package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.content.Intent
import android.os.Build
import android.util.Log
import id.sch.cbt.kiosk.security.SecurityManager

class KioskManager(private val activity: Activity) {
    
    var isKioskActive = false
        private set

    private var securityManager: SecurityManager? = null

    fun setSecurityManager(manager: SecurityManager) {
        this.securityManager = manager
    }

    fun startKiosk(examId: String, token: String): Boolean {
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
        Log.d("KioskManager", "Stopping kiosk")
        return try {
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
