package id.sch.cbt.kiosk

import android.content.Context
import android.provider.Settings
import android.util.Log

/**
 * Satu-satunya tempat yang tahu cara mendapatkan penanda perangkat.
 *
 * Dulu logikanya ada di MainActivity sementara HeartbeatManager membaca kunci
 * SharedPreferences-nya langsung. Dua tempat yang sama-sama tahu soal kunci itu
 * langsung menyimpang: penanda skema baru ditulis ke satu kunci, heartbeat
 * membaca kunci lain, dan akibatnya /api/kiosk/config serta heartbeat
 * memeriksa identitas yang BERBEDA. Monitoring menampilkan yang salah, dan
 * pengawas memblokir penanda yang tidak pernah dipakai aplikasi untuk
 * memperkenalkan diri.
 *
 * Sekarang semua pemanggil lewat sini, jadi tidak ada lagi yang bisa
 * menyimpang. Derivasi murninya tetap di [DeviceIdentity] supaya bisa diuji
 * tanpa framework Android.
 */
object DeviceIdentityStore {

    private const val PREFS = "cbt_kiosk_prefs"

    /** Penanda skema sekarang. Kuncinya terpisah supaya migrasi dari UUID terdeteksi. */
    private const val KEY_CURRENT = "kiosk_device_id_v2"

    /**
     * Kunci skema lama. Tetap ditulis dengan nilai yang sama karena masih ada
     * pembaca lain di aplikasi ini, dan supaya perangkat yang turun versi tidak
     * mendadak kehilangan identitasnya.
     */
    private const val KEY_LEGACY = "kiosk_device_id"

    fun resolve(context: Context): String {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

        val cached = prefs.getString(KEY_CURRENT, "")
        if (!cached.isNullOrBlank()) {
            // Isi ulang kunci lama bila belum sama: perangkat yang sempat
            // menjalankan versi yang hanya menulis KEY_CURRENT punya kunci lama
            // kosong, dan tanpa ini heartbeat-nya mengirim kosong selamanya.
            if (prefs.getString(KEY_LEGACY, "") != cached) {
                prefs.edit().putString(KEY_LEGACY, cached).apply()
            }
            return cached
        }

        val androidId = try {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        } catch (e: Throwable) {
            Log.w("DeviceIdentityStore", "Gagal membaca ANDROID_ID", e)
            null
        }

        val id = if (DeviceIdentity.isUsableAndroidId(androidId)) {
            DeviceIdentity.derive(androidId!!)
        } else {
            // Jalur cadangan: blokir tetap berfungsi untuk perangkat ini, hanya
            // kembali bisa dilepas dengan menghapus data aplikasi.
            //
            // Bentuk keluarannya ikut terikat aturan yang sama dengan
            // DeviceIdentity.derive(): server menyaring device_id dengan
            // [A-Za-z0-9_-] sepanjang maksimal 64 (DeviceBan::isValidDeviceId).
            // UUID.toString() lolos karena memakai tanda hubung, 36 karakter —
            // tapi itu kebetulan yang menguntungkan, bukan jaminan. Format lain
            // di sini akan ditolak diam-diam: device_id dikosongkan, lalu setiap
            // sambung ulang di perangkat yang sama ditolak selamanya.
            Log.w("DeviceIdentityStore", "ANDROID_ID tidak dapat dipakai, memakai UUID per-pemasangan")
            val legacy = prefs.getString(KEY_LEGACY, "")
            if (!legacy.isNullOrBlank()) legacy else java.util.UUID.randomUUID().toString()
        }

        prefs.edit()
            .putString(KEY_CURRENT, id)
            .putString(KEY_LEGACY, id)
            .apply()

        return id
    }
}
