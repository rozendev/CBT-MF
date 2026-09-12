package id.sch.cbt.kiosk.security

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

/**
 * Verifikasi kode keluar offline di perangkat.
 *
 * Perangkat TIDAK pernah memegang kiosk_exit_password: server mengirim amplop
 * berisi hash PBKDF2 dari kode beberapa hari ke depan, dan di sini masukan
 * pengawas dicocokkan dengan hash itu. HP yang dibongkar karenanya membocorkan
 * paling jauh sepekan kode, bukan password pengawas.
 */
object OfflineExitCode {

    private const val TAG = "OfflineExitCode"

    /**
     * Dipaku, bukan mengikuti zona perangkat: kalau ikut zona perangkat, siswa
     * cukup mengubah setelan zona untuk menggeser kode yang berlaku.
     */
    private const val TIMEZONE = "Asia/Jakarta"

    private const val ONE_DAY_MS = 86_400_000L

    const val PREFS = "cbt_kiosk_prefs"
    const val KEY_ENVELOPE = "kiosk_offline_envelope"
    const val KEY_ANCHOR = "kiosk_server_day_anchor"
    const val KEY_FAILS = "offline_exit_fails"
    const val KEY_LOCK_UNTIL = "offline_exit_lock_until"

    /** Mencerminkan MAX_EXIT_FAILS / EXIT_LOCKOUT_SECONDS di KioskController.php. */
    const val MAX_FAILS = 5
    const val LOCKOUT_MS = 600_000L

    data class Entry(val day: String, val salt: String, val hash: String)

    data class Envelope(val enabled: Boolean, val iterations: Int, val entries: List<Entry>)

    data class FailureState(val fails: Int, val lockUntil: Long)

    // ---------- Bagian murni: diuji tanpa perangkat ----------

    fun dayOf(epochMillis: Long): String {
        val fmt = SimpleDateFormat("yyyy-MM-dd", Locale.US)
        fmt.timeZone = TimeZone.getTimeZone(TIMEZONE)
        return fmt.format(Date(epochMillis))
    }

    /**
     * H-1, H, H+1 menurut jam perangkat, dibuang yang lebih tua dari jangkar
     * waktu server. Jendela ±1 hari menoleransi jam yang melenceng; jangkar
     * menutup trik memundurkan jam ke tanggal yang kodenya terlanjur bocor.
     *
     * Format YYYY-MM-DD membuat urutan leksikografis sama dengan urutan
     * kronologis, jadi perbandingan string sudah cukup.
     */
    fun candidateDays(epochMillis: Long, anchorDay: String?): List<String> {
        val days = listOf(
            dayOf(epochMillis - ONE_DAY_MS),
            dayOf(epochMillis),
            dayOf(epochMillis + ONE_DAY_MS)
        )
        if (anchorDay.isNullOrBlank()) return days
        return days.filter { it >= anchorDay }
    }

    /** Jangkar hanya boleh maju; kalau tidak, ia bisa dimundurkan bersama jam. */
    fun advanceAnchor(current: String?, incoming: String?): String? {
        if (incoming.isNullOrBlank()) return current
        if (current.isNullOrBlank()) return incoming
        return if (incoming > current) incoming else current
    }

    /**
     * Salt dipakai sebagai byte UTF-8 dari string hex apa adanya — sama seperti
     * PHP yang meneruskan string itu langsung ke hash_pbkdf2. Men-decode hex di
     * salah satu sisi membuat kedua implementasi tidak akan pernah sepakat.
     */
    fun pbkdf2Hex(code: String, salt: String, iterations: Int): String {
        val spec = PBEKeySpec(code.toCharArray(), salt.toByteArray(Charsets.UTF_8), iterations, 256)
        val factory = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256")
        val bytes = factory.generateSecret(spec).encoded
        val sb = StringBuilder(bytes.size * 2)
        for (b in bytes) {
            sb.append(HEX[(b.toInt() shr 4) and 0x0F])
            sb.append(HEX[b.toInt() and 0x0F])
        }
        return sb.toString()
    }

    private const val HEX = "0123456789abcdef"

    private fun constantTimeEquals(a: String, b: String): Boolean {
        if (a.length != b.length) return false
        var diff = 0
        for (i in a.indices) diff = diff or (a[i].code xor b[i].code)
        return diff == 0
    }

