package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.content.Intent
import android.os.Build
import android.util.Log
import id.sch.cbt.kiosk.security.SecurityManager
import id.sch.cbt.kiosk.security.SirenAlarmManager

class KioskManager(private val activity: Activity) {
    
    var isKioskActive = false
        private set

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

    fun startKiosk(examId: String, token: String): Boolean {
        currentExamId = examId
        currentToken = token
        Log.d("KioskManager", "Starting kiosk for exam: $examId")
        return try {
            securityManager?.enableSecurityFlags()
            activity.runOnUiThread {
                try {
                    activity.startLockTask()
                } catch (e: Throwable) {
                    Log.e("KioskManager", "startLockTask failed on UI thread", e)
                }
            }
            isKioskActive = true
            heartbeatManager?.start(examId, token)
            
            // Start Guard Service safely
            try {
                val intent = Intent(activity, KioskGuardService::class.java)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    activity.startForegroundService(intent)
                } else {
                    activity.startService(intent)
                }
            } catch (e: Throwable) {
                Log.e("KioskManager", "Guard service start error", e)
            }
            
            true
        } catch (e: Throwable) {
            Log.e("KioskManager", "Failed to start LockTask", e)
            false
        }
    }

    fun stopKiosk(): Boolean {
        currentExamId = ""
        currentToken = ""
        Log.d("KioskManager", "Stopping kiosk")
        return try {
            // Stop Siren Alarm if active
            SirenAlarmManager.stopSiren()

            securityManager?.disableSecurityFlags()
            activity.runOnUiThread {
                try {
                    activity.stopLockTask()
                } catch (e: Throwable) {
                    Log.e("KioskManager", "stopLockTask failed on UI thread", e)
                }
                (activity as? id.sch.cbt.kiosk.MainActivity)?.showSetupScreen()
            }
            isKioskActive = false
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
        return "{\"active\": $isKioskActive}"
    }
}
