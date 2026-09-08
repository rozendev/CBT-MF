package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import id.sch.cbt.kiosk.security.SecurityManager
import id.sch.cbt.kiosk.security.SirenAlarmManager
import org.json.JSONObject

class KioskManager(private val activity: Activity) {

    enum class State {
        INACTIVE,
        LOCK_REQUESTED,
        LOCKED,
        LOCK_REFUSED,
        FAILED,
    }

    @Volatile
    var state: State = State.INACTIVE
        private set

    /** Sesi pengamanan hidup, termasuk saat konfirmasi pin masih ditunggu. */
    val isSessionActive: Boolean
        get() = state != State.INACTIVE && state != State.FAILED

    /** `true` hanya bila Android mengonfirmasi lock-task masih terpasang. */
    val isKioskActive: Boolean
        get() = state == State.LOCKED && isInLockTask(activity) == true

    @Volatile
    var currentExamId: String = ""
        private set

    @Volatile
    var currentToken: String = ""
        private set

    private var securityManager: SecurityManager? = null
    private var heartbeatManager: HeartbeatManager? = null

    fun setSecurityManager(manager: SecurityManager) {
        this.securityManager = manager
    }

    fun setHeartbeatManager(manager: HeartbeatManager) {
        this.heartbeatManager = manager
    }

    /**
     * Memulai SESI kiosk. Nilai baliknya berarti "sesi dimulai", BUKAN
     * "perangkat terkunci" — dan perbedaan itu pernah berbahaya.
     *
     * Dulu fungsi ini selalu mengembalikan `true`: `startLockTask()` dipanggil
     * di dalam `runOnUiThread` (asinkron, exception-nya ditelan), `isKioskActive`
     * di-set tanpa syarat, dan `startLockTask()` memang tidak melempar apa pun
     * ketika siswa menekan "No thanks" — dialognya asinkron, jawabannya datang
     * beberapa detik kemudian. Akibatnya aplikasi menampilkan "Perangkat
     * terkunci" dan mengirim `kiosk_started` ke server untuk perangkat yang
     * sama sekali tidak terkunci. Cukup menolak sekali di awal, dan seluruh
     * ujian berjalan tanpa kunci dengan semua indikator hijau.
     *
     * Status terkunci sekarang hanya boleh dipercaya dari [onLockTaskConfirmed].
     */
    @Synchronized
    fun startKiosk(examId: String, token: String): Boolean {
        currentExamId = examId
        currentToken = token
        Log.d("KioskManager", "Starting kiosk for exam: $examId, state=$state")

        // Bundle memanggil startKiosk lagi ketika token ujian asli tersedia.
        // Pemanggilan kedua hanya memperbarui identitas/heartbeat; ia tidak boleh
        // membuat service kedua atau dialog pin paralel.
        if (isSessionActive) {
            heartbeatManager?.start(examId, token)
            if (state == State.LOCKED && isInLockTask(activity) == false) {
                requestLockTask()
            }
            return true
        }
        if (state == State.FAILED) {
            return false
        }

        return try {
            securityManager?.enableSecurityFlags()
            state = State.LOCK_REFUSED

            // Guard merupakan bagian dari mode aman, bukan side effect best-effort.
            // Kegagalan start sinkron berarti sesi tidak boleh dimulai.
            val intent = Intent(activity, KioskGuardService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                activity.startForegroundService(intent)
            } else {
                activity.startService(intent)
            }

            heartbeatManager?.start(examId, token)
            requestLockTask()
            true
        } catch (e: Throwable) {
            Log.e("KioskManager", "Failed to start secure kiosk session", e)
            state = State.FAILED
            securityManager?.disableSecurityFlags()
            heartbeatManager?.stop()
            try {
                activity.stopService(Intent(activity, KioskGuardService::class.java))
            } catch (_: Throwable) {
                // Start service sudah gagal; tidak ada tindakan lanjutan.
            }
            false
        }
    }