    /**
     * Mengembalikan tanggal yang cocok, atau null. Gerbang "8 digit" di depan
     * bukan sekadar validasi: ia yang membuat percobaan password normal tidak
     * membayar tiga kali PBKDF2.
     */
    fun verifyAgainst(input: String, envelope: Envelope, days: List<String>): String? {
        if (!envelope.enabled || envelope.iterations <= 0) return null

        val cleaned = input.trim()
        if (cleaned.length != 8 || !cleaned.all { it in '0'..'9' }) return null

        for (day in days) {
            val entry = envelope.entries.firstOrNull { it.day == day } ?: continue
            try {
                val computed = pbkdf2Hex(cleaned, entry.salt, envelope.iterations)
                if (constantTimeEquals(computed, entry.hash.lowercase(Locale.US))) return day
            } catch (e: Throwable) {
                Log.e(TAG, "Gagal menghitung PBKDF2 untuk $day", e)
            }
        }
        return null
    }

    fun parseEnvelope(raw: String?): Envelope {
        val kosong = Envelope(false, 0, emptyList())
        if (raw.isNullOrBlank()) return kosong

        return try {
            val o = JSONObject(raw)
            val enabled = o.optBoolean("enabled", false)
            val iterations = o.optInt("iterations", 0)
            val arr: JSONArray = o.optJSONArray("days") ?: JSONArray()

            val list = ArrayList<Entry>(arr.length())
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                val day = e.optString("day", "")
                val salt = e.optString("salt", "")
                val hash = e.optString("hash", "")
                if (day.isNotBlank() && salt.isNotBlank() && hash.isNotBlank()) {
                    list.add(Entry(day, salt, hash))
                }
            }

            if (!enabled || iterations <= 0 || list.isEmpty()) Envelope(false, iterations, list)
            else Envelope(true, iterations, list)
        } catch (e: Throwable) {
            Log.w(TAG, "Amplop offline tidak terbaca", e)
            kosong
        }
    }

    /**
     * Murni supaya bisa diuji: SharedPreferences tidak tersedia di unit test JVM,
     * sementara justru aturan "kelima mengunci" inilah yang perlu diuji.
     *
     * `lockUntil == 0L` berarti kuncian tidak berubah — pemanggil tidak boleh
     * menuliskannya, karena itu akan menghapus kuncian yang sedang berjalan.
     */
    fun nextFailureState(currentFails: Int, nowMillis: Long): FailureState {
        val fails = currentFails + 1
        return if (fails >= MAX_FAILS) FailureState(0, nowMillis + LOCKOUT_MS)
        else FailureState(fails, 0L)
    }

    // ---------- Bagian yang menyentuh prefs ----------

    fun storeEnvelope(context: Context, rawJson: String?) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (rawJson.isNullOrBlank()) {
            prefs.edit().remove(KEY_ENVELOPE).apply()
        } else {
            prefs.edit().putString(KEY_ENVELOPE, rawJson).apply()
        }
    }

    fun storedEnvelope(context: Context): Envelope =
        parseEnvelope(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_ENVELOPE, null))

    fun rememberServerDay(context: Context, serverDay: String?) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val next = advanceAnchor(prefs.getString(KEY_ANCHOR, null), serverDay) ?: return
        prefs.edit().putString(KEY_ANCHOR, next).apply()
    }

    fun isLockedOut(context: Context, nowMillis: Long = System.currentTimeMillis()): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong(KEY_LOCK_UNTIL, 0L) > nowMillis

    /**
     * Dicatat di prefs, bukan di memori, supaya menutup-buka dialog tidak
     * mengatur ulang penghitung.
     */
    fun recordFailure(context: Context, nowMillis: Long = System.currentTimeMillis()) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val next = nextFailureState(prefs.getInt(KEY_FAILS, 0), nowMillis)
        val editor = prefs.edit().putInt(KEY_FAILS, next.fails)
        if (next.lockUntil > 0L) {
            editor.putLong(KEY_LOCK_UNTIL, next.lockUntil)
        }
        editor.apply()
    }

    fun clearFailures(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putInt(KEY_FAILS, 0).putLong(KEY_LOCK_UNTIL, 0L).apply()
    }

    /**
     * Verifikasi lengkap terhadap amplop tersimpan. Mengembalikan tanggal kode
     * yang cocok, atau null bila ditolak / terkunci / jalur offline mati.
     */
    fun attempt(context: Context, input: String, nowMillis: Long = System.currentTimeMillis()): String? {
        if (isLockedOut(context, nowMillis)) return null

        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val envelope = storedEnvelope(context)
        if (!envelope.enabled) return null

        val days = candidateDays(nowMillis, prefs.getString(KEY_ANCHOR, null))
        if (days.isEmpty()) return null

        return verifyAgainst(input, envelope, days)
    }
}
