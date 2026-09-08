package id.sch.cbt.kiosk.kiosk

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.util.Log
import androidx.core.app.NotificationCompat
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.security.SirenAlarmManager

class KioskGuardService : Service() {

    private val handler = Handler(Looper.getMainLooper())
    private val checkInterval = 1000L // 1 detik

    companion object {
        @Volatile
        var isMainActivityVisible = false

        /**
         * Berapa kali berturut-turut activity boleh tidak terlihat sebelum
         * dianggap siswa BENAR-BENAR lolos. Transisi wajar (dialog sistem,
         * rotasi, animasi masuk kiosk) selesai jauh di bawah ambang ini, jadi
         * sirene tidak lagi meraung untuk hal yang bukan pelanggaran.
         */
        private const val ESCAPE_TICKS = 3

        /** Jeda tenang sesudah service menyala, menunggu lock task mapan. */
        private const val STARTUP_GRACE_MS = 3000L

        /** Jangan banjiri logcat: catat sekali tiap sekian detik saja. */
        private const val UNPINNED_LOG_EVERY = 10

        /**
         * Telemetri lapangan. Dipertahankan setelah investigasi selesai karena
         * `overlay=` di baris ini adalah cara tercepat mengetahui bahwa sebuah
         * perangkat kehilangan izin overlay — satu-satunya hal yang membuat
         * penutup layar bisa ditampilkan.
         */
        private const val DIAG = "KioskBAL"

        /**
         * Jendela tenang bersama untuk penutup layar DAN sirene.
         *
         * Dipasang KioskManager saat memasang (ulang) lock task: transisi itu
         * sesaat membuat pin terbaca lepas dan activity ter-pause, yang tanpa
         * peredam ini terlihat persis seperti pelolosan.
         */
        @Volatile
        private var settleUntilMs = 0L

        @Volatile
        private var lockRequestPending = false

        fun settleFor(durationMs: Long) {
            settleUntilMs = System.currentTimeMillis() + durationMs
        }

        /**
         * Sumber kebenaran bersama selama dialog pin milik sistem aktif.
         * Guard tidak boleh memakai timer pendek sendiri dan memutuskan bahwa
         * activity yang ter-pause oleh dialog tersebut adalah pelolosan.
         */
        fun setLockRequestPending(pending: Boolean) {
            lockRequestPending = pending
            if (!pending) {
                settleFor(1000L)
            }
        }

        private fun isLockRequestPending(): Boolean = lockRequestPending

        private fun isSettling(): Boolean = System.currentTimeMillis() < settleUntilMs
    }

    private var startedAtMs = 0L
    private var missedTicks = 0

    /** Berapa detik berturut-turut kiosk aktif tanpa pin, siswa tetap di layar ujian. */
    private var unpinnedTicks = 0

    /**
     * Layar masih ter-pin? Kalau lock task sudah mati padahal kiosk seharusnya
     * aktif, itu bukan sekadar "coba keluar" -- siswa sudah benar-benar keluar.
     */
    private fun isStillPinned(): Boolean {
        // Satu sumber kebenaran di KioskManager; logika yang sama pernah ada di
        // dua tempat. Arah aman DI SINI: status tak terbaca dianggap masih
        // ter-pin, supaya alarm tidak berbunyi hanya karena query gagal.
        return KioskManager.isInLockTask(this) ?: true
    }

