package id.sch.cbt.kiosk.security

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

/**
 * Jejak pemakaian kode keluar offline.
 *
 * Kode offline berlaku bahkan saat server sehat (keputusan #3 di spec), jadi
 * tanpa jejak ini ia menjadi pintu yang tidak meninggalkan bekas apa pun.
 * Antrian ditahan di prefs sampai server sempat menerimanya.
 */
object OfflineExitAudit {

    private const val TAG = "OfflineExitAudit"

    const val KEY_QUEUE = "pending_offline_exits"

    /** Sama dengan MAX_EVENTS_PER_REQUEST di KioskOfflineCode.php. */
    const val MAX_QUEUE = 20

    private const val TIMEOUT_MS = 8000

    // ---------- Bagian murni ----------

    fun appendToQueue(rawQueue: String?, at: Long, codeDay: String, appVersion: String): String {
        val arr = try {
            if (rawQueue.isNullOrBlank()) JSONArray() else JSONArray(rawQueue)
        } catch (e: Throwable) {
            Log.w(TAG, "Antrian audit rusak, dimulai ulang", e)
            JSONArray()
        }

        arr.put(
            JSONObject()
                .put("at", at)
                .put("code_day", codeDay)
                .put("app_version", appVersion)
        )

        // Buang yang terlama bila melampaui batas: laporan terbaru lebih
        // berguna daripada yang sudah lama tertahan.
        if (arr.length() <= MAX_QUEUE) return arr.toString()

        val trimmed = JSONArray()
        for (i in (arr.length() - MAX_QUEUE) until arr.length()) {
            trimmed.put(arr.get(i))
        }
        return trimmed.toString()
    }

    fun queueSize(rawQueue: String?): Int = try {
        if (rawQueue.isNullOrBlank()) 0 else JSONArray(rawQueue).length()
    } catch (e: Throwable) {
        0
    }

    // ---------- Bagian yang menyentuh prefs & jaringan ----------

    fun record(context: Context, codeDay: String, appVersion: String) {
        try {
            val prefs = context.getSharedPreferences(OfflineExitCode.PREFS, Context.MODE_PRIVATE)
            val next = appendToQueue(
                prefs.getString(KEY_QUEUE, null),
                System.currentTimeMillis() / 1000L,
                codeDay,
                appVersion
            )
            prefs.edit().putString(KEY_QUEUE, next).apply()
        } catch (e: Throwable) {
            Log.e(TAG, "Gagal mencatat pemakaian kode offline", e)
        }
    }

    /**
     * Dikirim di thread latar. Antrian hanya dikosongkan setelah server membalas
     * 2xx — kegagalan jaringan tidak boleh menghilangkan jejak.
     */
    fun flush(context: Context, baseUrl: String, deviceId: String) {
        if (baseUrl.isBlank() || deviceId.isBlank()) return

        val prefs = context.getSharedPreferences(OfflineExitCode.PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_QUEUE, null)
        if (queueSize(raw) == 0) return

        kotlin.concurrent.thread(start = true, isDaemon = true, name = "OfflineExitAuditFlush") {
            var connection: HttpURLConnection? = null
            try {
                val payload = JSONObject()
                    .put("device_id", deviceId)
                    .put("events", JSONArray(raw))
                    .toString()

                connection = (URL("$baseUrl/api/kiosk/offline-exit-log").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    connectTimeout = TIMEOUT_MS
                    readTimeout = TIMEOUT_MS
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json")
                }

                OutputStreamWriter(connection.outputStream, Charsets.UTF_8).use { it.write(payload) }

                val code = connection.responseCode
                if (code in 200..299) {
                    prefs.edit().remove(KEY_QUEUE).apply()
                    Log.w(TAG, "Jejak kode offline terkirim ($code)")
                } else {
                    Log.w(TAG, "Server menolak jejak kode offline: $code; antrian ditahan")
                }
            } catch (e: Throwable) {
                Log.w(TAG, "Gagal mengirim jejak kode offline; antrian ditahan", e)
            } finally {
                try {
                    connection?.disconnect()
                } catch (e: Throwable) {
                    // Diabaikan: menutup koneksi tidak boleh menggagalkan apa pun.
                }
            }
        }
    }
}