    @Synchronized
    fun stopKiosk(): Boolean {
        currentExamId = ""
        currentToken = ""
        Log.d("KioskManager", "Stopping kiosk")
        state = State.INACTIVE
        return try {
            // Stop Siren Alarm if active
            SirenAlarmManager.stopSiren()
            // Asuransi: stopService di bawah memang memicu onDestroy guard yang
            // juga menutup overlay, tapi penutup layar yang tertinggal menutupi
            // SELURUH perangkat tanpa ada lagi yang bisa mencabutnya. Kegagalan
            // semurah ini tidak pantas bergantung pada satu jalur saja.
            KioskOverlay.hide()
            // Verifikasi yang tertunda tidak boleh menyusul sesudah kiosk mati
            // dan memblokir layar setup yang sah.
            cancelVerify()
            // Keluar sah: jangan sampai transisi ke layar setup ikut memicu alarm.
            SirenAlarmManager.suppressFor(KIOSK_SETTLE_MS)

            securityManager?.disableSecurityFlags()
            activity.runOnUiThread {
                try {
                    activity.stopLockTask()
                } catch (e: Throwable) {
                    Log.e("KioskManager", "stopLockTask failed on UI thread", e)
                }
                (activity as? id.sch.cbt.kiosk.MainActivity)?.showSetupScreen()
            }
            heartbeatManager?.stop()
            
            // Stop Guard Service safely
            try {
                val intent = Intent(activity, KioskGuardService::class.java)
                activity.stopService(intent)
            } catch (e: Throwable) {
                Log.e("KioskManager", "Guard service stop error", e)
            }
            
            true
        } catch (e: Throwable) {
            Log.e("KioskManager", "Failed to stop LockTask", e)
            false
        }
    }
    
    fun getStatusJson(): String {
        val pinned = isInLockTask(activity)
        return JSONObject()
            .put("active", state == State.LOCKED && pinned == true)
            .put("sessionActive", isSessionActive)
            .put("state", state.name.lowercase())
            .put("pinned", pinned ?: JSONObject.NULL)
            .toString()
    }

    /**
     * Dipanggil ketika pin TERBUKTI terpasang. Hanya di sinilah aplikasi boleh
     * mengatakan perangkat terkunci.
     */
    var onLockTaskConfirmed: (() -> Unit)? = null

    /**
     * Dipanggil ketika pin tidak terpasang setelah permintaan.
     * [isFinal] = percobaan ulang sudah habis; pemanggil harus menghentikan ujian.
     */
    var onLockTaskRefused: ((isFinal: Boolean) -> Unit)? = null

    private val verifyHandler = Handler(Looper.getMainLooper())
    private var pinAttempt = 0
    private var verifyGeneration = 0

    /** Minta lock task, lalu VERIFIKASI hasilnya melalui satu state machine. */
    @Synchronized
    fun requestLockTask(): Boolean {
        if (!isSessionActive || state == State.LOCK_REQUESTED) {
            return false
        }

        if (isInLockTask(activity) == true) {
            confirmLockTask()
            return true
        }

        pinAttempt++
        state = State.LOCK_REQUESTED
        val generation = ++verifyGeneration

        // Selama dialog sistem belum selesai, guard tidak boleh menghitung pause
        // sebagai pelolosan. Deadline guard dan verifier kini berasal dari state
        // yang sama, bukan dua timer yang saling bertentangan.
        SirenAlarmManager.suppressFor(PIN_DEADLINE_MS + KIOSK_SETTLE_MS)
        KioskGuardService.setLockRequestPending(true)
        // Pada pemulihan, overlay harus dilepas sebelum dialog sistem menerima
        // sentuhan. Keduanya diantrikan di main looper agar urutannya deterministik.
        KioskOverlay.hide()
        verifyHandler.post {
            try {
                activity.startLockTask()
            } catch (e: Throwable) {
                Log.e("KioskManager", "startLockTask gagal", e)
            }
        }
        scheduleVerify(System.currentTimeMillis(), generation)
        return true
    }

