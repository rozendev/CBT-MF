package id.sch.cbt.kiosk.bridge

import android.webkit.JavascriptInterface
import android.webkit.WebView
import android.widget.Toast
import id.sch.cbt.kiosk.DeviceIdentityStore
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.security.RootDetector

class CommsBridge(private val activity: MainActivity) {

    /**
     * Toast native untuk pesan singkat dari halaman ujian (mis. peredam aksi
     * beruntun). Dipakai karena di kiosk halaman berjalan fullscreen — notifikasi
     * ala web mudah terlewat, sedangkan Toast muncul di atas WebView.
     */
    @JavascriptInterface
    fun toast(message: String) {
        val text = message.trim()
        if (text.isEmpty()) return
        activity.runOnUiThread {
            // Batasi panjang: pesan dari halaman tidak boleh membanjiri layar.
            Toast.makeText(activity, text.take(160), Toast.LENGTH_SHORT).show()
        }
    }

    @JavascriptInterface
    fun startKiosk(examId: String, token: String): Boolean {
        val result = activity.kioskManager.startKiosk(examId, token)
        if (result) {
            sendEventToJS(activity.webView, "kiosk_started", "{\"examId\": \"$examId\"}")
        } else {
            sendEventToJS(activity.webView, "kiosk_failed", "{\"error\": \"Failed to pin screen\"}")
        }
        return result
    }

    /**
     * Request a VERIFIED kiosk exit. The native app checks with the server
     * that the locked exam session is genuinely finished before unlocking.
     * A page alone can no longer unlock the kiosk.
     */
    @JavascriptInterface
    fun requestExit(token: String) {
        activity.runOnUiThread {
            activity.handleKioskExitRequest(token)
        }
    }

    @JavascriptInterface
    fun setExamActive(active: Boolean) {
        activity.uiBundleManager.examActive = active
    }

    @JavascriptInterface
    fun closeApp() {
        activity.runOnUiThread {
            activity.kioskManager.stopKiosk()
            activity.finishAffinity()
        }
    }

    @JavascriptInterface
    fun getKioskStatus(): String {
        return activity.kioskManager.getStatusJson()
    }

    @JavascriptInterface
    fun getDeviceInfo(): String {
        val isRooted = RootDetector.isRooted(activity)
        val release = android.os.Build.VERSION.RELEASE
        val model = android.os.Build.MODEL
        return "{\"os\": \"Android\", \"version\": \"$release\", \"model\": \"$model\", \"isRooted\": $isRooted}"
    }

    /**
     * Penanda perangkat untuk halaman login.
     *
     * Nilainya diambil dari DeviceIdentityStore — sumber yang sama dengan
     * heartbeat dan /api/kiosk/config. Mengambilnya dari tempat lain pernah
     * membuat dua titik penegakan memeriksa identitas yang berbeda, dan blokir
     * yang dipasang pengawas tidak pernah menggigit.
     */
    @JavascriptInterface
    fun getDeviceId(): String = DeviceIdentityStore.resolve(activity)

    companion object {
        fun sendEventToJS(webView: WebView, eventName: String, dataJson: String) {
            val script = "window.dispatchEvent(new CustomEvent('$eventName', { detail: $dataJson }));"
            webView.post { webView.evaluateJavascript(script, null) }
        }
    }
}