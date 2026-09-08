package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Handler
import android.os.Looper
import android.util.Log
import id.sch.cbt.kiosk.BuildConfig
import id.sch.cbt.kiosk.DeviceIdentityStore
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * Sends device status heartbeats to the CBT-MF server while the kiosk
 * exam is active: `POST {server_url}/kiosk-heartbeat.php` every 15s.
 *
 * - 200 → continue
 * - 401 → stop and block the session via [onUnauthorized]
 * - 403 → stop and block the device via [onDeviceBanned]
 * - 503 / network error → back off to 30s (outage noise guard)
 */
class HeartbeatManager(
    private val activity: Activity,
    private val onUnauthorized: () -> Unit,
    private val onDeviceBanned: (reason: String) -> Unit,
) {

    companion object {
        private const val TAG = "HeartbeatManager"
        private const val INTERVAL_MS = 15_000L
        private const val BACKOFF_MS = 30_000L
        private const val TIMEOUT_MS = 5_000
    }

    private val handler = Handler(Looper.getMainLooper())
    private var examId = ""
    private var token = ""

    @Volatile
    private var running = false

    @Volatile
    private var backoff = false

    @Volatile
    private var generation = 0

    fun start(examId: String, token: String) {
        this.examId = examId
        this.token = token
        if (running) return
        // The pre-JS placeholder token must never arm the timer: it would fire a
        // false 401 heartbeat before the page hands off the real token. When the
        // real token arrives, running == false and the loop starts normally.
        if (token.isBlank() || token == "TOKEN") return
        running = true
        backoff = false
        generation++
        Log.d(TAG, "heartbeat started for exam $examId")
        schedule()
    }

    fun stop() {
        running = false
        generation++
        handler.removeCallbacksAndMessages(null)
        Log.d(TAG, "heartbeat stopped")
    }

    private fun schedule() {
        if (!running) return
        handler.postDelayed({ tick() }, if (backoff) BACKOFF_MS else INTERVAL_MS)
    }

    private fun tick() {
        if (!running || token.isBlank()) return
        val url = (activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)
            .getString("server_url", "") ?: "")
            .trimEnd('/') + "/kiosk-heartbeat.php"
        if (url == "/kiosk-heartbeat.php") {
            schedule()
            return
        }

        // Lewat DeviceIdentityStore, BUKAN membaca kunci prefs langsung.
        // Membaca kunci sendiri di sini pernah membuat heartbeat mengirim
        // penanda lama — atau kosong — sementara /api/kiosk/config mengirim
        // penanda baru, sehingga kedua titik penegakan memeriksa identitas
        // yang berbeda dan blokir yang dipasang pengawas tidak pernah menggigit.
        val deviceId = DeviceIdentityStore.resolve(activity)
        val payload = buildPayload(deviceId)

        val requestGeneration = generation
        thread(start = true, isDaemon = true, name = "KioskHeartbeat") {
            var response = HeartbeatResponse(0, JSONObject())
            try {
                response = postJson(url, payload)
            } catch (e: Throwable) {
                Log.w(TAG, "heartbeat request failed", e)
            }

            // Respons dari sesi lama tidak boleh menghentikan sesi baru yang sudah
            // memakai token berbeda.
            if (!running || requestGeneration != generation) {
                return@thread
            }

            when (response.code) {
                401 -> {
                    running = false
                    generation++
                    handler.removeCallbacksAndMessages(null)
                    activity.runOnUiThread { onUnauthorized() }
                }
                403 -> {
                    running = false
                    generation++
                    handler.removeCallbacksAndMessages(null)
                    val reason = response.body.optString("reason", "")
                    activity.runOnUiThread { onDeviceBanned(reason) }
                }
                200 -> backoff = false
                else -> backoff = true // 503 / 5xx / network error
            }

            handler.post { schedule() }
        }
    }

    private fun buildPayload(deviceId: String): String {
        val battery = (activity.getSystemService(Context.BATTERY_SERVICE) as BatteryManager)
            .getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        val isCharging = try {
            val sticky = activity.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            sticky?.getIntExtra(BatteryManager.EXTRA_STATUS, -1) == BatteryManager.BATTERY_STATUS_CHARGING
        } catch (e: Throwable) { false }

        val cm = activity.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = try {
            val caps = cm.getNetworkCapabilities(cm.activeNetwork)
            when {
                caps == null -> "none"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                else -> "none"
            }
        } catch (e: Throwable) { "none" }

        return JSONObject()
            .put("token", token)
            .put("device_id", deviceId)
            .put("battery", battery)
            .put("charging", isCharging)
            .put("network", network)
            .put("app_version", BuildConfig.VERSION_NAME)
            // Izin overlay diberikan pengguna dan bisa dicabut kapan saja,
            // termasuk di tengah ujian. Perangkat tanpa izin ini kehilangan
            // satu-satunya mekanisme tarik-kembali yang tersisa, dan pengawas
            // tidak punya cara lain mengetahuinya. Server saat ini mengabaikan
            // field tak dikenal, jadi datanya mulai mengalir lebih dulu.
            .put("overlay_guard", KioskOverlay.isGranted(activity))
            // Status penguncian sesungguhnya, bukan yang diklaim aplikasi.
            // Tanpa ini pengawas tidak punya cara apa pun mengetahui perangkat
            // yang siswanya menolak "Sematkan layar?" di awal ujian.
            .put("pinned", KioskManager.isInLockTask(activity) ?: JSONObject.NULL)
            .toString()
    }

    private data class HeartbeatResponse(val code: Int, val body: JSONObject)

    private fun postJson(url: String, body: String): HeartbeatResponse {
        val conn = URL(url).openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = TIMEOUT_MS
            conn.readTimeout = TIMEOUT_MS
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            val stream = if (code >= 400) conn.errorStream else conn.inputStream
            val text = stream?.bufferedReader()?.use { it.readText() } ?: "{}"
            val json = try {
                JSONObject(text)
            } catch (_: Throwable) {
                JSONObject()
            }
            HeartbeatResponse(code, json)
        } finally {
            conn.disconnect()
        }
    }
}