    /**
     * Menunggu jawaban siswa atas dialog sistem, lalu memutuskan.
     *
     * Menolak dideteksi lebih cepat daripada sekadar menunggu tenggat: selama
     * dialog sistem tampil activity kita ter-pause, jadi begitu activity
     * terlihat LAGI sementara pin tetap belum terpasang, dialognya pasti sudah
     * dijawab — dan jawabannya bukan "ya". Tenggat panjang tetap ada sebagai
     * jaring pengaman untuk siswa yang lama memutuskan.
     */
    private fun scheduleVerify(startedAt: Long, generation: Int) {
        verifyHandler.postDelayed({
            if (!isSessionActive || state != State.LOCK_REQUESTED || generation != verifyGeneration) {
                return@postDelayed
            }

            if (isInLockTask(activity) == true) {
                confirmLockTask()
                return@postDelayed
            }

            val elapsed = System.currentTimeMillis() - startedAt
            val dialogSudahDijawab = elapsed >= PIN_MIN_WAIT_MS &&
                KioskGuardService.isMainActivityVisible
            if (dialogSudahDijawab || elapsed >= PIN_DEADLINE_MS) {
                KioskGuardService.setLockRequestPending(false)
                val isFinal = pinAttempt >= PIN_MAX_ATTEMPTS
                state = if (isFinal) State.FAILED else State.LOCK_REFUSED
                Log.e(
                    "KioskManager",
                    "Lock task TIDAK terpasang (percobaan $pinAttempt, final=$isFinal)"
                )
                onLockTaskRefused?.invoke(isFinal)
                return@postDelayed
            }

            scheduleVerify(startedAt, generation)
        }, PIN_POLL_MS)
    }

    @Synchronized
    private fun confirmLockTask() {
        if (!isSessionActive) return
        state = State.LOCKED
        pinAttempt = 0
        verifyGeneration++
        verifyHandler.removeCallbacksAndMessages(null)
        KioskGuardService.setLockRequestPending(false)
        SirenAlarmManager.stopSiren()
        Log.w("KioskManager", "Lock task TERKONFIRMASI")
        onLockTaskConfirmed?.invoke()
    }

    @Synchronized
    private fun cancelVerify() {
        verifyGeneration++
        verifyHandler.removeCallbacksAndMessages(null)
        pinAttempt = 0
        KioskGuardService.setLockRequestPending(false)
    }

    /**
     * Pasang ULANG lock task bila kiosk seharusnya aktif tapi pin sudah lepas.
     *
     * Ini menutup celah yang tersisa setelah penutup layar dipasang: overlay
     * berhasil menyeret siswa kembali ke layar ujian, tapi mengembalikannya ke
     * perangkat yang TIDAK lagi terkunci — notification shade hidup, Recents
     * hidup, dan lewat Recents aplikasinya bisa di-swipe mati. Dulu
     * `startLockTask()` hanya dipanggil sekali seumur sesi, jadi sekali pin
     * lepas ia tidak pernah kembali.
     *
     * Dipanggil dari `onResume` MainActivity: `startLockTask()` menuntut
     * activity yang sedang di depan, dan itulah momennya.
     */
    fun ensureLockTask() {
        if (!isSessionActive || state == State.LOCK_REQUESTED) return
        // Hanya bila pin DIPASTIKAN lepas. Saat status tidak terbaca, diam:
        // memasang ulang secara spekulatif berisiko memunculkan dialog
        // konfirmasi sistem berulang-ulang di tengah ujian.
        if (isInLockTask(activity) != false) return

        Log.w("KioskManager", "Pin lepas padahal sesi kiosk aktif — meminta ulang")
        requestLockTask()
    }

    companion object {
        /** Jeda tenang saat masuk/keluar lock task. */
        private const val KIOSK_SETTLE_MS = 4000L

        /** Selang polling saat menunggu jawaban dialog "Sematkan layar?". */
        private const val PIN_POLL_MS = 1000L

        /** Sebelum ini, activity terlihat belum tentu berarti dialog sudah dijawab. */
        private const val PIN_MIN_WAIT_MS = 2500L

        /** Jaring pengaman untuk siswa yang lama memutuskan. */
        private const val PIN_DEADLINE_MS = 20_000L

        /** Permintaan awal + satu percobaan ulang. */
        private const val PIN_MAX_ATTEMPTS = 2

        /**
         * Status lock task perangkat. `null` = tidak terbaca.
         *
         * Sengaja tri-state supaya setiap pemanggil menyatakan arah amannya
         * sendiri: guard service menganggap "tidak terbaca" = masih ter-pin
         * (jangan membunyikan alarm palsu), sedangkan [ensureLockTask]
         * menganggapnya = jangan bertindak.
         */
        fun isInLockTask(context: Context): Boolean? {
            return try {
                val am = context.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager
                    ?: return null
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    am.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE
                } else {
                    @Suppress("DEPRECATION")
                    am.isInLockTaskMode
                }
            } catch (e: Throwable) {
                Log.e("KioskManager", "Tidak dapat membaca status lock task", e)
                null
            }
        }
    }
}
