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
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * Sends device status heartbeats to the CBT-MF server while the kiosk
 * exam is active: `POST {server_url}/kiosk-heartbeat.php` every 15s.
 *
 * - 200 → continue
 * - 401 → stop and notify via [onUnauthorized] (session expired)
 * - 503 / network error → back off to 30s (outage noise guard)
 */
class HeartbeatManager(
    private val activity: Activity,
    private val onUnauthorized: () -> Unit
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
    private var running = false
    private var backoff = false

    fun start(examId: String, token: String) {
        this.examId = examId
        this.token = token
        if (running) return
        running = true
        Log.d(TAG, "heartbeat started for exam $examId")
        schedule()
    }

    fun stop() {
        running = false
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

        val deviceId = activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)
            .getString("kiosk_device_id", "") ?: ""
        val payload = buildPayload(deviceId)

        thread(start = true, isDaemon = true, name = "KioskHeartbeat") {
            var code = 0
            try {
                code = postJson(url, payload)
            } catch (e: Throwable) {
                Log.w(TAG, "heartbeat request failed", e)
            }

            when {
                code == 401 -> {
                    running = false
                    handler.removeCallbacksAndMessages(null)
                    activity.runOnUiThread { onUnauthorized() }
                }
                code == 200 -> backoff = false
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
            .toString()
    }

    private fun postJson(url: String, body: String): Int {
        val conn = URL(url).openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = TIMEOUT_MS
            conn.readTimeout = TIMEOUT_MS
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            conn.responseCode
        } finally {
            conn.disconnect()
        }
    }
}
