package id.sch.cbt.kiosk.bridge

import android.webkit.JavascriptInterface
import android.webkit.WebView
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.security.RootDetector

class CommsBridge(private val activity: MainActivity) {

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

    companion object {
        fun sendEventToJS(webView: WebView, eventName: String, dataJson: String) {
            val script = "window.dispatchEvent(new CustomEvent('$eventName', { detail: $dataJson }));"
            webView.post { webView.evaluateJavascript(script, null) }
        }
    }
}