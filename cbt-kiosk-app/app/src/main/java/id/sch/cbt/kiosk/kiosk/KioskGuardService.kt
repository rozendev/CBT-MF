package id.sch.cbt.kiosk.kiosk

import android.app.ActivityManager
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
    }

    private var startedAtMs = 0L
    private var missedTicks = 0

    /**
     * Layar masih ter-pin? Kalau lock task sudah mati padahal kiosk seharusnya
     * aktif, itu bukan sekadar "coba keluar" -- siswa sudah benar-benar keluar.
     */
    private fun isStillPinned(): Boolean {
        return try {
            val am = getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager ?: return true
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                am.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE
            } else {
                @Suppress("DEPRECATION")
                am.isInLockTaskMode
            }
        } catch (e: Throwable) {
            Log.e("KioskGuardService", "Cannot read lock task state", e)
            true
        }
    }

    private val monitorRunnable = object : Runnable {
        override fun run() {
            try {
                val settled = System.currentTimeMillis() - startedAtMs >= STARTUP_GRACE_MS

                if (isMainActivityVisible) {
                    missedTicks = 0
                } else if (settled) {
                    missedTicks++

                    // Selalu tarik kembali ke depan, berbunyi atau tidak.
                    val intent = Intent(this@KioskGuardService, MainActivity::class.java).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                    }
                    startActivity(intent)

                    // Sirene hanya untuk pelolosan sungguhan: pin sudah lepas,
                    // atau layar ujian hilang terus-menerus melewati ambang.
                    if (!isStillPinned() || missedTicks >= ESCAPE_TICKS) {
                        SirenAlarmManager.startSiren(this@KioskGuardService)
                    }
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
        }

        startedAtMs = System.currentTimeMillis()
        missedTicks = 0
        handler.post(monitorRunnable)
    }

    override fun onDestroy() {
        handler.removeCallbacks(monitorRunnable)
        SirenAlarmManager.stopSiren()
        super.onDestroy()
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
