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

class KioskGuardService : Service() {

    private val handler = Handler(Looper.getMainLooper())
    private val checkInterval = 1000L // 1 detik

    companion object {
        @Volatile
        var isMainActivityVisible = false
    }

    private val monitorRunnable = object : Runnable {
        override fun run() {
            try {
                if (!isMainActivityVisible) {
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
        }

        handler.post(monitorRunnable)
    }

    override fun onDestroy() {
        handler.removeCallbacks(monitorRunnable)
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
