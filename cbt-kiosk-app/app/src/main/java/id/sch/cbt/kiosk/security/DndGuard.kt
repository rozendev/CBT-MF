package id.sch.cbt.kiosk.security

import android.app.NotificationManager
import android.content.Context
import android.content.SharedPreferences
import android.provider.Settings
import android.util.Log

/**
 * Menyenyapkan perangkat selama ujian lewat Do Not Disturb.
 *
 * Kiosk ini memakai screen pinning biasa (`startLockTask`), BUKAN device owner,
 * jadi tidak ada `LOCK_TASK_FEATURE_NOTIFICATIONS` yang bisa dimatikan. Satu-
 * satunya jalur yang tersisa adalah `setInterruptionFilter`, dan itu menuntut
 * Notification Policy Access — izin khusus yang hanya bisa diberikan manusia
 * lewat layar Pengaturan.
 *
 * Filter yang dipasang adalah [FILTER_ALARMS], bukan `INTERRUPTION_FILTER_NONE`.
 * Perbedaannya menentukan: NONE ikut membungkam STREAM_ALARM, dan di situlah
 * [SirenAlarmManager] membunyikan sirene anti-curang. Senyap total berarti
 * ujian berjalan tanpa alarm sama sekali — menukar satu perlindungan dengan
 * perlindungan lain.
 *
 * Keputusan murninya sengaja dipisah dari panggilan Android:
 * `NotificationManager` tidak ada di unit test JVM, sementara justru logika
 * inilah yang perlu diuji.
 */
object DndGuard {

    private const val TAG = "DndGuard"

    /** Filter perangkat disimpan di sini SEBELUM diubah, agar bisa dikembalikan. */
    const val PREF_SAVED_FILTER = "kiosk_dnd_saved_filter"

    /** Tidak ada filter tersimpan / filter tidak terbaca. */
    const val NO_SAVED_FILTER = NotificationManager.INTERRUPTION_FILTER_UNKNOWN

    const val FILTER_ALL = NotificationManager.INTERRUPTION_FILTER_ALL
    const val FILTER_PRIORITY = NotificationManager.INTERRUPTION_FILTER_PRIORITY
    const val FILTER_ALARMS = NotificationManager.INTERRUPTION_FILTER_ALARMS

    /** Dialog izin hanya relevan bila kebijakan menuntutnya dan izinnya belum ada. */
    fun needsPermissionPrompt(policyEnabled: Boolean, waived: Boolean, granted: Boolean): Boolean =
        policyEnabled && !waived && !granted

    /**
     * Waiver sengaja TIDAK ikut dipertimbangkan: waiver hanya berarti "jangan
     * hadang ujian karena izinnya belum ada". Bila izinnya ternyata ada,
     * memasang filter tetap benar dan gratis.
     */
    fun shouldApply(policyEnabled: Boolean, granted: Boolean): Boolean =
        policyEnabled && granted

    /**
     * Filter asli hanya boleh direkam SEKALI per sesi.
     *
     * `onResume` menegaskan ulang DND di tengah ujian. Bila penegasan itu ikut
     * menyimpan, yang tersimpan adalah ALARMS — filter yang kita pasang sendiri —
     * dan perangkat tidak akan pernah kembali ke keadaan semula.
     *
     * Filter yang tidak terbaca jatuh ke [FILTER_ALL], bukan ke "tidak ada yang
     * disimpan". Bila `currentInterruptionFilter` melempar sementara
     * `setInterruptionFilter` berhasil, tanpa jatuhan ini perangkat tertinggal
     * senyap tanpa catatan cara pulang. FILTER_ALL adalah keadaan bawaan
     * Android, jadi paling buruk siswa kehilangan DND yang ia pasang sendiri —
     * jauh lebih murah daripada HP yang senyap selamanya.
     */
    fun filterToSave(existingSaved: Int, currentFilter: Int): Int = when {
        existingSaved != NO_SAVED_FILTER -> existingSaved
        currentFilter == NO_SAVED_FILTER -> FILTER_ALL
        else -> currentFilter
    }

    /** `null` bila memang tidak ada yang tertinggal untuk dipulihkan. */
    fun staleFilterToRestore(savedFilter: Int): Int? =
        if (savedFilter == NO_SAVED_FILTER) null else savedFilter

    /**
     * Status untuk heartbeat pengawas.
     *
     * `waived` sengaja menggabungkan "dilewati saat setup" dan "izin dicabut di
     * tengah ujian": bagi pengawas keduanya berarti hal yang sama persis —
     * kebijakan menuntut perangkat ini senyap, dan perangkat ini tidak senyap.
     */
    fun statusFor(policyEnabled: Boolean, currentFilter: Int): String = when {
        !policyEnabled -> "off"
        currentFilter == FILTER_ALARMS -> "on"
        else -> "waived"
    }

    // --- Lapisan Android: tidak dapat diuji di JVM, jadi dijaga setipis mungkin ---

    fun isGranted(context: Context): Boolean = try {
        notificationManager(context)?.isNotificationPolicyAccessGranted == true
    } catch (e: Throwable) {
        Log.w(TAG, "Tidak dapat membaca status Notification Policy Access", e)
        false
    }

    /** [NO_SAVED_FILTER] bila tidak terbaca. */
    fun currentFilter(context: Context): Int = try {
        notificationManager(context)?.currentInterruptionFilter ?: NO_SAVED_FILTER
    } catch (e: Throwable) {
        Log.w(TAG, "Tidak dapat membaca interruption filter", e)
        NO_SAVED_FILTER
    }

    /**
     * Pasang DND. Filter asli disimpan ke [prefs] LEBIH DULU — bukan sesudah —
     * karena aplikasi yang dibunuh di antara dua langkah itu akan meninggalkan
     * perangkat senyap tanpa ada catatan cara mengembalikannya.
     */
    fun apply(context: Context, prefs: SharedPreferences, policyEnabled: Boolean): Boolean {
        if (!shouldApply(policyEnabled, isGranted(context))) return false

        val current = currentFilter(context)
        if (current != FILTER_ALARMS) {
            prefs.edit()
                .putInt(PREF_SAVED_FILTER, filterToSave(prefs.getInt(PREF_SAVED_FILTER, NO_SAVED_FILTER), current))
                .commit()
        }

        return setFilter(context, FILTER_ALARMS)
    }

    /** Kembalikan perangkat ke filter aslinya dan lupakan catatannya. */
    fun restore(context: Context, prefs: SharedPreferences) {
        val saved = staleFilterToRestore(prefs.getInt(PREF_SAVED_FILTER, NO_SAVED_FILTER)) ?: return
        setFilter(context, saved)
        prefs.edit().remove(PREF_SAVED_FILTER).apply()
    }

    private fun setFilter(context: Context, filter: Int): Boolean = try {
        notificationManager(context)?.setInterruptionFilter(filter)
        Log.w(TAG, "Interruption filter di-set ke $filter")
        true
    } catch (e: Throwable) {
        // Izin dicabut di tengah jalan, atau ROM menolak. Jangan jatuhkan ujian
        // karenanya — heartbeat yang melaporkan perangkat ini tidak senyap.
        Log.e(TAG, "Gagal men-set interruption filter ke $filter", e)
        false
    }

    /**
     * ROM bervariasi dalam menyediakan layar ini, jadi pemanggil mencobanya
     * berjenjang sampai ada yang mau terbuka.
     */
    fun settingsIntents(): List<String> = listOf(
        Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS,
        Settings.ACTION_SOUND_SETTINGS,
        Settings.ACTION_SETTINGS
    )

    private fun notificationManager(context: Context): NotificationManager? =
        context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager
}