    private val monitorRunnable = object : Runnable {
        override fun run() {
            try {
                val settled = System.currentTimeMillis() - startedAtMs >= STARTUP_GRACE_MS

                val lockPending = isLockRequestPending()

                if (isMainActivityVisible) {
                    missedTicks = 0
                    val pinned = isStillPinned()
                    // Penutup baru boleh dilepas ketika perangkat sudah aman atau
                    // dialog pin perlu menerima input. Sekadar Activity terlihat
                    // tidak lagi dianggap bukti bahwa pemulihan berhasil.
                    if ((pinned || lockPending) && KioskOverlay.isShowing) {
                        KioskOverlay.hide()
                    }

                    // Cabang ini DULU tidak memeriksa apa pun, dan itulah yang
                    // membuat penolakan "No thanks" tak terlihat: siswa yang
                    // menolak pin tetap duduk di layar ujian dengan activity
                    // terlihat, jadi eksekusi selalu berhenti di sini dan status
                    // pin tidak pernah dibaca sama sekali.
                    //
                    // Sengaja hanya MELAPOR, tidak bertindak: reaksi resmi ada
                    // di mesin verifikasi KioskManager (coba ulang lalu blokir).
                    // Dua pihak yang sama-sama mencoba memperbaiki keadaan yang
                    // sama akan saling menimpa dialog.
                    if (settled && !lockPending && !isSettling() && !pinned) {
                        unpinnedTicks++
                        if (unpinnedTicks % UNPINNED_LOG_EVERY == 1) {
                            Log.e(
                                DIAG,
                                "KIOSK AKTIF TAPI TIDAK TER-PIN selama ${unpinnedTicks}s " +
                                    "sementara siswa berada di layar ujian"
                            )
                        }
                    } else {
                        unpinnedTicks = 0
                    }
                } else if (settled && !lockPending) {
                    missedTicks++

                    // Baris log di bawah dipertahankan sebagai telemetri lapangan.
                    // `startActivity()` yang ditolak sistem TIDAK melempar
                    // exception — ia dibuang diam-diam — jadi tanpa penanda waktu
                    // ini tidak ada cara mencocokkan perilaku app dengan log
                    // ActivityTaskManager. `overlay=` juga satu-satunya cara cepat
                    // melihat perangkat yang izin overlay-nya dicabut siswa.
                    val pinned = isStillPinned()
                    // Pelolosan sungguhan: pin sudah lepas, atau layar ujian
                    // hilang terus-menerus melewati ambang.
                    val escaped = !pinned || missedTicks >= ESCAPE_TICKS
                    Log.w(
                        DIAG,
                        "tick#$missedTicks pinned=$pinned escaped=$escaped " +
                            "settling=${isSettling()} " +
                            "overlay=${KioskOverlay.isGranted(this@KioskGuardService)}"
                    )

                    if (escaped && !isSettling()) {
                        // URUTAN PENTING: penutup layar lebih dulu, baru sirene.
                        // Penutup digambar langsung oleh WindowManager sehingga
                        // tidak bisa dibatalkan sistem, DAN dengan tampilnya ia
                        // aplikasi jadi punya jendela terlihat — itulah yang
                        // membuat startActivity() di bawah berhenti kena
                        // BAL_BLOCK.
                        //
                        // Ambangnya sengaja SAMA dengan sirene. Ambang itu sudah
                        // disetel untuk membedakan pelolosan dari transisi wajar
                        // (dialog sistem, animasi lock task); memberi penutup
                        // ambang sendiri yang lebih longgar akan membuatnya
                        // berkedip setiap kali dialog muncul di tengah ujian.
                        KioskOverlay.show(this@KioskGuardService)
                        SirenAlarmManager.startSiren(this@KioskGuardService)
                    }

                    // Dicoba setiap tick, bukan hanya saat lolos: pada transisi
                    // wajar panggilan ini memang berhasil (jendela masih
                    // terlihat), dan sesudah penutup tampil ia berhasil juga —
                    // siswa kembali ke ujian tanpa perlu menekan apa pun.
                    val intent = Intent(this@KioskGuardService, MainActivity::class.java).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                    }
                    startActivity(intent)
                }
            } catch (e: Throwable) {
                Log.e("KioskGuardService", "Error in monitor runnable", e)
            }
            handler.postDelayed(this, checkInterval)
        }
    }

    override fun onCreate() {
        super.onCreate()
        try {
            createNotificationChannel()
            val notification = NotificationCompat.Builder(this, "KIOSK_GUARD_CHANNEL")
                .setContentTitle("Sesi Ujian Aktif")
                .setContentText("Kiosk mode sedang memantau keamanan ujian.")
                .setSmallIcon(android.R.drawable.ic_secure)
                .setPriority(NotificationCompat.PRIORITY_LOW)
                .build()

            // FOREGROUND_SERVICE_TYPE_SPECIAL_USE requires API 34+
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(1, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
            } else {
                startForeground(1, notification)
            }
        } catch (e: Throwable) {
            Log.e("KioskGuardService", "Error starting foreground service", e)
            stopSelf()
            return
        }

        startedAtMs = System.currentTimeMillis()
        missedTicks = 0
        handler.post(monitorRunnable)
    }

    override fun onDestroy() {
        handler.removeCallbacks(monitorRunnable)
        SirenAlarmManager.stopSiren()
        // Penutup layar TIDAK boleh hidup lebih lama dari guard yang memasangnya:
        // jendela overlay yang tertinggal akan menutupi seluruh perangkat tanpa
        // ada lagi yang bisa mencabutnya.
        KioskOverlay.hide()
        setLockRequestPending(false)
        super.onDestroy()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        return START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            try {
                val channel = NotificationChannel(
                    "KIOSK_GUARD_CHANNEL",
                    "Kiosk Guard Service",
                    NotificationManager.IMPORTANCE_LOW
                )
                val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
                manager.createNotificationChannel(channel)
            } catch (e: Throwable) {
                Log.e("KioskGuardService", "Error creating notification channel", e)
            }
        }
    }
}
