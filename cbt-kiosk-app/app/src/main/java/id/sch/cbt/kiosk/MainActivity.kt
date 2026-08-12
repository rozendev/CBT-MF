package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.kiosk.KioskManager

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView
    lateinit var kioskManager: KioskManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        kioskManager = KioskManager(this)

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
}
