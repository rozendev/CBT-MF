package id.sch.cbt.kiosk

import android.Manifest
import android.annotation.SuppressLint
import android.app.role.RoleManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.BatteryManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
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
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.webkit.WebViewAssetLoader
import id.sch.cbt.kiosk.bridge.CommsBridge
import id.sch.cbt.kiosk.bundle.UiBundleManager
import id.sch.cbt.kiosk.kiosk.HeartbeatManager
import id.sch.cbt.kiosk.kiosk.HomeLauncherGuard
import id.sch.cbt.kiosk.security.OfflineExitAudit
import id.sch.cbt.kiosk.security.OfflineExitCode
import id.sch.cbt.kiosk.kiosk.KioskGuardService
import id.sch.cbt.kiosk.kiosk.KioskManager
import id.sch.cbt.kiosk.kiosk.KioskOverlay
import id.sch.cbt.kiosk.security.DndGuard
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
    private lateinit var btnTestBeep: Button
    private lateinit var btnUpdateBundle: Button
    private lateinit var tvBatteryStatus: TextView
    private lateinit var tvNetworkStatus: TextView
    private lateinit var tvBundleStatus: TextView
    private lateinit var btnReloadPage: ImageButton
    private lateinit var btnExitKiosk: ImageButton
    private lateinit var prefs: SharedPreferences
    private var restoreHomeDialog: AlertDialog? = null

    private var batteryReceiver: BroadcastReceiver? = null

    // Only this host (and its subdomains) may be loaded inside the WebView.
    private var allowedHost: String? = null

    private var pendingBundleBaseUrl: String? = null
    private var examFlowRequested = false
    private var bundleFlowStarted = false
    private var bundleDownloadActive = false
    private var serverPolicyResolved = false
    private var overlayWaivedForCurrentSession = false
    private var dndWaivedForCurrentSession = false
    private var activeBlockDialog: AlertDialog? = null

    private val policySettingsLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) {
        continueExamAfterResolvedPolicies()
    }

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (!granted) {
            Log.w("MainActivity", "Izin notifikasi ditolak; foreground guard tetap dijalankan")
        }
    }

    private val bundleImportLauncher = registerForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri ->
        if (uri != null && ::uiBundleManager.isInitialized && uiBundleManager.importBundle(uri)) {
            continueExamFlowAfterImport()
        }
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

            // Aplikasi yang dibunuh di tengah ujian tidak pernah sampai ke
            // disableSecurityFlags(), jadi perangkatnya tertinggal senyap TANPA
            // ada lagi yang akan mengembalikannya. Filter aslinya sengaja
            // disimpan ke prefs sebelum dipasang, dan di sinilah ia ditebus.
            try {
                DndGuard.restore(this, prefs)
            } catch (e: Throwable) {
                Log.e("MainActivity", "Gagal memulihkan filter DND yang tertinggal", e)
            }

            uiBundleManager = UiBundleManager(
                this,
                prefs,
                onReady = { ready ->
                    if (ready) {
                        runOnUiThread { continueExamAfterResolvedPolicies() }
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
                HeartbeatManager(
                    activity = this,
                    onUnauthorized = {
                        getSafeWebView()?.let {
                            CommsBridge.sendEventToJS(
                                it,
                                "kiosk_failed",
                                "{\"error\": \"Sesi kiosk tidak valid (401)\"}"
                            )
                        }
                        showActiveSessionBlocked(getString(R.string.session_invalid_body))
                    },
                    onDeviceBanned = { reason ->
                        showActiveSessionBlocked(
                            buildString {
                                append(getString(R.string.device_blocked_body))
                                if (reason.isNotBlank()) {
                                    append("\n\n")
                                    append(getString(R.string.device_blocked_reason_prefix))
                                    append(' ')
                                    append(reason)
                                }
                            }
                        )
                    },
                )
            )

            // Ensure the device id exists up front so the first heartbeat is never blank.
            getOrCreateDeviceId()

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
            ) {
                notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }

            setupLayout = findViewById(R.id.setupLayout)
            examContainer = findViewById(R.id.examContainer)
            etServerUrl = findViewById(R.id.etServerUrl)
            btnStartExam = findViewById(R.id.btnStartExam)
            btnImportBundle = findViewById(R.id.btnImportBundle)
            btnTestBeep = findViewById(R.id.btnTestBeep)
            btnUpdateBundle = findViewById(R.id.btnUpdateBundle)
            webView = findViewById(R.id.webView)
            tvBatteryStatus = findViewById(R.id.tvBatteryStatus)
            tvNetworkStatus = findViewById(R.id.tvNetworkStatus)
            tvBundleStatus = findViewById(R.id.tvBundleStatus)
            btnReloadPage = findViewById(R.id.btnReloadPage)
            btnExitKiosk = findViewById(R.id.btnExitKiosk)

            // AppCompatActivity modern menyalurkan back lewat dispatcher ini,
            // bukan lagi selalu ke onBackPressed().
            onBackPressedDispatcher.addCallback(this, object : androidx.activity.OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    handleBackAttempt()
                }
            })

            // Pengawas bisa memastikan bunyi peringatan terdengar SEBELUM ujian
            // dimulai, tanpa harus masuk kiosk lebih dulu.
            btnTestBeep.setOnClickListener {
                SirenAlarmManager.playWarningBeep(this)
                Toast.makeText(this, getString(R.string.toast_test_beep), Toast.LENGTH_SHORT).show()
            }

            setupWebView()
            setupToolbarListeners()

            btnImportBundle.setOnClickListener {
                try {
                    bundleImportLauncher.launch(arrayOf("application/zip", "application/octet-stream"))
                } catch (e: Throwable) {
                    Toast.makeText(this, "Tidak ada aplikasi pemilih file.", Toast.LENGTH_LONG).show()
                }
            }

            // Load saved URL from preferences
            val savedUrl = prefs.getString("server_url", "")
            if (!savedUrl.isNullOrBlank()) {
                etServerUrl.setText(savedUrl)
                fetchServerKioskConfig(savedUrl)
            }

            btnUpdateBundle.setOnClickListener {
                val inputUrl = etServerUrl.text.toString().trim()
                if (inputUrl.isEmpty()) {
                    Toast.makeText(this, getString(R.string.toast_bundle_need_url), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                checkAndUpdateBundle(normalizeServerUrl(inputUrl))
            }

            // "Mulai Ujian" mati selama alamat server kosong: menekannya tanpa
            // URL tidak pernah bisa berhasil, jadi lebih jujur dimatikan
            // daripada memunculkan toast galat setiap kali ditekan.
            etServerUrl.addTextChangedListener(object : android.text.TextWatcher {
                override fun beforeTextChanged(c: CharSequence?, a: Int, b: Int, d: Int) {}
                override fun onTextChanged(c: CharSequence?, a: Int, b: Int, d: Int) {}
                override fun afterTextChanged(e: android.text.Editable?) { syncSetupButtons() }
            })
            syncSetupButtons()

            btnStartExam.setOnClickListener {
                val inputUrl = etServerUrl.text.toString().trim()
                if (inputUrl.isEmpty()) {
                    Toast.makeText(this, getString(R.string.toast_url_empty), Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                if (uiBundleManager.localVersion() == null) {
                    Toast.makeText(this, getString(R.string.toast_bundle_missing), Toast.LENGTH_LONG).show()
                    return@setOnClickListener
                }

                val finalUrl = normalizeServerUrl(inputUrl)
                // Beri tahu HANYA kalau pengalihan benar-benar terjadi. Di build
                // debug http:// dibiarkan, jadi toast ini pun tidak muncul —
                // pesannya tetap jujur terhadap apa yang sungguh dilakukan.
                if (inputUrl.startsWith("http://") && finalUrl.startsWith("https://")) {
                    Toast.makeText(this, getString(R.string.toast_https_redirect), Toast.LENGTH_LONG).show()
                }

                if (!enforceDevicePolicy()) return@setOnClickListener
                beginExam(finalUrl)
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error in onCreate", e)
        }
    }

    private fun beginExam(finalUrl: String) {
        overlayWaivedForCurrentSession = false
        dndWaivedForCurrentSession = false
        serverPolicyResolved = false
        prefs.edit().putString("server_url", finalUrl).apply()
        startExamAndLockKiosk(finalUrl)
    }

    /**
     * Gerbang izin overlay. Sengaja TIDAK memblokir mutlak: sebagian ROM
     * menyembunyikan atau menolak izin ini, dan menahan ujian karenanya menukar
     * satu kegagalan dengan kegagalan yang lebih buruk. Yang dijamin di sini
     * adalah keputusannya jadi sadar dan tercatat — pengawas memilihnya, dan
     * status perangkatnya ikut terkirim di heartbeat.
     */
    private fun showOverlayPermissionDialog() {
        try {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.overlay_perm_title))
                .setMessage(getString(R.string.overlay_perm_message))
                .setCancelable(false)
                .setPositiveButton(getString(R.string.overlay_perm_open)) { _, _ ->
                    openOverlaySettings()
                }
                .setNegativeButton(getString(R.string.overlay_perm_skip)) { _, _ ->
                    overlayWaivedForCurrentSession = true
                    Toast.makeText(this, getString(R.string.overlay_perm_skipped), Toast.LENGTH_LONG).show()
                    continueExamAfterResolvedPolicies()
                }
                .show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Gagal menampilkan dialog izin overlay", e)
            showSetupScreen()
        }
    }

    private fun openOverlaySettings() {
        try {
            policySettingsLauncher.launch(
                Intent(
                    Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                    Uri.parse("package:$packageName")
                )
            )
        } catch (e: Throwable) {
            Log.e("MainActivity", "Tidak dapat membuka pengaturan izin overlay", e)
            Toast.makeText(this, getString(R.string.overlay_perm_no_settings), Toast.LENGTH_LONG).show()
            showOverlayPermissionDialog()
        }
    }

    /**
     * Gerbang Do Not Disturb. Sama seperti gerbang overlay: TIDAK memblokir
     * mutlak. Notification Policy Access tidak tersedia seragam di semua ROM,
     * dan menahan siswa dari ujiannya karena itu adalah ongkos yang lebih mahal
     * daripada notifikasi yang lolos. Yang dijamin: keputusannya sadar, dan
     * perangkat yang dilewati ikut terlihat di monitoring lewat heartbeat.
     */
    private fun showDndPermissionDialog() {
        try {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.dnd_perm_title))
                .setMessage(getString(R.string.dnd_perm_message))
                .setCancelable(false)
                .setPositiveButton(getString(R.string.dnd_perm_open)) { _, _ ->
                    openDndSettings()
                }
                .setNegativeButton(getString(R.string.dnd_perm_skip)) { _, _ ->
                    dndWaivedForCurrentSession = true
                    Toast.makeText(this, getString(R.string.dnd_perm_skipped), Toast.LENGTH_LONG).show()
                    continueExamAfterResolvedPolicies()
                }
                .show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Gagal menampilkan dialog izin DND", e)
            showSetupScreen()
        }
    }

    /**
     * Layar special access ini paling bervariasi antar-ROM, jadi intent-nya
     * dicoba berjenjang sampai ada yang mau terbuka.
     */
    private fun openDndSettings() {
        for (action in DndGuard.settingsIntents()) {
            try {
                policySettingsLauncher.launch(Intent(action))
                return
            } catch (e: Throwable) {
                Log.w("MainActivity", "Intent $action tidak tersedia di ROM ini", e)
            }
        }
        Toast.makeText(this, getString(R.string.dnd_perm_no_settings), Toast.LENGTH_LONG).show()
        dndWaivedForCurrentSession = true
        continueExamAfterResolvedPolicies()
    }

    /** Lanjut hanya setelah konfigurasi server dan seluruh policy wajib terpenuhi. */
    private fun continueExamAfterResolvedPolicies() {
        if (!examFlowRequested || !serverPolicyResolved) return

        if (!enforceDevicePolicy()) {
            showSetupScreen()
            return
        }

        if (prefs.getBoolean("kiosk_enforce_home_launcher", true) && !isDefaultHomeLauncher()) {
            showHomeLauncherDialog()
            return
        }

        if (DndGuard.needsPermissionPrompt(
                policyEnabled = prefs.getBoolean("kiosk_enforce_dnd", true),
                waived = dndWaivedForCurrentSession,
                granted = DndGuard.isGranted(this)
            )
        ) {
            showDndPermissionDialog()
            return
        }

        val overlayRequired = prefs.getBoolean("kiosk_overlay_guard_enabled", true)
        if (overlayRequired && !overlayWaivedForCurrentSession && !KioskOverlay.isGranted(this)) {
            showOverlayPermissionDialog()
            return
        }

        proceedToBundleExam()
    }

    private fun isDefaultHomeLauncher(): Boolean {
        return try {
            val homeIntent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
            val resolved = packageManager.resolveActivity(homeIntent, PackageManager.MATCH_DEFAULT_ONLY)
            resolved?.activityInfo?.packageName == packageName
        } catch (e: Throwable) {
            Log.e("MainActivity", "Tidak dapat membaca launcher HOME aktif", e)
            false
        }
    }

    private fun showHomeLauncherDialog() {
        try {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.home_launcher_title))
                .setMessage(getString(R.string.home_launcher_message))
                .setCancelable(false)
                .setPositiveButton(getString(R.string.home_launcher_open)) { _, _ ->
                    openHomeLauncherSettings()
                }
                .setNegativeButton(getString(R.string.exit_dialog_cancel)) { _, _ ->
                    showSetupScreen()
                }
                .show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Gagal menampilkan dialog HOME launcher", e)
            showSetupScreen()
        }
    }

    private fun openHomeLauncherSettings() {
        try {
            val intent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val roleManager = getSystemService(RoleManager::class.java)
                if (roleManager.isRoleAvailable(RoleManager.ROLE_HOME) &&
                    !roleManager.isRoleHeld(RoleManager.ROLE_HOME)
                ) {
                    roleManager.createRequestRoleIntent(RoleManager.ROLE_HOME)
                } else {
                    Intent(Settings.ACTION_HOME_SETTINGS)
                }
            } else {
                Intent(Settings.ACTION_HOME_SETTINGS)
            }
            policySettingsLauncher.launch(intent)
        } catch (e: Throwable) {
            Log.e("MainActivity", "Tidak dapat membuka pengaturan HOME launcher", e)
            Toast.makeText(this, getString(R.string.home_launcher_no_settings), Toast.LENGTH_LONG).show()
            showSetupScreen()
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
            // Beri tahu pengawas bahwa kolom ini juga menerima kode offline —
            // hanya saat amplopnya memang ada, supaya tidak menjanjikan jalur
            // yang server-nya tidak menyalakan.
            input.hint = if (OfflineExitCode.storedEnvelope(this).enabled) {
                getString(R.string.exit_dialog_hint_with_code)
            } else {
                getString(R.string.exit_dialog_hint)
            }
            builder.setView(input)

            builder.setPositiveButton(getString(R.string.exit_dialog_confirm)) { dialog, _ ->
                val enteredPassword = input.text.toString().trim()
                verifyExitPassword(enteredPassword) { allowed, message ->
                    runOnUiThread {
                        if (allowed) {
                            activeBlockDialog?.dismiss()
                            activeBlockDialog = null
                            SirenAlarmManager.stopSiren()
                            kioskManager.stopKiosk()
                            // Pesan datang dari callback: jalur offline melaporkan
                            // dirinya sendiri, jalur server tidak mengirim pesan sukses.
                            Toast.makeText(this, message ?: getString(R.string.toast_kiosk_unlocked), Toast.LENGTH_SHORT).show()
                        } else {
                            // Salah password itu percobaan, bukan pelolosan.
                            SirenAlarmManager.playWarningBeep(this)
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

    /**
     * Penanda perangkat. Diturunkan dari ANDROID_ID supaya bertahan melewati
     * hapus data aplikasi dan pasang ulang — UUID per-pemasangan yang dulu
     * dipakai lenyap begitu data dihapus, friction yang terlalu murah untuk
     * aplikasi yang memang biasa dipasang ulang.
     *
     * Hasilnya di-cache di prefs supaya tidak dihitung ulang tiap heartbeat.
     * Kuncinya dibedakan per skema (v2): perangkat yang sudah menyimpan UUID
     * lama harus naik ke penanda baru, bukan terus memakai yang lama.
     */
    /**
     * Penanda perangkat. Seluruh logikanya ada di [DeviceIdentityStore] supaya
     * hanya ada SATU tempat yang tahu cara mendapatkannya — HeartbeatManager
     * memakai sumber yang sama, sehingga heartbeat dan /api/kiosk/config tidak
     * bisa lagi memeriksa identitas yang berbeda.
     */
    private fun getOrCreateDeviceId(): String = DeviceIdentityStore.resolve(this)

    /**
     * Layar akhir untuk perangkat yang diblokir. Tidak ada tombol coba lagi:
     * ini keputusan pengawas, bukan gangguan jaringan, dan tombol coba lagi
     * hanya mengundang siswa menekannya berkali-kali.
     */
    private fun showDeviceBlockedScreen(schoolName: String, reason: String) {
        runOnUiThread {
            try {
                webView.loadUrl("about:blank")
                webView.visibility = android.view.View.GONE
            } catch (e: Throwable) {
                Log.w("MainActivity", "Gagal menyembunyikan WebView", e)
            }

            val pesan = buildString {
                append(getString(R.string.device_blocked_body))
                if (reason.isNotBlank()) {
                    append("\n\n")
                    append(getString(R.string.device_blocked_reason_prefix))
                    append(' ')
                    append(reason)
                }
            }

            AlertDialog.Builder(this)
                .setTitle(if (schoolName.isBlank()) getString(R.string.device_blocked_title) else schoolName)
                .setMessage(pesan)
                .setCancelable(false)
                .setPositiveButton(R.string.device_blocked_close) { _, _ -> finishAffinity() }
                .show()
        }
    }

    /**
     * Heartbeat 401/403 di tengah ujian adalah keputusan keamanan, bukan outage.
     * Lock-task tetap hidup dan halaman ujian disembunyikan sampai pengawas
     * membuka sesi memakai password server.
     */
    private fun showActiveSessionBlocked(message: String) {
        runOnUiThread {
            if (!::kioskManager.isInitialized || !kioskManager.isSessionActive) return@runOnUiThread

            try {
                webView.loadUrl("about:blank")
                webView.visibility = View.GONE
            } catch (e: Throwable) {
                Log.w("MainActivity", "Gagal menyembunyikan WebView pada sesi terblokir", e)
            }

            activeBlockDialog?.dismiss()
            val dialog = AlertDialog.Builder(this)
                .setTitle(getString(R.string.session_blocked_title))
                .setMessage(message)
                .setCancelable(false)
                .setPositiveButton(getString(R.string.session_blocked_proctor_unlock), null)
                .create()
            dialog.setOnShowListener {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
                    showExitPasswordDialog()
                }
            }
            activeBlockDialog = dialog
            dialog.show()
        }
    }

    /**
     * Verify the proctor password against the server (rate-limited there).
     * The password is never stored on the device.
     */
    private fun verifyExitPassword(password: String, callback: (Boolean, String?) -> Unit) {
        val baseUrl = prefs.getString("server_url", "") ?: ""
        if (password.isBlank()) {
            callback(false, getString(R.string.toast_password_empty))
            return
        }

        // Kode offline dicocokkan LEBIH DULU, sengaja. Jalur ini justru dipakai
        // saat server tak terjangkau; mendahulukan server berarti pengawas
        // menunggu dua kali timeout 8 detik sebelum kode yang benar diterima —
        // persis pada momen paling menegangkan. Gerbang "8 digit" di dalam
        // OfflineExitCode membuat percobaan password biasa tidak membayar PBKDF2.
        val offlineDay = OfflineExitCode.attempt(this, password)
        if (offlineDay != null) {
            OfflineExitCode.clearFailures(this)
            OfflineExitAudit.record(this, offlineDay, BuildConfig.VERSION_NAME)
            OfflineExitAudit.flush(this, baseUrl, getOrCreateDeviceId())
            callback(true, getString(R.string.toast_kiosk_unlocked_offline))
            return
        }

        if (baseUrl.isBlank()) {
            OfflineExitCode.recordFailure(this)
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

                if (allowed) OfflineExitCode.clearFailures(this) else OfflineExitCode.recordFailure(this)
                callback(allowed, message ?: getString(R.string.toast_wrong_password))
            } catch (e: Throwable) {
                Log.e("MainActivity", "Error verifying exit password", e)
                OfflineExitCode.recordFailure(this)
                callback(false, getString(R.string.toast_verify_failed))
            }
        }
    }

    /** Header HTTP Date (RFC 1123, selalu GMT) → tanggal YYYY-MM-DD di zona sekolah. */
    private fun serverDayFromHeader(dateHeader: String?): String? {
        if (dateHeader.isNullOrBlank()) return null
        return try {
            val parser = java.text.SimpleDateFormat("EEE, dd MMM yyyy HH:mm:ss zzz", java.util.Locale.US)
            parser.timeZone = java.util.TimeZone.getTimeZone("GMT")
            val parsed = parser.parse(dateHeader) ?: return null
            OfflineExitCode.dayOf(parsed.time)
        } catch (e: Throwable) {
            Log.w("MainActivity", "Header Date tidak terbaca: $dateHeader", e)
            null
        }
    }

    /**
     * Called from JS when the page believes the exam is finished.
     * The native app asks the server whether the locked attempt is
     * genuinely finished and only then unlocks the kiosk.
     */
    fun handleKioskExitRequest(token: String) {
        if (!kioskManager.isSessionActive) return

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

    /** Beri tahu halaman ujian bahwa ada percobaan keluar (untuk dicatat server). */
    private fun notifyExitAttempt(reason: String) {
        getSafeWebView()?.let {
            CommsBridge.sendEventToJS(it, "exit_attempt", "{\"reason\": \"$reason\"}")
        }
    }

    private fun triggerDeniedExit(reason: String) {
        SirenAlarmManager.playWarningBeep(this)
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
            val finalUrl = normalizeServerUrl(url)

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
        // Dipasang SEBELUM startKiosk: permintaan pin dikirim di dalamnya, dan
        // jawabannya bisa datang kapan saja sesudah itu.
        kioskManager.onLockTaskConfirmed = {
            runOnUiThread {
                Toast.makeText(this, getString(R.string.toast_kiosk_locked), Toast.LENGTH_LONG).show()
                val detail = org.json.JSONObject()
                    .put("examId", kioskManager.currentExamId)
                    .put("status", "active")
                    .toString()
                CommsBridge.sendEventToJS(webView, "kiosk_started", detail)
            }
        }
        kioskManager.onLockTaskRefused = { isFinal ->
            runOnUiThread { handleLockTaskRefused(isFinal) }
        }

        val started = kioskManager.startKiosk("EXAM_SESSION", "TOKEN")
        if (!started) {
            Toast.makeText(this, getString(R.string.toast_kiosk_failed), Toast.LENGTH_LONG).show()
            CommsBridge.sendEventToJS(webView, "kiosk_failed", "{\"error\": \"LOCK_TASK_FAILED\"}")
            return
        }

        // TIDAK mengatakan "terkunci" di sini. Sesi memang dimulai, tapi pinnya
        // belum tentu terpasang — dialog sistem punya tombol "No thanks", dan
        // klaim prematur di titik inilah yang dulu membuat ujian tanpa kunci
        // terlihat normal bagi semua orang.
        Toast.makeText(this, getString(R.string.toast_kiosk_waiting_lock), Toast.LENGTH_SHORT).show()
    }

    /**
     * Penguncian ditolak atau tidak terpasang. Tidak bisa dicegah tanpa Device
     * Owner — "No thanks" adalah pilihan sah yang diberikan Android — jadi yang
     * dijamin di sini adalah ia tidak bisa lolos tanpa ketahuan.
     */
    private fun handleLockTaskRefused(isFinal: Boolean) {
        SirenAlarmManager.playWarningBeep(this)

        if (!isFinal) {
            try {
                AlertDialog.Builder(this)
                    .setTitle(getString(R.string.pin_refused_title))
                    .setMessage(getString(R.string.pin_refused_retry_message))
                    .setCancelable(false)
                    .setPositiveButton(getString(R.string.pin_refused_retry)) { _, _ ->
                        kioskManager.requestLockTask()
                    }
                    .show()
            } catch (e: Throwable) {
                Log.e("MainActivity", "Gagal menampilkan dialog coba-lagi pin", e)
                kioskManager.requestLockTask()
            }
            return
        }

        // Percobaan habis: ujian dihentikan dan halamannya dilepas, bukan
        // sekadar diberi peringatan.
        CommsBridge.sendEventToJS(webView, "kiosk_failed", "{\"error\": \"LOCK_TASK_REFUSED\"}")
        kioskManager.stopKiosk()
        try {
            AlertDialog.Builder(this)
                .setTitle(getString(R.string.pin_refused_title))
                .setMessage(getString(R.string.pin_refused_final_message))
                .setCancelable(false)
                .setPositiveButton(getString(R.string.pin_refused_close)) { d, _ -> d.dismiss() }
                .show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Gagal menampilkan dialog pin final", e)
            Toast.makeText(this, getString(R.string.pin_refused_final_message), Toast.LENGTH_LONG).show()
        }
    }

    /**
     * SATU-SATUNYA tempat kebijakan skema URL server ditegakkan. Dulu logika
     * yang sama disalin di empat tempat dengan bentuk yang sedikit berbeda —
     * kebijakan keamanan yang diduplikasi adalah kebijakan yang cepat atau
     * lambat menyimpang di salah satu salinannya.
     *
     * Rilis: HTTP polos SELALU ditulis ulang jadi HTTPS. Cleartext di jaringan
     * sekolah berarti jawaban ujian dan cookie sesi bisa dibaca dan diubah
     * siapa pun yang satu WiFi.
     *
     * Debug: alamat http:// dibiarkan apa adanya supaya perangkat uji bisa
     * menunjuk stack lokal yang tidak punya sertifikat. [BuildConfig.DEBUG]
     * adalah konstanta compile-time — di varian rilis cabang ini dibuang
     * compiler, sehingga tidak ada cara menghidupkannya dari luar: tidak lewat
     * konfigurasi server, tidak lewat prefs, tidak lewat intent. Izin cleartext
     * yang menyertainya pun hanya ada di src/debug/AndroidManifest.xml, yang
     * tidak pernah ikut ke build rilis.
     */
    private fun normalizeServerUrl(raw: String): String {
        val url = raw.trim().trimEnd('/')
        if (BuildConfig.DEBUG && url.startsWith("http://")) return url
        if (url.startsWith("http://")) return "https://" + url.removePrefix("http://")
        if (!url.startsWith("https://")) return "https://$url"
        return url
    }

    private fun syncSetupButtons() {
        val hasUrl = etServerUrl.text.toString().trim().isNotEmpty()
        btnStartExam.isEnabled = hasUrl
        btnStartExam.alpha = if (hasUrl) 1f else 0.45f
        btnUpdateBundle.isEnabled = hasUrl
        btnUpdateBundle.alpha = if (hasUrl) 1f else 0.45f

        val local = uiBundleManager.localVersion()
        if (local == null) {
            setBundleStatus("Bundle UI belum terpasang — tekan \"Update UI\".")
        }
    }

    /**
     * Pembaruan bundle atas permintaan pengguna, bukan otomatis tiap membuka
     * aplikasi. Pendekatan lama mengunduh ulang setiap kali versi lokal tidak
     * cocok dengan versi server, dan itu berulang tanpa henti begitu unduhan
     * menghasilkan bundle yang bukan versi terbaru — persis yang terjadi di
     * lapangan: mengunduh terus, versinya tidak pernah maju.
     */
    private fun checkAndUpdateBundle(baseUrl: String) {
        if (bundleDownloadActive) {
            Toast.makeText(this, "Pembaruan sedang berjalan, harap tunggu...", Toast.LENGTH_SHORT).show()
            return
        }
        prefs.edit().putString("server_url", baseUrl).apply()
        setBundleStatus("Memeriksa versi bundle...")
        btnUpdateBundle.isEnabled = false

        kotlin.concurrent.thread(start = true, isDaemon = true, name = "BundleUpdateCheck") {
            var conn: java.net.HttpURLConnection? = null
            try {
                conn = (java.net.URL("$baseUrl/api/kiosk/config").openConnection() as java.net.HttpURLConnection).apply {
                    requestMethod = "GET"
                    connectTimeout = 8000
                    readTimeout = 8000
                    setRequestProperty("Accept", "application/json")
                    // Config TIDAK boleh dijawab dari cache: nomor versinya
                    // justru yang sedang kita tanyakan.
                    setRequestProperty("Cache-Control", "no-cache")
                }
                if (conn.responseCode != 200) {
                    runOnUiThread {
                        btnUpdateBundle.isEnabled = true
                        setBundleStatus("Gagal memeriksa versi (HTTP ${conn?.responseCode}).")
                    }
                    return@thread
                }

                val json = org.json.JSONObject(conn.inputStream.bufferedReader().use { it.readText() })
                val info = json.optJSONObject("ui_bundle")
                val serverVersion = info?.optString("version") ?: ""
                if (serverVersion.isBlank()) {
                    runOnUiThread {
                        btnUpdateBundle.isEnabled = true
                        setBundleStatus("Server belum menyediakan bundle UI.")
                    }
                    return@thread
                }

                val localVersion = uiBundleManager.localVersion()
                if (localVersion == serverVersion) {
                    runOnUiThread {
                        btnUpdateBundle.isEnabled = true
                        setBundleStatus("Bundle v${serverVersion.take(8)} — sudah terbaru.")
                        Toast.makeText(this, getString(R.string.toast_bundle_up_to_date), Toast.LENGTH_SHORT).show()
                    }
                    return@thread
                }

                val zipUrl = info?.optString("url")?.takeIf { it.isNotBlank() }
                    ?: "$baseUrl/ui-bundle/ui-bundle.zip"

                // Sidik jari zip resmi, datang lewat HTTPS di luar zip itu sendiri.
                // Dipin supaya side-load manual (offline) tetap punya pembanding.
                val serverSha = info?.optString("sha256")?.takeIf { it.isNotBlank() }
                if (serverSha == null) {
                    runOnUiThread {
                        btnUpdateBundle.isEnabled = true
                        setBundleStatus("Server belum mengirim sidik jari bundle — perbarui server.")
                    }
                    return@thread
                }
                uiBundleManager.expectedZipSha = serverSha

                runOnUiThread {
                    bundleDownloadActive = true
                    setBundleStatus("Mengunduh bundle v${serverVersion.take(8)}...")
                }
                uiBundleManager.downloadDirect(
                    zipUrl,
                    serverVersion,
                    serverSha,
                    onProgress = { done, total ->
                        runOnUiThread { setBundleStatus("Mengunduh bundle UI... ${(done * 100 / total)}%") }
                    },
                    onDone = { ok, message ->
                        bundleDownloadActive = false
                        runOnUiThread {
                            btnUpdateBundle.isEnabled = true
                            val installed = uiBundleManager.localVersion()
                            when {
                                !ok -> setBundleStatus("Update gagal: $message")
                                // Unduhan berhasil tapi isinya versi lain =
                                // yang datang bukan berkas terbaru (mis. masih
                                // tertahan cache CDN). Katakan apa adanya.
                                installed != serverVersion ->
                                    setBundleStatus("Server memberi v${installed?.take(8)}, bukan v${serverVersion.take(8)}. Coba lagi sebentar.")
                                else -> {
                                    setBundleStatus("Bundle v${serverVersion.take(8)} terpasang.")
                                    Toast.makeText(this, "Bundle UI diperbarui.", Toast.LENGTH_SHORT).show()
                                }
                            }
                            syncSetupButtons()
                        }
                    }
                )
            } catch (e: Throwable) {
                Log.e("MainActivity", "Bundle update check gagal", e)
                runOnUiThread {
                    btnUpdateBundle.isEnabled = true
                    setBundleStatus("Gagal terhubung ke server.")
                }
            } finally {
                try { conn?.disconnect() } catch (_: Throwable) {}
            }
        }
    }

    fun fetchServerKioskConfig(serverUrl: String) {
        if (serverUrl.isBlank()) return
        kotlin.concurrent.thread(start = true, isDaemon = true, name = "KioskConfigFetcher") {
            try {
                val baseUrl = normalizeServerUrl(serverUrl)
                // device_id dikirim supaya server bisa menjawab blocked SEBELUM
                // WebView dijalankan — itulah yang membuat halaman ujian benar-
                // benar tidak termuat, bukan sekadar ditolak setelah tampil.
                val configUrl = "$baseUrl/api/kiosk/config?device_id=" + Uri.encode(getOrCreateDeviceId())

                Log.d("MainActivity", "Fetching kiosk config from: $configUrl")
                val url = java.net.URL(configUrl)
                val connection = url.openConnection() as java.net.HttpURLConnection
                connection.requestMethod = "GET"
                connection.connectTimeout = 5000
                connection.readTimeout = 5000
                connection.setRequestProperty("Accept", "application/json")

                val responseCode = connection.responseCode
                // Header Date adalah satu-satunya sumber waktu tepercaya yang
                // dimiliki perangkat. Ia yang menutup trik memundurkan jam ke
                // tanggal yang kodenya terlanjur bocor.
                OfflineExitCode.rememberServerDay(this, serverDayFromHeader(connection.getHeaderField("Date")))
                if (responseCode == 200) {
                    val jsonString = connection.inputStream.bufferedReader().use { it.readText() }
                    runOnUiThread {
                        applyKioskConfig(jsonString, baseUrl)
                    }
                    OfflineExitAudit.flush(this, baseUrl, getOrCreateDeviceId())
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

            // Perangkat terblokir: berhenti di sini. WebView tidak pernah
            // dijalankan, jadi halaman ujian benar-benar tidak termuat.
            val blocked = json.optJSONObject("blocked")
            if (blocked != null) {
                showDeviceBlockedScreen(
                    json.optString("school_name", ""),
                    blocked.optString("reason", "")
                )
                return
            }

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
                    if (it.has("enforce_home_launcher")) {
                        prefs.edit()
                            .putBoolean("kiosk_enforce_home_launcher", it.optBoolean("enforce_home_launcher", true))
                            .apply()
                    }
                    if (it.has("root_detection_strictness")) {
                        val strictness = it.optString("root_detection_strictness", "warning")
                        if (strictness.isNotBlank()) prefs.edit().putString("kiosk_root_strictness", strictness).apply()
                    }
                    if (it.has("enforce_home_launcher")) {
                        prefs.edit()
                            .putBoolean("kiosk_enforce_home_launcher", it.optBoolean("enforce_home_launcher", true))
                            .apply()
                    }
                    if (it.has("overlay_guard_enabled")) {
                        prefs.edit()
                            .putBoolean("kiosk_overlay_guard_enabled", it.optBoolean("overlay_guard_enabled", true))
                            .apply()
                    }
                    if (it.has("enforce_dnd")) {
                        prefs.edit()
                            .putBoolean("kiosk_enforce_dnd", it.optBoolean("enforce_dnd", true))
                            .apply()
                    }
                }
            }

            // Toggle mati dikirim eksplisit oleh server, sehingga mematikannya
            // di admin benar-benar mencabut amplop dari perangkat — blok yang
            // hilang tidak bisa dibedakan dari respons server versi lama.
            val offlineExit = json.optJSONObject("offline_exit")
            if (offlineExit != null) {
                if (offlineExit.optBoolean("enabled", false)) {
                    OfflineExitCode.storeEnvelope(this, offlineExit.toString())
                } else {
                    OfflineExitCode.storeEnvelope(this, null)
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

            serverPolicyResolved = true
            Log.d(
                "MainActivity",
                "Applied kiosk config: sirenEnabled=${SirenAlarmManager.isSirenEnabled}, " +
                    "sirenMaxVolume=${SirenAlarmManager.isSirenMaxVolume}, " +
                    "homeLauncher=${prefs.getBoolean("kiosk_enforce_home_launcher", true)}, " +
                    "overlayGuard=${prefs.getBoolean("kiosk_overlay_guard_enabled", true)}, " +
                    "enforceDnd=${prefs.getBoolean("kiosk_enforce_dnd", true)}"
            )

            // ---- Bundle UI ----
            // Memulai ujian TIDAK lagi mengunduh apa pun. Dulu setiap tekan
            // "Mulai Ujian" membandingkan versi lokal dengan versi server dan
            // mengunduh ulang bila beda — yang berulang tanpa henti kalau
            // berkas yang datang bukan versi terbaru, sekaligus menahan siswa
            // di layar tunggu tepat sebelum ujian. Pembaruan sekarang urusan
            // tombol "Update UI" di layar setup, dijalankan pengawas sebelum
            // ujian dimulai.
            val localBundleVersion = uiBundleManager.localVersion()
            if (localBundleVersion == null) {
                Toast.makeText(this, getString(R.string.toast_bundle_missing), Toast.LENGTH_LONG).show()
                showSetupScreen()
                return
            }

            val bundleInfo = json.optJSONObject("ui_bundle")
            // Pin sidik jari resmi setiap kali config dibaca: inilah satu-satunya
            // kesempatan perangkat mendapat pembanding tepercaya sebelum offline.
            bundleInfo?.optString("sha256")?.takeIf { it.isNotBlank() }?.let {
                uiBundleManager.expectedZipSha = it
            }
            val serverBundleVersion = bundleInfo?.optString("version") ?: ""
            if (serverBundleVersion.isNotBlank() && serverBundleVersion != localBundleVersion) {
                // Sekadar pemberitahuan; ujian tetap boleh jalan dengan bundle
                // yang ada supaya jaringan bermasalah tidak membatalkan ujian.
                setBundleStatus("Bundle v${localBundleVersion.take(8)} — tersedia v${serverBundleVersion.take(8)}.")
            } else {
                setBundleStatus("Bundle v${localBundleVersion.take(8)} — sudah terbaru.")
            }

            continueExamAfterResolvedPolicies()
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

    @JvmOverloads
    public fun showSetupScreen(afterKioskExit: Boolean = false) {
        runOnUiThread {
            try {
                activeBlockDialog?.dismiss()
                activeBlockDialog = null
                SirenAlarmManager.stopSiren()
                examFlowRequested = false
                bundleFlowStarted = false
                serverPolicyResolved = false
                overlayWaivedForCurrentSession = false
                dndWaivedForCurrentSession = false
                pendingBundleBaseUrl = null
                setupLayout.visibility = View.VISIBLE
                examContainer.visibility = View.GONE
                webView.visibility = View.VISIBLE
                webView.loadUrl("about:blank")
            } catch (e: Throwable) {
                Log.e("MainActivity", "Error showing setup screen", e)
            }
            // Hanya sesudah kiosk benar-benar dilepas. Memanggilnya di setiap
            // layar setup akan menyuruh siswa membatalkan peran Home yang baru
            // saja mereka tetapkan untuk memulai ujian.
            if (afterKioskExit) promptRestoreHomeLauncher()
        }
    }

    /**
     * Sengaja tanpa flag "sudah pernah ditampilkan": syaratnya dievaluasi ulang
     * tiap kali dipanggil, sehingga prompt berhenti muncul dengan sendirinya
     * begitu launcher dikembalikan — tidak ada state yang bisa basi.
     */
    private fun promptRestoreHomeLauncher() {
        if (!prefs.getBoolean("kiosk_enforce_home_launcher", true)) return
        if (!HomeLauncherGuard.isHoldingHomeRole(this)) return
        if (isFinishing || isDestroyed) return
        if (restoreHomeDialog?.isShowing == true) return

        try {
            restoreHomeDialog = AlertDialog.Builder(this)
                .setTitle(R.string.restore_home_title)
                .setMessage(R.string.restore_home_message)
                .setCancelable(false)
                .setPositiveButton(R.string.restore_home_open) { d, _ ->
                    d.dismiss()
                    if (!HomeLauncherGuard.openHomeSettings(this)) {
                        Toast.makeText(this, R.string.restore_home_manual, Toast.LENGTH_LONG).show()
                    }
                }
                .setNegativeButton(R.string.restore_home_later) { d, _ -> d.dismiss() }
                .show()
        } catch (e: Throwable) {
            Log.e("MainActivity", "Gagal menampilkan prompt pemulihan launcher", e)
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
        // Tidak ada lagi impor bundle dari intent luar: lihat catatan di
        // AndroidManifest.xml. Bundle hanya masuk lewat tombol Impor (pilihan
        // sadar operator) atau unduhan terverifikasi dari server.
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

    @Suppress("DEPRECATION")
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

    private fun isKioskLocked(): Boolean =
        ::kioskManager.isInitialized && kioskManager.isSessionActive

    /**
     * Satu-satunya tempat back ditangani. Dipanggil dari tiga jalur karena back
     * bisa sampai lewat rute berbeda tergantung versi Android dan mode navigasi:
     * dispatchKeyEvent (tombol mentah) dan OnBackPressedDispatcher (androidx,
     * termasuk gesture). Debounce di SirenAlarmManager menjaga bunyinya sekali.
     */
    private fun handleBackAttempt() {
        if (isKioskLocked()) {
            // Tombol back memang diblokir lock task: ini percobaan keluar yang
            // gagal, bukan pelolosan. Cukup "titung" pendek + toast.
            SirenAlarmManager.playWarningBeep(this)
            notifyExitAttempt("BACK_BUTTON")
            Toast.makeText(this, getString(R.string.toast_kiosk_locked_warning), Toast.LENGTH_SHORT).show()
            return
        }
        if (::setupLayout.isInitialized && setupLayout.visibility == View.VISIBLE) {
            finish()
            return
        }
        if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
        }
    }

    /**
     * Rute paling awal: event back mentah, sebelum WebView atau dispatcher mana
     * pun sempat menelannya.
     */
    override fun dispatchKeyEvent(event: android.view.KeyEvent): Boolean {
        if (event.keyCode == android.view.KeyEvent.KEYCODE_BACK &&
            event.action == android.view.KeyEvent.ACTION_UP &&
            isKioskLocked()
        ) {
            handleBackAttempt()
            return true
        }
        return super.dispatchKeyEvent(event)
    }


    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (::kioskManager.isInitialized && kioskManager.isSessionActive) {
            SirenAlarmManager.playWarningBeep(this)
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
        if (::kioskManager.isInitialized && kioskManager.isSessionActive) {
            SirenAlarmManager.playWarningBeep(this)
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

        // Siswa baru saja kembali ke layar ujian — entah ditarik penutup layar
        // atau kembali sendiri. Kalau pin sudah lepas, DI SINILAH satu-satunya
        // kesempatan memasangnya lagi: `startLockTask()` menuntut activity yang
        // sedang di depan. Tanpa ini, layar ujian kembali tapi perangkatnya
        // tetap terbuka — shade dan Recents hidup, dan aplikasinya bisa
        // di-swipe mati dari Recents.
        //
        // Di-post supaya transaksi resume selesai lebih dulu; dipanggil
        // langsung di dalam onResume, activity belum benar-benar dianggap
        // berada di depan.
        if (::kioskManager.isInitialized) {
            window.decorView.post {
                try {
                    kioskManager.ensureLockTask()
                } catch (e: Throwable) {
                    Log.e("MainActivity", "Gagal memasang ulang lock task", e)
                }
                // Alasan yang sama persis dengan pin di atas: siswa yang sempat
                // menjangkau quick settings bisa mematikan DND di tengah ujian.
                // Sekali dipasang saat start tidak cukup.
                if (::securityManager.isInitialized && kioskManager.isSessionActive) {
                    try {
                        securityManager.reassertDnd()
                    } catch (e: Throwable) {
                        Log.e("MainActivity", "Gagal menegaskan ulang DND", e)
                    }
                }
            }
        }
    }

    override fun onPause() {
        super.onPause()
        KioskGuardService.isMainActivityVisible = false
        unregisterStatusReceivers()
        if (::kioskManager.isInitialized && kioskManager.isSessionActive) {
            // Sekadar ter-pause belum tentu lolos: dialog sistem, notification
            // shade, dan animasi lock task juga memicu onPause. Bunyikan beep
            // saja; KioskGuardService yang memutuskan kapan ini jadi sirene.
            SirenAlarmManager.playWarningBeep(this)
            if (::webView.isInitialized) {
                CommsBridge.sendEventToJS(webView, "exit_attempt", "{}")
            }
        }
    }

    override fun onDestroy() {
        try {
            activeBlockDialog?.dismiss()
            activeBlockDialog = null
            // Bersihkan sebelum super.onDestroy(); sesudah itu Activity tidak lagi
            // aman dipakai untuk stopLockTask atau transisi UI.
            if (::kioskManager.isInitialized && kioskManager.state != KioskManager.State.INACTIVE) {
                kioskManager.stopKiosk()
            }
        } catch (e: Throwable) {
            Log.e("MainActivity", "Error stopping kiosk in onDestroy", e)
        }
        super.onDestroy()
    }
}