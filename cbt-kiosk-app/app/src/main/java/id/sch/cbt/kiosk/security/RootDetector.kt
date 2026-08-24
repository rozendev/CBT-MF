package id.sch.cbt.kiosk.security

import android.content.Context
import android.util.Log
import com.scottyab.rootbeer.RootBeer
import java.io.File

object RootDetector {
    fun isRooted(context: Context): Boolean {
        return try {
            val rootBeer = RootBeer(context)
            rootBeer.isRooted
        } catch (e: Throwable) {
            Log.e("RootDetector", "RootBeer native check exception, using fallback check", e)
            checkSuBinaryFallback()
        }
    }

    private fun checkSuBinaryFallback(): Boolean {
        val paths = arrayOf(
            "/system/app/Superuser.apk",
            "/sbin/su",
            "/system/bin/su",
            "/system/xbin/su",
            "/data/local/xbin/su",
            "/data/local/bin/su",
            "/system/sd/xbin/su",
            "/system/bin/failsafe/su",
            "/data/local/su"
        )
        return try {
            paths.any { File(it).exists() }
        } catch (e: Throwable) {
            false
        }
    }
}
