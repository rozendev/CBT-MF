package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Build
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.kiosk.KioskGuardService
import id.sch.cbt.kiosk.kiosk.KioskManager
import id.sch.cbt.kiosk.security.SecurityManager

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView
    lateinit var kioskManager: KioskManager
    lateinit var securityManager: SecurityManager

    var currentExitPassword = "123456"

    private lateinit var setupLayout: View
    private lateinit var examContainer: View
    private lateinit var etServerUrl: EditText
    private lateinit var btnStartExam: Button
    private lateinit var tvBatteryStatus: TextView
    private lateinit var tvNetworkStatus: TextView
    private lateinit var btnReloadPage: Button
    private lateinit var btnExitKiosk: Button
    private lateinit var prefs: SharedPreferences

    private var batteryReceiver: BroadcastReceiver? = null

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
        examContainer = findViewById(R.id.examContainer)
        etServerUrl = findViewById(R.id.etServerUrl)
        btnStartExam = findViewById(R.id.btnStartExam)
        webView = findViewById(R.id.webView)
        tvBatteryStatus = findViewById(R.id.tvBatteryStatus)
        tvNetworkStatus = findViewById(R.id.tvNetworkStatus)
        btnReloadPage = findViewById(R.id.btnReloadPage)
        btnExitKiosk = findViewById(R.id.btnExitKiosk)

        setupWebView()
        setupToolbarListeners()

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

    private fun setupToolbarListeners() {
        btnReloadPage.setOnClickListener {
            if (::webView.isInitialized) {
                webView.reload()
                Toast.makeText(this, "Halaman dimuat ulang", Toast.LENGTH_SHORT).show()
            }
        }

        btnExitKiosk.setOnClickListener {
            showExitPasswordDialog()
        }
    }

    private fun showExitPasswordDialog() {
        val builder = AlertDialog.Builder(this)
        builder.setTitle("🚪 Password Keluar Kiosk")
        builder.setMessage("Masukkan password pengawas untuk melepas penguncian aplikasi:")

        val input = EditText(this)
        input.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        input.hint = "Password Pengawas"
        builder.setView(input)

        builder.setPositiveButton("Buka Kunci") { dialog, _ ->
            val enteredPassword = input.text.toString().trim()
            if (enteredPassword == currentExitPassword) {
                kioskManager.stopKiosk()
                Toast.makeText(this, "✅ Kiosk Mode Dibuka!", Toast.LENGTH_SHORT).show()
                showSetupScreen()
            } else {
                Toast.makeText(this, "❌ Password Keluar Salah!", Toast.LENGTH_LONG).show()
            }
            dialog.dismiss()
        }

        builder.setNegativeButton("Batal") { dialog, _ ->
            dialog.cancel()
        }

        builder.show()
    }

    private fun startExamAndLockKiosk(url: String) {
        setupLayout.visibility = View.GONE
        examContainer.visibility = View.VISIBLE

        webView.loadUrl(url)

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
            examContainer.visibility = View.GONE
            webView.loadUrl("about:blank")
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val settings: WebSettings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true

        webView.addJavascriptInterface(CommsBridge(this), "CommsBridge")

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                return false
            }
        }
    }

    private fun registerStatusReceivers() {
        // Battery Receiver
        batteryReceiver = object : BroadcastReceiver() {
            override fun onReceive(context: Context?, intent: Intent?) {
                intent?.let {
                    val level = it.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                    val scale = it.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
                    val pct = if (level != -1 && scale != -1) (level * 100 / scale.toFloat()).toInt() else 0
                    val isCharging = it.getIntExtra(BatteryManager.EXTRA_STATUS, -1) == BatteryManager.BATTERY_STATUS_CHARGING
                    tvBatteryStatus.text = if (isCharging) "⚡ $pct%" else "🔋 $pct%"
                }
            }
        }
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))

        // Update Network Status
        updateNetworkStatus()
    }

    private fun updateNetworkStatus() {
        val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        if (cm != null) {
            val activeNetwork = cm.activeNetwork
            val caps = cm.getNetworkCapabilities(activeNetwork)
            if (caps != null) {
                if (caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) {
                    tvNetworkStatus.text = "📶 WiFi"
                } else if (caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)) {
                    tvNetworkStatus.text = "📶 Seluler"
                } else {
                    tvNetworkStatus.text = "🌐 Terhubung"
                }
            } else {
                tvNetworkStatus.text = "⚠️ Offline"
            }
        } else {
            tvNetworkStatus.text = "📶 --"
        }
    }

    private fun unregisterStatusReceivers() {
        batteryReceiver?.let {
            try { unregisterReceiver(it) } catch (e: Exception) {}
        }
    }

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            return
        }
        if (setupLayout.visibility == View.VISIBLE) {
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
        registerStatusReceivers()
    }

    override fun onPause() {
        super.onPause()
        KioskGuardService.isMainActivityVisible = false
        unregisterStatusReceivers()
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            if (::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "exit_attempt", "{}")
            }
        }
    }
}
