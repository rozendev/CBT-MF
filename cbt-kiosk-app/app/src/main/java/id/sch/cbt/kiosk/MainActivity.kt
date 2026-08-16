package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.BatteryManager
import android.os.Build
import android.os.Bundle
import android.text.InputType
import android.util.Log
import android.view.View
import android.webkit.ConsoleMessage
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.ImageButton
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.webkit.WebViewAssetLoader
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.bundle.UiBundleManager
import id.sch.cbt.kiosk.kiosk.HeartbeatManager
import id.sch.cbt.kiosk.kiosk.KioskGuardService
import id.sch.cbt.kiosk.kiosk.KioskManager
import id.sch.cbt.kiosk.security.RootDetector
import id.sch.cbt.kiosk.security.SecurityManager
import id.sch.cbt.kiosk.security.SirenAlarmManager
import java.io.ByteArrayInputStream
import java.io.File
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView
    lateinit var kioskManager: KioskManager
    lateinit var securityManager: SecurityManager
    lateinit var uiBundleManager: UiBundleManager

    private lateinit var setupLayout: View
    private lateinit var examContainer: View
    private lateinit var etServerUrl: EditText
    private lateinit var btnStartExam: Button
    private lateinit var btnImportBundle: Button
    private lateinit var tvBatteryStatus: TextView
    private lateinit var tvNetworkStatus: TextView
    private lateinit var tvBundleStatus: TextView
    private lateinit var btnReloadPage: ImageButton
    private lateinit var btnExitKiosk: ImageButton
    private lateinit var prefs: SharedPreferences

    private var batteryReceiver: BroadcastReceiver? = null

    // Only this host (and its subdomains) may be loaded inside the WebView.
    private var allowedHost: String? = null

    private var pendingBundleBaseUrl: String? = null
    private var examFlowRequested = false
    private var bundleFlowStarted = false
    private var bundleDownloadActive = false

    companion object {
        private const val REQ_IMPORT_BUNDLE = 4001
    }

    fun getSafeWebView(): WebView? {
        return try { webView } catch (e: UninitializedPropertyAccessException) { null }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        // Global Uncaught Exception Handler to prevent silent app crashes
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            Log.e("CBTKiosk", "Uncaught exception in thread ${thread.name}", throwable)
        }

        super.onCreate(savedInstanceState)
        try {
            setContentView(R.layout.activity_main)

            prefs = getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)

            uiBundleManager = UiBundleManager(
                this,
                prefs,
                onReady = { ready ->
                    if (ready) {
                        runOnUiThread { proceedToBundleExam() }
                    }
                },
                onError = { message ->
                    runOnUiThread { Toast.makeText(this, message, Toast.LENGTH_LONG).show() }
                }
            )

            // Load saved security config from preferences for offline resilience
            SirenAlarmManager.isSirenEnabled = prefs.getBoolean("kiosk_siren_enabled", true)
            SirenAlarmManager.isSirenMaxVolume = prefs.getBoolean("kiosk_siren_max_volume", true)

            kioskManager = KioskManager(this)
            securityManager = SecurityManager(this)
            kioskManager.setSecurityManager(securityManager)

            kioskManager.setHeartbeatManager(
                HeartbeatManager(this) {
                    val safeWebView = getSafeWebView()
                    if (safeWebView != null) {
                        CommsBridge.sendEventToJS(
                            safeWebView,
                            "kiosk_failed",
                            "{\"error\": \"Sesi kiosk tidak valid (401)\"}"
                        )
                    }
                }
            )

            // Ensure the device id exists up front so the first heartbeat is never blank.
            getOrCreateDeviceId()

            setupLayout = findViewById(R.id.setupLayout)
            examContainer = findViewById(R.id.examContainer)
            etServerUrl = findViewById(R.id.etServerUrl)
            btnStartExam = findViewById(R.id.btnStartExam)
            btnImportBundle = findViewById(R.id.btnImportBundle)
            webView = findViewById(R.id.webView)
            tvBatteryStatus = findViewById(R.id.tvBatteryStatus)
            tvNetworkStatus = findViewById(R.id.tvNetworkStatus)
            tvBundleStatus = findViewById(R.id.tvBundleStatus)
            btnReloadPage = findViewById(R.id.btnReloadPage)
            btnExitKiosk = findViewById(R.id.btnExitKiosk)

            setupWebView()
            setupToolbarListeners()

            btnImportBundle.setOnClickListener {
                val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
                    type = "application/zip"
                    addCategory(Intent.CATEGORY_OPENABLE)
                }
                try {
                    startActivityForResult(intent, REQ_IMPORT_BUNDLE)
                } catch (e: Throwable) {
                    Toast.makeText(this, "Tidak ada aplikasi pemilih file.", Toast.LENGTH_LONG).show()
                }
            }

            // Side-load: bundle zip dibuka dari file manager / share intent.
            handleBundleIntent(intent)

            // Load saved URL from preferences
            val savedUrl = prefs.getString("server_url", "")
            if (!savedUrl.isNullOrBlank()) {
                etServerUrl.setText(savedUrl)
                fetchServerKioskConfig(savedUrl)
            }

            btnStartExam.setOnClickListener {
                val inputUrl = etServerUrl.text.toString().trim()
                if (inputUrl.isEmpty()) {
                    Toast.makeText(this, getString(R.string.toast_url_empty), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }

                // HTTPS ONLY: plaintext HTTP is a MITM risk for exam integrity.
                var finalUrl = inputUrl
                if (finalUrl.startsWith("http://")) {
                    finalUrl = "https://" + finalUrl.removePrefix("http://")
                    Toast.makeText(this, getString(R.string.toast_https_redirect), Toast.LENGTH_LONG).show()
                } else if (!finalUrl.startsWith("https://")) {
                    finalUrl = "https://$finalUrl"
                }

                if (!enforceDevicePolicy()) return@setOnClickListener

                // Save URL to preferences
                prefs.edit().putString("server_url", finalUrl).apply()

                startExamAndLockKiosk(finalUrl)
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error in onCreate", e)
        }
    }

    /**
     * Device-level policy checks before starting the exam:
     * min app version and root strictness from the server config.
     */
    private fun enforceDevicePolicy(): Boolean {
        val minVersion = prefs.getString("kiosk_min_app_version", "1.0.0") ?: "1.0.0"
        if (!isVersionAtLeast(BuildConfig.VERSION_NAME, minVersion)) {
            Toast.makeText(this, getString(R.string.toast_version_too_old), Toast.LENGTH_LONG).show()
            return false
        }

        val rootStrictness = prefs.getString("kiosk_root_strictness", "warning") ?: "warning"
        if (rootStrictness == "strict_block" && RootDetector.isRooted(this)) {
            Toast.makeText(this, getString(R.string.toast_root_blocked), Toast.LENGTH_LONG).show()
            return false
        }
        return true
    }

    private fun isVersionAtLeast(installed: String, required: String): Boolean {
        return try {
            val a = installed.split('.').map { it.toIntOrNull() ?: 0 }
            val b = required.split('.').map { it.toIntOrNull() ?: 0 }
            for (i in 0 until maxOf(a.size, b.size)) {
                val av = a.getOrElse(i) { 0 }
                val bv = b.getOrElse(i) { 0 }
                if (av > bv) return true
                if (av < bv) return false
            }
            true
        } catch (e: Throwable) {
            true
        }
    }

    private fun setupToolbarListeners() {
        btnReloadPage.setOnClickListener {
            if (::webView.isInitialized) {
                try {
                    // Flow bundle belum jalan (config fetch gagal / download belum selesai):
                    // reload = retry konfigurasi, bukan reload about:blank.
                    if (examFlowRequested && !bundleFlowStarted) {
                        val retryUrl = pendingBundleBaseUrl
                        if (!retryUrl.isNullOrBlank()) {
                            fetchServerKioskConfig(retryUrl)
                            return@setOnClickListener
                        }
                    }
                    webView.reload()
                    Toast.makeText(this, getString(R.string.toast_page_reloaded), Toast.LENGTH_SHORT).show()
                } catch (e: Throwable) {
                    Log.e("MainActivity", "Error reloading webView", e)
                }
            }
        }

        btnExitKiosk.setOnClickListener {
            showExitPasswordDialog()
        }
    }

    private fun showExitPasswordDialog() {
        try {
            val builder = AlertDialog.Builder(this)
            builder.setTitle(getString(R.string.exit_dialog_title))
            builder.setMessage(getString(R.string.exit_dialog_message))

            val input = EditText(this)
            input.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            input.hint = getString(R.string.exit_dialog_hint)
            builder.setView(input)

            builder.setPositiveButton(getString(R.string.exit_dialog_confirm)) { dialog, _ ->
                val enteredPassword = input.text.toString().trim()
                verifyExitPassword(enteredPassword) { allowed, message ->
                    runOnUiThread {
                        if (allowed) {
                            SirenAlarmManager.stopSiren()
                            kioskManager.stopKiosk()
                            Toast.makeText(this, getString(R.string.toast_kiosk_unlocked), Toast.LENGTH_SHORT).show()
                        } else {
                            // Trigger loud siren alarm when password is wrong
                            SirenAlarmManager.startSiren(this)
                            Toast.makeText(this, message ?: getString(R.string.toast_wrong_password), Toast.LENGTH_LONG).show()
                        }
                    }
                }
                dialog.dismiss()
            }

            builder.setNegativeButton(getString(R.string.exit_dialog_cancel)) { dialog, _ ->
                dialog.cancel()
            }

            builder.show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error showing exit dialog", e)
        }
    }

    private fun getOrCreateDeviceId(): String {
        val existing = prefs.getString("kiosk_device_id", "")
        if (!existing.isNullOrBlank()) return existing
        val newId = java.util.UUID.randomUUID().toString()
        prefs.edit().putString("kiosk_device_id", newId).apply()
        return newId
    }

    /**
     * Verify the proctor password against the server (rate-limited there).
     * The password is never stored on the device.
     */
    private fun verifyExitPassword(password: String, callback: (Boolean, String?) -> Unit) {
        val baseUrl = prefs.getString("server_url", "") ?: ""
        if (baseUrl.isBlank() || password.isBlank()) {
            callback(false, getString(R.string.toast_password_empty))
            return
        }
        kotlin.concurrent.thread(start = true, isDaemon = true, name = "KioskVerifyPassword") {
            try {
                val escaped = password.replace("\\", "\\\\").replace("\"", "\\\"")
                val deviceId = getOrCreateDeviceId()
                val payload = "{\"password\": \"$escaped\", \"device_id\": \"$deviceId\"}"
                val response = postJson("$baseUrl/api/kiosk/verify-exit", payload)
                val allowed = try { response.first.optBoolean("allowed", false) } catch (e: Throwable) { false }
                val message = try {
                    response.first.opt("message")?.toString()?.trim()?.takeIf { it.isNotEmpty() }
                } catch (e: Throwable) { null }
                callback(allowed, message ?: getString(R.string.toast_wrong_password))
            } catch (e: Throwable) {
                Log.e("MainActivity", "Error verifying exit password", e)
                callback(false, getString(R.string.toast_verify_failed))
            }
        }
    }

    /**
     * Called from JS when the page believes the exam is finished.
     * The native app asks the server whether the locked attempt is
     * genuinely finished and only then unlocks the kiosk.
     */
    fun handleKioskExitRequest(token: String) {
        if (!kioskManager.isKioskActive) return

        val baseUrl = prefs.getString("server_url", "") ?: ""
        if (baseUrl.isBlank()) {
            triggerDeniedExit("NO_SERVER")
            return
        }

        val verifyToken = if (token.isNotBlank()) token else kioskManager.currentToken
        kotlin.concurrent.thread(start = true, isDaemon = true, name = "KioskCanExit") {
            try {
                val payload = "{\"token\": \"$verifyToken\"}"
                val (json, code) = postJson("$baseUrl/api/kiosk/can-exit", payload)
                val allowed = code >= 200 && code < 300 && json.optBoolean("allowed", false)

                runOnUiThread {
                    if (allowed) {
                        SirenAlarmManager.stopSiren()
                        kioskManager.stopKiosk()
                        Toast.makeText(this, getString(R.string.toast_exam_finished), Toast.LENGTH_LONG).show()
                    } else {
                        triggerDeniedExit("NOT_FINISHED_OR_UNVERIFIED")
                    }
                }
            } catch (e: Throwable) {
                Log.e("MainActivity", "can-exit request failed", e)
                runOnUiThread { triggerDeniedExit("VERIFY_FAILED") }
            }
        }
    }

    private fun triggerDeniedExit(reason: String) {
        SirenAlarmManager.startSiren(this)
        getSafeWebView()?.let {
            CommsBridge.sendEventToJS(it, "exit_denied", "{\"reason\": \"$reason\"}")
        }
        Toast.makeText(this, getString(R.string.toast_exam_not_verified), Toast.LENGTH_LONG).show()
    }

    /**
     * Minimal JSON POST helper returning (JSONObject, httpCode).
     */
    private fun postJson(urlString: String, body: String): Pair<org.json.JSONObject, Int> {
        val url = URL(urlString)
        val connection = url.openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 8000
            connection.readTimeout = 8000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            OutputStreamWriter(connection.outputStream).use { it.write(body) }

            val code = connection.responseCode
            val stream = if (code >= 400) connection.errorStream else connection.inputStream
            val text = stream?.bufferedReader()?.use { it.readText() } ?: "{}"
            val json = try { org.json.JSONObject(text) } catch (e: Throwable) { org.json.JSONObject("{}") }
            return Pair(json, code)
        } finally {
            connection.disconnect()
        }
    }

    private fun startExamAndLockKiosk(url: String) {
        try {
            var finalUrl = url.trimEnd('/')
            if (!finalUrl.startsWith("http://") && !finalUrl.startsWith("https://")) {
                finalUrl = "https://$finalUrl"
            }
            if (finalUrl.startsWith("http://")) {
                finalUrl = "https://" + finalUrl.removePrefix("http://")
            }

            pendingBundleBaseUrl = finalUrl
            examFlowRequested = true
            // Sesi ujian baru pada Activity yang sama (mis. setelah kembali ke
            // setup atau selesai ujian): latch harus bersih, kalau tidak
            // penekanan "Mulai Ujian" berikutnya diabaikan.
            bundleFlowStarted = false

            setupLayout.visibility = View.GONE
            examContainer.visibility = View.VISIBLE

            allowedHost = try { Uri.parse(finalUrl).host } catch (e: Throwable) { null }
            if (allowedHost.isNullOrBlank()) {
                Toast.makeText(this, getString(R.string.toast_url_invalid), Toast.LENGTH_LONG).show()
                showSetupScreen()
                return
            }

            // Config + bundle check; WebView baru di-load setelah bundle siap
            // (applyKioskConfig / onReady UiBundleManager → proceedToBundleExam).
            fetchServerKioskConfig(finalUrl)
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error starting exam and locking kiosk", e)
        }
    }

    /**
     * Memuat halaman login dari bundle lokal via WebViewAssetLoader, lalu
     * mengunci kiosk. Dipanggil hanya ketika bundle UI sudah siap.
     */
    private fun proceedToBundleExam() {
        // Urutan penting: cek examFlowRequested SEBELUM membakar latch.
        // fetchServerKioskConfig juga dipanggil saat startup (URL tersimpan),
        // jadi jalur ini tercapai sebelum user menekan "Mulai Ujian". Bila latch
        // terbakar di situ, penekanan tombol berikutnya diabaikan sehingga
        // WebView tetap about:blank DAN kiosk tidak pernah terkunci.
        if (!examFlowRequested) return
        if (bundleFlowStarted) {
            Log.d("MainActivity", "proceedToBundleExam: bundle flow sudah berjalan; pemanggilan diabaikan")
            return
        }
        bundleFlowStarted = true
        val baseUrl = pendingBundleBaseUrl
        if (baseUrl.isNullOrBlank()) {
            showSetupScreen()
            return
        }
        loadBundleLoginPage(baseUrl)
        lockKioskSession()
    }

    private fun loadBundleLoginPage(baseUrl: String) {
        val loader = WebViewAssetLoader.Builder()
            .setDomain("appassets.androidplatform.net")
            .addPathHandler(
                "/",
                WebViewAssetLoader.InternalStoragePathHandler(this, File(filesDir, "ui-bundle"))
            )
            .build()
        webView.webViewClient = object : WebViewClient() {
            override fun shouldInterceptRequest(view: WebView?, request: WebResourceRequest?): WebResourceResponse? {
                val req = request ?: return null
                val host = req.url.host
                // Bundle lokal → loader; host server (fetch API + cookie) → biarkan normal.
                if (host == "appassets.androidplatform.net") {
                    return loader.shouldInterceptRequest(req.url)
                }
                val allowed = allowedHost
                if (allowed != null && host != null && (host == allowed || host.endsWith(".$allowed"))) {
                    return null
                }
                // Hard-block semua resource lintas-host (script/image/iframe/exfil).
                return WebResourceResponse("text/plain", "utf-8", ByteArrayInputStream("blocked".toByteArray()))
            }

            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                // Internal bundle navigation bebas; segala navigasi keluar di-block.
                val host = request?.url?.host
                if (host == "appassets.androidplatform.net") return false
                return true
            }

            override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                Log.d("MainActivity", "WebView onPageStarted: $url")
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                Log.d("MainActivity", "WebView onPageFinished: $url")
            }

            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                Log.e("MainActivity", "WebView onReceivedError: url=${request?.url} isMainFrame=${request?.isForMainFrame} code=${error?.errorCode} desc=${error?.description}")
            }

            override fun onReceivedHttpError(view: WebView?, request: WebResourceRequest?, errorResponse: android.webkit.WebResourceResponse?) {
                Log.e("MainActivity", "WebView onReceivedHttpError: url=${request?.url} status=${errorResponse?.statusCode}")
            }
        }
        webView.webChromeClient = object : WebChromeClient() {
            override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
                Log.d("WebViewConsole", "${consoleMessage?.messageLevel()} ${consoleMessage?.message()} [${consoleMessage?.sourceId()}:${consoleMessage?.lineNumber()}]")
                return true
            }
        }
        webView.loadUrl("https://appassets.androidplatform.net/login.html?server=" + Uri.encode(baseUrl))
    }

    private fun lockKioskSession() {
        val started = kioskManager.startKiosk("EXAM_SESSION", "TOKEN")
        if (started) {
            Toast.makeText(this, getString(R.string.toast_kiosk_locked), Toast.LENGTH_LONG).show()
            CommsBridge.sendEventToJS(webView, "kiosk_started", "{\"examId\": \"EXAM_SESSION\", \"status\": \"active\"}")
        } else {
            Toast.makeText(this, getString(R.string.toast_kiosk_failed), Toast.LENGTH_LONG).show()
            CommsBridge.sendEventToJS(webView, "kiosk_failed", "{\"error\": \"LOCK_TASK_FAILED\"}")
        }
    }

    fun fetchServerKioskConfig(serverUrl: String) {
        if (serverUrl.isBlank()) return
        kotlin.concurrent.thread(start = true, isDaemon = true, name = "KioskConfigFetcher") {
            try {
                var baseUrl = serverUrl.trimEnd('/')
                if (!baseUrl.startsWith("http://") && !baseUrl.startsWith("https://")) {
                    baseUrl = "https://$baseUrl"
                }
                if (baseUrl.startsWith("http://")) {
                    baseUrl = "https://" + baseUrl.removePrefix("http://")
                }
                val configUrl = "$baseUrl/api/kiosk/config"

                Log.d("MainActivity", "Fetching kiosk config from: $configUrl")
                val url = java.net.URL(configUrl)
                val connection = url.openConnection() as java.net.HttpURLConnection
                connection.requestMethod = "GET"
                connection.connectTimeout = 5000
                connection.readTimeout = 5000
                connection.setRequestProperty("Accept", "application/json")

                val responseCode = connection.responseCode
                if (responseCode == 200) {
                    val jsonString = connection.inputStream.bufferedReader().use { it.readText() }
                    runOnUiThread {
                        applyKioskConfig(jsonString, baseUrl)
                    }
                } else {
                    Log.w("MainActivity", "Failed to fetch kiosk config, response code: $responseCode")
                    handleConfigFetchFailure()
                }
                connection.disconnect()
            } catch (e: Throwable) {
                Log.e("MainActivity", "Error fetching kiosk config from $serverUrl", e)
                handleConfigFetchFailure()
            }
        }
    }

    /**
     * Config fetch gagal → jangan diam dengan WebView kosong: toast + kembali
     * ke setup screen (retry = tombol "Mulai Ujian" / reload toolbar).
     */
    private fun handleConfigFetchFailure() {
        runOnUiThread {
            Toast.makeText(this, "Gagal memuat konfigurasi server. Periksa URL server.", Toast.LENGTH_LONG).show()
            if (examFlowRequested) {
                showSetupScreen()
            }
        }
    }

    fun applyKioskConfig(configJson: String, serverBaseUrl: String) {
        try {
            val json = org.json.JSONObject(configJson)
            if (json.has("min_app_version")) {
                val v = json.optString("min_app_version", "1.0.0")
                if (v.isNotBlank()) prefs.edit().putString("kiosk_min_app_version", v).apply()
            }
            if (json.has("features")) {
                val features = json.optJSONObject("features")
                features?.let {
                    if (it.has("siren_enabled")) {
                        SirenAlarmManager.isSirenEnabled = it.optBoolean("siren_enabled", true)
                    }
                    if (it.has("siren_max_volume")) {
                        SirenAlarmManager.isSirenMaxVolume = it.optBoolean("siren_max_volume", true)
                    }
                    if (it.has("block_clipboard")) {
                        val blockClipboard = it.optBoolean("block_clipboard", true)
                        prefs.edit().putBoolean("kiosk_block_clipboard", blockClipboard).apply()
                        securityManager.setClipboardGuard(blockClipboard)
                    }
                    if (it.has("root_detection_strictness")) {
                        val strictness = it.optString("root_detection_strictness", "warning")
                        if (strictness.isNotBlank()) prefs.edit().putString("kiosk_root_strictness", strictness).apply()
                    }
                }
            }

            if (!SirenAlarmManager.isSirenEnabled) {
                SirenAlarmManager.stopSiren()
            }

            // Persist config to SharedPreferences for offline resilience
            prefs.edit()
                .putBoolean("kiosk_siren_enabled", SirenAlarmManager.isSirenEnabled)
                .putBoolean("kiosk_siren_max_volume", SirenAlarmManager.isSirenMaxVolume)
                .apply()

            Log.d("MainActivity", "Applied kiosk config: sirenEnabled=${SirenAlarmManager.isSirenEnabled}, sirenMaxVolume=${SirenAlarmManager.isSirenMaxVolume}")

            // ---- Bundle UI: gate load WebView pada ketersediaan bundle ----
            val bundleInfo = json.optJSONObject("ui_bundle")
            val serverBundleVersion = bundleInfo?.optString("version") ?: ""
            if (serverBundleVersion.isBlank()) {
                Toast.makeText(this, "Bundle UI belum tersedia di server.", Toast.LENGTH_LONG).show()
                showSetupScreen()
                return
            }
            if (uiBundleManager.canRefresh() && uiBundleManager.localVersion() != serverBundleVersion) {
                val zipUrl = bundleInfo.optString("url")
                    .takeIf { it.isNotBlank() } ?: "$serverBaseUrl/ui-bundle/ui-bundle.zip"
                if (bundleDownloadActive) {
                    Toast.makeText(this, "Bundle sedang diunduh, harap tunggu...", Toast.LENGTH_LONG).show()
                    return
                }
                bundleDownloadActive = true
                setBundleStatus("Mengunduh bundle UI...")
                Toast.makeText(this, "Mengunduh bundle UI, harap tunggu...", Toast.LENGTH_LONG).show()
                uiBundleManager.downloadDirect(
                    zipUrl,
                    serverBundleVersion,
                    onProgress = { done, total ->
                        runOnUiThread {
                            if (::tvBundleStatus.isInitialized) {
                                tvBundleStatus.text = "Mengunduh bundle UI... ${(done * 100 / total)}%"
                            }
                        }
                    },
                    onDone = { ok, message ->
                        bundleDownloadActive = false
                        runOnUiThread {
                            if (ok) {
                                setBundleStatus("Bundle v${serverBundleVersion.take(8)} terpasang.")
                            } else {
                                setBundleStatus("Download gagal: $message")
                                Toast.makeText(this, "Gagal mengunduh bundle: $message", Toast.LENGTH_LONG).show()
                            }
                        }
                    }
                )
                // Lanjut ke WebView lewat onReady setelah verifyAndInstall selesai.
                return
            }
            // Versi bundle cocok (atau tidak bisa refresh karena exam aktif) → lanjut.
            proceedToBundleExam()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error parsing kiosk config JSON", e)
        }
    }

    private fun setBundleStatus(text: String) {
        runOnUiThread {
            if (::tvBundleStatus.isInitialized) {
                tvBundleStatus.text = text
            }
        }
    }

    public fun showSetupScreen() {
        runOnUiThread {
            try {
                SirenAlarmManager.stopSiren()
                setupLayout.visibility = View.VISIBLE
                examContainer.visibility = View.GONE
                webView.loadUrl("about:blank")
            } catch (e: Throwable) {
                Log.e("MainActivity", "Error showing setup screen", e)
            }
        }
    }

    /**
     * Bundle zip dibuka dari luar app (file manager / share) → import lalu lanjut exam flow.
     */
    private fun handleBundleIntent(intent: Intent?) {
        if (intent == null) return
        val data = intent.data ?: return
        val isZipMime = intent.type == "application/zip"
        val isZipPath = data.lastPathSegment?.endsWith(".zip") == true
        if ((isZipMime || isZipPath) && data != null) {
            if (uiBundleManager.importBundle(data)) {
                continueExamFlowAfterImport()
            }
        }
    }

    private fun continueExamFlowAfterImport() {
        val savedUrl = prefs.getString("server_url", "") ?: ""
        if (savedUrl.isNullOrBlank()) {
            Toast.makeText(this, "Bundle terpasang. Isi URL server lalu klik Mulai Ujian.", Toast.LENGTH_LONG).show()
            return
        }
        startExamAndLockKiosk(savedUrl)
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleBundleIntent(intent)
    }

    @Suppress("DEPRECATION")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == REQ_IMPORT_BUNDLE && resultCode == Activity.RESULT_OK) {
            data?.data?.let { uri ->
                if (uiBundleManager.importBundle(uri)) {
                    // onReady(true) → proceedToBundleExam (bila exam flow sudah diminta);
                    // tanpa itu, lanjut via Start / continueExamFlowAfterImport.
                    continueExamFlowAfterImport()
                }
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        try {
            // Bundle UI di-load dari origin lokal (appassets.androidplatform.net),
            // sementara fetch API pergi ke server: tanpa cookie third-party,
            // Cookie sesi (ci_session; SameSite=None) tidak pernah tersimpan →
            // semua request API terautentikasi gagal 401 setelah login sukses.
            CookieManager.getInstance().apply {
                setAcceptCookie(true)
                setAcceptThirdPartyCookies(webView, true)
            }

            val settings: WebSettings = webView.settings
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.setSupportMultipleWindows(false)

            webView.addJavascriptInterface(CommsBridge(this), "CommsBridge")

            webView.webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                    // Return true = block navigation to non-allowed hosts inside the kiosk.
                    val url = request?.url?.toString() ?: return false
                    return !isAllowedUrl(url)
                }

                override fun shouldInterceptRequest(view: WebView?, request: WebResourceRequest?): WebResourceResponse? {
                    val url = request?.url?.toString() ?: return null
                    if (!isAllowedUrl(url)) {
                        // Hard-block all cross-host subresources (scripts, iframes, images, exfil).
                        return WebResourceResponse("text/plain", "utf-8", ByteArrayInputStream("blocked".toByteArray()))
                    }
                    return null
                }
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error setting up webView", e)
        }
    }

    private fun isAllowedUrl(url: String): Boolean {
        if (url == "about:blank") return true
        if (url.startsWith("about:") || url.startsWith("data:")) return false
        val host = try { Uri.parse(url).host } catch (e: Throwable) { null } ?: return false
        val allowed = allowedHost ?: return false
        return host == allowed || host.endsWith(".$allowed")
    }

    private fun registerStatusReceivers() {
        try {
            batteryReceiver = object : BroadcastReceiver() {
                override fun onReceive(context: Context?, intent: Intent?) {
                    intent?.let {
                        val level = it.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                        val scale = it.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
                        val pct = if (level != -1 && scale != -1) (level * 100 / scale.toFloat()).toInt() else 0
                        val isCharging = it.getIntExtra(BatteryManager.EXTRA_STATUS, -1) == BatteryManager.BATTERY_STATUS_CHARGING
                        tvBatteryStatus.text = "$pct%"
                    }
                }
            }

            if (Build.VERSION.SDK_INT >= 33) {
                registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED), Context.RECEIVER_NOT_EXPORTED)
            } else {
                registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error registering battery receiver", e)
        }

        updateNetworkStatus()
    }

    private fun updateNetworkStatus() {
        try {
            val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            if (cm != null) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    val activeNetwork = cm.activeNetwork
                    val caps = cm.getNetworkCapabilities(activeNetwork)
                    if (caps != null) {
                        if (caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)) {
                            tvNetworkStatus.text = "WiFi"
                        } else if (caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)) {
                            tvNetworkStatus.text = "Seluler"
                        } else {
                            tvNetworkStatus.text = "Terhubung"
                        }
                    } else {
                        tvNetworkStatus.text = "Offline"
                    }
                } else {
                    @Suppress("DEPRECATION")
                    val networkInfo = cm.activeNetworkInfo
                    if (networkInfo != null && networkInfo.isConnected) {
                        tvNetworkStatus.text = networkInfo.typeName
                    } else {
                        tvNetworkStatus.text = "Offline"
                    }
                }
            } else {
                tvNetworkStatus.text = "--"
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error updating network status", e)
            tvNetworkStatus.text = "--"
        }
    }

    private fun unregisterStatusReceivers() {
        batteryReceiver?.let {
            try { unregisterReceiver(it) } catch (e: Throwable) {}
        }
    }

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
            // Trigger siren alarm if back button is pressed repeatedly in attempt to break out
            SirenAlarmManager.startSiren(this)
            Toast.makeText(this, getString(R.string.toast_kiosk_locked_warning), Toast.LENGTH_SHORT).show()
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
            SirenAlarmManager.startSiren(this)
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
            SirenAlarmManager.startSiren(this)
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
            // Trigger loud siren alarm when app goes to background / force exit attempt
            SirenAlarmManager.startSiren(this)
            if (::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "exit_attempt", "{}")
            }
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        try {
            // Tear down the kiosk (stops the heartbeat timer/worker via stopKiosk)
            // so it never outlives the activity. Guarded: normal exits already ran
            // stopKiosk, which cleared isKioskActive, so this does not double-stop.
            if (::kioskManager.isInitialized && kioskManager.isKioskActive) {
                kioskManager.stopKiosk()
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error stopping kiosk in onDestroy", e)
        }
    }
}