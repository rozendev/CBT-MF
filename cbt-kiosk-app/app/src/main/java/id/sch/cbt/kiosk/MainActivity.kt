package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.content.Context
import android.content.SharedPreferences
import android.os.Bundle
import android.view.View
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.kiosk.KioskGuardService
import id.sch.cbt.kiosk.kiosk.KioskManager
import id.sch.cbt.kiosk.security.SecurityManager

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView
    lateinit var kioskManager: KioskManager
    lateinit var securityManager: SecurityManager

    private lateinit var setupLayout: View
    private lateinit var etServerUrl: EditText
    private lateinit var btnStartExam: Button
    private lateinit var prefs: SharedPreferences

    fun getSafeWebView(): WebView? {
        return try { webView } catch (e: UninitializedPropertyAccessException) { null }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        prefs = getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)

        kioskManager = KioskManager(this)
        securityManager = SecurityManager(this)
        kioskManager.setSecurityManager(securityManager)

        setupLayout = findViewById(R.id.setupLayout)
        etServerUrl = findViewById(R.id.etServerUrl)
        btnStartExam = findViewById(R.id.btnStartExam)
        webView = findViewById(R.id.webView)

        setupWebView()

        // Load saved URL from preferences
        val savedUrl = prefs.getString("server_url", "")
        if (!savedUrl.isNullOrBlank()) {
            etServerUrl.setText(savedUrl)
        }

        btnStartExam.setOnClickListener {
            val inputUrl = etServerUrl.text.toString().trim()
            if (inputUrl.isEmpty()) {
                Toast.makeText(this, "Silakan masukkan URL Server CBT!", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            var finalUrl = inputUrl
            if (!finalUrl.startsWith("http://") && !finalUrl.startsWith("https://")) {
                finalUrl = "http://$finalUrl"
            }

            // Save URL to preferences
            prefs.edit().putString("server_url", finalUrl).apply()

            startExamAndLockKiosk(finalUrl)
        }
    }

    private fun startExamAndLockKiosk(url: String) {
        // 1. Tampilkan WebView & Sembunyikan Form Setup
        setupLayout.visibility = View.GONE
        webView.visibility = View.VISIBLE

        // 2. Load URL ke WebView
        webView.loadUrl(url)

        // 3. SEGERA AKTIFKAN KIOSK LOCK MODE
        val started = kioskManager.startKiosk("EXAM_SESSION", "TOKEN")
        if (started) {
            Toast.makeText(this, "🔒 Kiosk Mode Aktif. Perangkat terkunci!", Toast.LENGTH_LONG).show()
            CommsBridge.sendEventToJS(webView, "kiosk_started", "{\"examId\": \"EXAM_SESSION\", \"status\": \"active\"}")
        } else {
            Toast.makeText(this, "⚠️ Gagal mengunci Kiosk Mode. Periksa izin Screen Pinning.", Toast.LENGTH_LONG).show()
            CommsBridge.sendEventToJS(webView, "kiosk_failed", "{\"error\": \"LOCK_TASK_FAILED\"}")
        }
    }

    public fun showSetupScreen() {
        runOnUiThread {
            setupLayout.visibility = View.VISIBLE
            webView.visibility = View.GONE
            webView.loadUrl("about:blank")
        }
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
        if (setupLayout.visibility == View.VISIBLE) {
            // Jika masih di halaman setup URL, biarkan sistem menutup app secara normal
            super.onBackPressed()
            return
        }
        if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
        }
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
