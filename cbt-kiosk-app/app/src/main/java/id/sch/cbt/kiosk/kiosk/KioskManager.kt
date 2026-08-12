package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.util.Log

class KioskManager(private val activity: Activity) {
    
    var isKioskActive = false
        private set

    fun startKiosk(examId: String, token: String): Boolean {
        Log.d("KioskManager", "Starting kiosk for exam: $examId")
        return try {
            activity.startLockTask()
            isKioskActive = true
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to start LockTask", e)
            false
        }
    }

    fun stopKiosk(): Boolean {
        Log.d("KioskManager", "Stopping kiosk")
        return try {
            activity.stopLockTask()
            isKioskActive = false
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to stop LockTask", e)
            false
        }
    }
    
    fun getStatusJson(): String {
        return "{\"active\": $isKioskActive}"
    }
}
