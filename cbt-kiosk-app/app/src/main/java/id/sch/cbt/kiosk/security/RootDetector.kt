package id.sch.cbt.kiosk.security

import android.content.Context
import com.scottyab.rootbeer.RootBeer

object RootDetector {
    fun isRooted(context: Context): Boolean {
        val rootBeer = RootBeer(context)
        return rootBeer.isRooted
    }
}
