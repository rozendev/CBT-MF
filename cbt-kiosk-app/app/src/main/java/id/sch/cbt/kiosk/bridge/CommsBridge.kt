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

    @JavascriptInterface
    fun stopKiosk(): Boolean {
        return activity.kioskManager.stopKiosk()
    }

    @JavascriptInterface
    fun setExitPassword(password: String) {
        if (password.isNotBlank()) {
            activity.currentExitPassword = password
        }
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
