package id.sch.cbt.kiosk.kiosk

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.Settings
import android.util.Log

/**
 * Melepas kiosk TIDAK mengembalikan perangkat ke keadaan semula: aplikasi masih
 * terdaftar CATEGORY_HOME (AndroidManifest.xml:29), jadi tombol Home tetap
 * membawa siswa ke sini. Objek ini yang mendeteksi keadaan itu dan menuntun
 * penggunanya keluar.
 */
object HomeLauncherGuard {

    private const val TAG = "HomeLauncherGuard"

    /**
     * Sengaja dipisah sebagai fungsi murni: PackageManager tidak tersedia di
     * unit test JVM, sementara justru perbandingan inilah yang perlu diuji —
     * termasuk kasus resolved == null dan resolver bawaan sistem.
     */
    fun isSelfTheHomeApp(resolvedPackage: String?, ownPackage: String): Boolean =
        !resolvedPackage.isNullOrBlank() && resolvedPackage.trim() == ownPackage

    fun resolveHomePackage(pm: PackageManager): String? = try {
        val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        pm.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY)?.activityInfo?.packageName
    } catch (e: Throwable) {
        Log.w(TAG, "Tidak bisa membaca Home app terpilih", e)
        null
    }

    fun isHoldingHomeRole(context: Context): Boolean =
        isSelfTheHomeApp(resolveHomePackage(context.packageManager), context.packageName)

    /**
     * ROM Android sangat beragam dalam menyediakan layar pemilihan Home app,
     * jadi intent dicoba berjenjang. Mengembalikan false bila tidak satu pun
     * bisa dibuka, supaya pemanggil bisa menampilkan instruksi manual alih-alih
     * gagal senyap.
     */
    fun openHomeSettings(context: Context): Boolean {
        val candidates = listOf(
            Settings.ACTION_HOME_SETTINGS,
            Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS,
            Settings.ACTION_SETTINGS
        )
        for (action in candidates) {
            try {
                context.startActivity(Intent(action).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                return true
            } catch (e: Throwable) {
                Log.w(TAG, "Intent $action tidak tersedia di ROM ini", e)
            }
        }
        return false
    }
}
