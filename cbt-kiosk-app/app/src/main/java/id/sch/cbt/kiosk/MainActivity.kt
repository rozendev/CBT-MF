package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.kiosk.KioskGuardService
import id.sch.cbt.kiosk.kiosk.KioskManager
import id.sch.cbt.kiosk.security.SecurityManager

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView
    lateinit var kioskManager: KioskManager
    lateinit var securityManager: SecurityManager

    fun getSafeWebView(): WebView? {
        return try { webView } catch (e: UninitializedPropertyAccessException) { null }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        kioskManager = KioskManager(this)
        securityManager = SecurityManager(this)
        kioskManager.setSecurityManager(securityManager)

        webView = findViewById(R.id.webView)
        setupWebView()

        // Default URL testing
        webView.loadUrl("https://google.com")
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val settings: WebSettings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true

        // Add Javascript Interface
        webView.addJavascriptInterface(CommsBridge(this), "CommsBridge")

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                // Return false to let WebView handle navigation internally (preserves POST data & form submissions)
                return false
            }
        }
    }

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            // Blokir tombol back saat mode kiosk aktif
            return
        }
        if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
        }
        // Never call super.onBackPressed() to prevent exiting app from root page (kiosk requirement)
    }

    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            if (::securityManager.isInitialized) {
                securityManager.handleMultiWindow(isInMultiWindowMode, isInPictureInPictureMode)
            } else if (isInMultiWindowMode && ::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
            }
        }
    }

    @Suppress("DEPRECATION")
    override fun onPictureInPictureModeChanged(isInPictureInPictureMode: Boolean) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode)
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            if (::securityManager.isInitialized) {
                securityManager.handleMultiWindow(isInMultiWindowMode = false, isInPictureInPictureMode = isInPictureInPictureMode)
            } else if (isInPictureInPictureMode && ::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
            }
        }
    }

    override fun onResume() {
        super.onResume()
        KioskGuardService.isMainActivityVisible = true
    }

    override fun onPause() {
        super.onPause()
        KioskGuardService.isMainActivityVisible = false
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            if (::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "exit_attempt", "{}")
            }
        }
    }
}
