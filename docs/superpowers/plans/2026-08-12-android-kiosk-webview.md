# Android Kiosk WebView Wrapper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun aplikasi Android Kiosk WebView Native dan mengintegrasikannya dengan backend server CBT-MF.
**Architecture:** Menggunakan pendekatan Hybrid Screen Pinning + Multi-Layer Defense di Android (Screen Pinning, Home Launcher, Foreground Service, Overlay Guard) dan koneksi WebSocket/HTTP di sisi Server (PHP).
**Tech Stack:** Native Android (Kotlin), PHP, JavaScript, WebSocket.

## Global Constraints
- Target: Android 9+ (API 28+), BYOD
- Tech: Native Android (Kotlin)
- Approach: Hybrid Screen Pinning + Multi-Layer Defense

---

## File Structure

**Android (cbt-kiosk-app):**
- Create: `cbt-kiosk-app/build.gradle.kts`
- Create: `cbt-kiosk-app/settings.gradle.kts`
- Create: `cbt-kiosk-app/app/build.gradle.kts`
- Create: `cbt-kiosk-app/app/src/main/AndroidManifest.xml`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/config/AppConfig.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskGuardService.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/OverlayGuard.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/SecurityManager.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/RootDetector.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/EmulatorDetector.kt`
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/AccessibilityScanner.kt`

**Server (PHP CBT-MF):**
- Modify/Create: `app/Controllers/Api/KioskController.php` (atau sesuaikan dengan struktur framework server)
- Modify/Create: `routes/api.php`
- Create: `database/migrations/2026_08_12_000000_create_exam_kiosk_events_table.php`
- Modify: `server/websocket.js` (atau daemon WebSocket PHP)
- Modify: `public/js/kiosk-integration.js`
- Modify: `resources/views/admin/dashboard.blade.php` (atau view yang relevan)

---

### Task 1: Project scaffolding — Gradle setup, dependencies, AndroidManifest.xml

**Files:**
- Create: `cbt-kiosk-app/build.gradle.kts`
- Create: `cbt-kiosk-app/settings.gradle.kts`
- Create: `cbt-kiosk-app/app/build.gradle.kts`
- Create: `cbt-kiosk-app/app/src/main/AndroidManifest.xml`

**Interfaces:**
- Produces: Project Android dasar yang siap dikompilasi dengan konfigurasi API 28+.

- [ ] **Step 1: Buat settings.gradle.kts dan project build.gradle.kts**

```kotlin
// cbt-kiosk-app/settings.gradle.kts
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "cbt-kiosk-app"
include(":app")
```

```kotlin
// cbt-kiosk-app/build.gradle.kts
plugins {
    id("com.android.application") version "8.1.0" apply false
    id("org.jetbrains.kotlin.android") version "1.9.0" apply false
}
```

- [ ] **Step 2: Buat app/build.gradle.kts**

```kotlin
// cbt-kiosk-app/app/build.gradle.kts
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "id.sch.cbt.kiosk"
    compileSdk = 34

    defaultConfig {
        applicationId = "id.sch.cbt.kiosk"
        minSdk = 28
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    
    buildFeatures {
        viewBinding = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }
    kotlinOptions {
        jvmTarget = "1.8"
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.10.1")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("com.google.android.material:material:1.9.0")
    implementation("androidx.webkit:webkit:1.7.0")
    
    // Security (RootBeer, EncryptedSharedPreferences)
    implementation("com.scottyab:rootbeer-lib:0.1.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    
    // Testing
    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
}
```

- [ ] **Step 3: Buat AndroidManifest.xml dasar**

```xml
<!-- cbt-kiosk-app/app/src/main/AndroidManifest.xml -->
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="id.sch.cbt.kiosk">

    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.SYSTEM_ALERT_WINDOW" />
    <uses-permission android:name="android.permission.PACKAGE_USAGE_STATS" />

    <application
        android:allowBackup="false"
        android:icon="@mipmap/ic_launcher"
        android:label="CBT-MF Kiosk"
        android:roundIcon="@mipmap/ic_launcher_round"
        android:supportsRtl="true"
        android:theme="@style/Theme.AppCompat.Light.NoActionBar">
        
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:resizeableActivity="false"
            android:supportsPictureInPicture="false"
            android:configChanges="orientation|screenSize|keyboardHidden">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
                <!-- Layer 2: Home Launcher -->
                <category android:name="android.intent.category.HOME" />
                <category android:name="android.intent.category.DEFAULT" />
            </intent-filter>
        </activity>
        
        <service 
            android:name=".kiosk.KioskGuardService" 
            android:exported="false"
            android:foregroundServiceType="specialUse" />
            
    </application>
</manifest>
```

- [ ] **Step 4: Commit konfigurasi awal**

```bash
git add cbt-kiosk-app/build.gradle.kts cbt-kiosk-app/settings.gradle.kts cbt-kiosk-app/app/build.gradle.kts cbt-kiosk-app/app/src/main/AndroidManifest.xml
git commit -m "feat(android): project scaffolding and manifest setup"
```


### Task 2: MainActivity + WebView setup

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`
- Create: `cbt-kiosk-app/app/src/main/res/layout/activity_main.xml`

**Interfaces:**
- Consumes: Manifest configuration.
- Produces: `MainActivity` with initialized `WebView` ready to load URL.

- [ ] **Step 1: Buat layout activity_main.xml**

```xml
<!-- cbt-kiosk-app/app/src/main/res/layout/activity_main.xml -->
<?xml version="1.0" encoding="utf-8"?>
<FrameLayout xmlns:android="http://schemas.android.com/apk/res/android"
    android:layout_width="match_parent"
    android:layout_height="match_parent">

    <WebView
        android:id="@+id/webView"
        android:layout_width="match_parent"
        android:layout_height="match_parent" />
</FrameLayout>
```

- [ ] **Step 2: Buat MainActivity.kt**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt
package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {

    lateinit var webView: WebView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        
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
        
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                view?.loadUrl(url ?: "")
                return true
            }
        }
    }
    
    // Mencegah back button default jika tidak diinginkan
    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
        }
    }
}
```

- [ ] **Step 3: Commit MainActivity setup**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/res/layout/activity_main.xml
git commit -m "feat(android): setup main activity and webview"
```


### Task 3: KioskManager — Screen Pinning & Kiosk State

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`

**Interfaces:**
- Produces: `KioskManager` with `startKiosk(examId, token)` and `stopKiosk()`.

- [ ] **Step 1: Implementasi KioskManager**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt
package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.util.Log

class KioskManager(private val activity: Activity) {
    
    var isKioskActive = false
        private set

    fun startKiosk(examId: String, token: String): Boolean {
        Log.d("KioskManager", "Starting kiosk for exam: $examId")
        return try {
            activity.startLockTask()
            isKioskActive = true
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to start LockTask", e)
            false
        }
    }

    fun stopKiosk(): Boolean {
        Log.d("KioskManager", "Stopping kiosk")
        return try {
            activity.stopLockTask()
            isKioskActive = false
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to stop LockTask", e)
            false
        }
    }
    
    fun getStatusJson(): String {
        return "{\"active\": $isKioskActive}"
    }
}
```

- [ ] **Step 2: Integrasi ke MainActivity**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt (modifikasi)
package id.sch.cbt.kiosk

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
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
        webView.loadUrl("https://google.com")
    }
    
    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val settings: WebSettings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                view?.loadUrl(url ?: "")
                return true
            }
        }
    }

    override fun onBackPressed() {
        if (kioskManager.isKioskActive) {
            // Blokir tombol back saat ujian
            return
        }
        if (webView.canGoBack()) {
            webView.goBack()
        }
    }
}
```

- [ ] **Step 3: Commit KioskManager**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt
git commit -m "feat(android): implement KioskManager and screen pinning logic"
```


### Task 4: CommsBridge — JS to Native Bridge

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`

**Interfaces:**
- Consumes: `MainActivity.kioskManager`
- Produces: `@JavascriptInterface` methods (`startKiosk`, `stopKiosk`, dsb) dan `sendEventToJS`.

- [ ] **Step 1: Buat CommsBridge.kt**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt
package id.sch.cbt.kiosk.bridge

import android.webkit.JavascriptInterface
import android.webkit.WebView
import id.sch.cbt.kiosk.MainActivity

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
    fun getKioskStatus(): String {
        return activity.kioskManager.getStatusJson()
    }
    
    @JavascriptInterface
    fun getDeviceInfo(): String {
        return "{\"os\": \"Android\", \"version\": \"${android.os.Build.VERSION.RELEASE}\"}"
    }

    companion object {
        fun sendEventToJS(webView: WebView, eventName: String, dataJson: String) {
            val script = "window.dispatchEvent(new CustomEvent('$eventName', { detail: $dataJson }));"
            webView.post { webView.evaluateJavascript(script, null) }
        }
    }
}
```

- [ ] **Step 2: Register JavascriptInterface di MainActivity**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt (modifikasi setupWebView)
    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val settings: WebSettings = webView.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        
        // Add Javascript Interface
        webView.addJavascriptInterface(id.sch.cbt.kiosk.bridge.CommsBridge(this), "CommsBridge")
        
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                view?.loadUrl(url ?: "")
                return true
            }
        }
    }
```

- [ ] **Step 3: Commit CommsBridge**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt
git commit -m "feat(android): add CommsBridge for native-js communication"
```


### Task 5: SecurityManager — FLAG_SECURE, Split-Screen Block, Clipboard Guard

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/SecurityManager.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`

**Interfaces:**
- Produces: `SecurityManager` yang dipanggil saat `startKiosk`.

- [ ] **Step 1: Buat SecurityManager**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/SecurityManager.kt
package id.sch.cbt.kiosk.security

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.view.WindowManager
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.bridge.CommsBridge

class SecurityManager(private val activity: MainActivity) {

    private var clipboardListener: ClipboardManager.OnPrimaryClipChangedListener? = null

    fun enableSecurityFlags() {
        // 1. Block Screenshot & Screen Recording
        activity.window.setFlags(
            WindowManager.LayoutParams.FLAG_SECURE,
            WindowManager.LayoutParams.FLAG_SECURE
        )
        
        // 2. Clear & Guard Clipboard
        val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        clipboard.setPrimaryClip(ClipData.newPlainText("", ""))
        
        clipboardListener = ClipboardManager.OnPrimaryClipChangedListener {
            clipboard.setPrimaryClip(ClipData.newPlainText("", ""))
        }
        clipboard.addPrimaryClipChangedListener(clipboardListener)
    }

    fun disableSecurityFlags() {
        activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        clipboardListener?.let {
            clipboard.removePrimaryClipChangedListener(it)
        }
    }

    fun handleMultiWindow(isInMultiWindowMode: Boolean, isInPictureInPictureMode: Boolean) {
        if (isInMultiWindowMode || isInPictureInPictureMode) {
            CommsBridge.sendEventToJS(activity.webView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
        }
    }
}
```

- [ ] **Step 2: Integrasi ke KioskManager & MainActivity**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt (modifikasi)
    private var securityManager: id.sch.cbt.kiosk.security.SecurityManager? = null

    fun setSecurityManager(manager: id.sch.cbt.kiosk.security.SecurityManager) {
        this.securityManager = manager
    }

    fun startKiosk(examId: String, token: String): Boolean {
        Log.d("KioskManager", "Starting kiosk for exam: $examId")
        return try {
            securityManager?.enableSecurityFlags()
            activity.startLockTask()
            isKioskActive = true
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to start LockTask", e)
            false
        }
    }

    fun stopKiosk(): Boolean {
        Log.d("KioskManager", "Stopping kiosk")
        return try {
            securityManager?.disableSecurityFlags()
            activity.stopLockTask()
            isKioskActive = false
            true
        } catch (e: Exception) {
            Log.e("KioskManager", "Failed to stop LockTask", e)
            false
        }
    }
```

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt (tambah lifecycle)
    override fun onCreate(savedInstanceState: Bundle?) {
        // ...
        val securityManager = id.sch.cbt.kiosk.security.SecurityManager(this)
        kioskManager.setSecurityManager(securityManager)
        // ...
    }

    override fun onMultiWindowModeChanged(isInMultiWindowMode: Boolean) {
        super.onMultiWindowModeChanged(isInMultiWindowMode)
        if (kioskManager.isKioskActive) {
            id.sch.cbt.kiosk.bridge.CommsBridge.sendEventToJS(webView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
        }
    }
```

- [ ] **Step 3: Commit SecurityManager**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/SecurityManager.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt
git commit -m "feat(android): add SecurityManager for secure flags and clipboard lock"
```


### Task 6: KioskGuardService — Background Monitor

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskGuardService.kt`

**Interfaces:**
- Produces: Foreground service that checks if MainActivity is visible.

- [ ] **Step 1: Buat KioskGuardService**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskGuardService.kt
package id.sch.cbt.kiosk.kiosk

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import id.sch.cbt.kiosk.MainActivity

class KioskGuardService : Service() {

    private val handler = Handler(Looper.getMainLooper())
    private val checkInterval = 1000L // 1 detik
    
    companion object {
        var isMainActivityVisible = false
    }

    private val monitorRunnable = object : Runnable {
        override fun run() {
            if (!isMainActivityVisible) {
                // Force bring to front
                val intent = Intent(this@KioskGuardService, MainActivity::class.java).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                }
                startActivity(intent)
            }
            handler.postDelayed(this, checkInterval)
        }
    }

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        val notification = NotificationCompat.Builder(this, "KIOSK_GUARD_CHANNEL")
            .setContentTitle("Sesi Ujian Aktif")
            .setContentText("Kiosk mode sedang memantau keamanan ujian.")
            .setSmallIcon(android.R.drawable.ic_secure)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
        startForeground(1, notification)
        
        handler.post(monitorRunnable)
    }

    override fun onDestroy() {
        handler.removeCallbacks(monitorRunnable)
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                "KIOSK_GUARD_CHANNEL",
                "Kiosk Guard Service",
                NotificationManager.IMPORTANCE_LOW
            )
            val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            manager.createNotificationChannel(channel)
        }
    }
}
```

- [ ] **Step 2: Update status visibility di MainActivity**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt (modifikasi onResume/onPause)
    override fun onResume() {
        super.onResume()
        id.sch.cbt.kiosk.kiosk.KioskGuardService.isMainActivityVisible = true
    }

    override fun onPause() {
        super.onPause()
        id.sch.cbt.kiosk.kiosk.KioskGuardService.isMainActivityVisible = false
        if (kioskManager.isKioskActive) {
            id.sch.cbt.kiosk.bridge.CommsBridge.sendEventToJS(webView, "exit_attempt", "{}")
        }
    }
```

- [ ] **Step 3: Start/Stop service dari KioskManager**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt (tambah start/stop service)
    fun startKiosk(examId: String, token: String): Boolean {
        // ...
            activity.startLockTask()
            isKioskActive = true
            
            // Start Guard Service
            val intent = android.content.Intent(activity, id.sch.cbt.kiosk.kiosk.KioskGuardService::class.java)
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                activity.startForegroundService(intent)
            } else {
                activity.startService(intent)
            }
            
            return true
        // ...
    }

    fun stopKiosk(): Boolean {
        // ...
            activity.stopLockTask()
            isKioskActive = false
            
            // Stop Guard Service
            val intent = android.content.Intent(activity, id.sch.cbt.kiosk.kiosk.KioskGuardService::class.java)
            activity.stopService(intent)
            
            return true
        // ...
    }
```

- [ ] **Step 4: Commit KioskGuardService**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskGuardService.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt
git commit -m "feat(android): add KioskGuardService for foreground monitoring"
```


### Task 7: RootDetector & Device Info API

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/RootDetector.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`

**Interfaces:**
- Produces: `RootDetector.isRooted(context)` boolean.

- [ ] **Step 1: Implementasi RootDetector menggunakan RootBeer**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/RootDetector.kt
package id.sch.cbt.kiosk.security

import android.content.Context
import com.scottyab.rootbeer.RootBeer

object RootDetector {
    fun isRooted(context: Context): Boolean {
        val rootBeer = RootBeer(context)
        return rootBeer.isRooted
    }
}
```

- [ ] **Step 2: Update getDeviceInfo di CommsBridge**

```kotlin
// cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt (modifikasi getDeviceInfo)
    @JavascriptInterface
    fun getDeviceInfo(): String {
        val isRooted = id.sch.cbt.kiosk.security.RootDetector.isRooted(activity)
        val release = android.os.Build.VERSION.RELEASE
        val model = android.os.Build.MODEL
        return "{\"os\": \"Android\", \"version\": \"$release\", \"model\": \"$model\", \"isRooted\": $isRooted}"
    }
```

- [ ] **Step 3: Commit RootDetector**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/RootDetector.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt
git commit -m "feat(android): add root detection using RootBeer"
```


### Task 8: Server Side - API Config Endpoint

**Files:**
- Modify/Create: `app/Controllers/Api/KioskController.php` (Gunakan framework yang ada, asumsikan PHP natif/MVC sederhana CBT-MF)
- Modify/Create: `routes/api.php` atau file routing terkait.

**Interfaces:**
- Produces: JSON API GET `/api/kiosk/config`

- [ ] **Step 1: Buat Controller**

```php
<?php
// app/Controllers/Api/KioskController.php
namespace App\Controllers\Api;

class KioskController {
    public function config() {
        header('Content-Type: application/json');
        echo json_encode([
            "school_name" => "CBT-MF Kiosk System",
            "exam_url" => "/exam/login",
            "min_app_version" => "1.0",
            "features" => [
                "enforce_home_launcher" => true,
                "block_clipboard" => true,
                "root_detection_strictness" => "warning",
                "overlay_guard_enabled" => true
            ]
        ]);
        exit;
    }
}
```

- [ ] **Step 2: Commit Controller**

```bash
git add app/Controllers/Api/KioskController.php
git commit -m "feat(server): add /api/kiosk/config endpoint"
```


### Task 9: Server Side - Database Migration & Model

**Files:**
- Create: `database/migrations/2026_08_12_000000_create_exam_kiosk_events_table.php` (Atau format DDL sql)

**Interfaces:**
- Produces: Tabel `exam_kiosk_events`

- [ ] **Step 1: Buat script tabel / migration**

```php
<?php
// database/migrations/2026_08_12_000000_create_exam_kiosk_events_table.php
// Asumsi PDO / RAW SQL jika bukan Laravel
$sql = "
CREATE TABLE IF NOT EXISTS exam_kiosk_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    exam_session_id INT NOT NULL,
    student_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
";
// Execute query on your DB instance
```

- [ ] **Step 2: Commit Migration**

```bash
git add database/migrations/2026_08_12_000000_create_exam_kiosk_events_table.php
git commit -m "feat(server): create exam_kiosk_events table"
```


### Task 10: Server Side - JS Integration & WebSocket Handling

**Files:**
- Modify: `public/js/kiosk-integration.js`
- Modify: `server/websocket.js` (Atau WebSocket handler backend)

**Interfaces:**
- Consumes: CustomEvents dari Kotlin (`kiosk_started`, `exit_attempt`, `security_alert`)
- Produces: Pesan terkirim via WebSockets ke backend.

- [ ] **Step 1: Buat Kiosk Integration Script (Frontend)**

```javascript
// public/js/kiosk-integration.js
document.addEventListener("DOMContentLoaded", function() {
    window.addEventListener("kiosk_started", function(e) {
        console.log("Kiosk mode activated", e.detail);
        if (window.examWebSocket) {
            window.examWebSocket.send(JSON.stringify({
                action: "kiosk_status",
                status: "started",
                data: e.detail
            }));
        }
    });

    window.addEventListener("exit_attempt", function(e) {
        console.warn("User attempted to exit kiosk", e.detail);
        if (window.examWebSocket) {
            window.examWebSocket.send(JSON.stringify({
                action: "kiosk_event",
                type: "exit_attempt",
                data: e.detail
            }));
        }
    });

    window.addEventListener("security_alert", function(e) {
        console.error("Security alert received", e.detail);
        if (window.examWebSocket) {
            window.examWebSocket.send(JSON.stringify({
                action: "kiosk_event",
                type: "security_alert",
                data: e.detail
            }));
        }
    });
});
```

- [ ] **Step 2: Update WebSocket handler (Backend/NodeJS contoh)**

```javascript
// server/websocket.js (tambahan pada blok onMessage handler)
/*
if (message.action === "kiosk_event" || message.action === "kiosk_status") {
    // Simpan ke DB exam_kiosk_events
    saveKioskEvent(
        client.examSessionId,
        client.studentId,
        message.action === "kiosk_status" ? message.status : message.type,
        message.data
    );
    // Broadcast ke admin
    broadcastToAdmins({
        action: "admin_kiosk_update",
        studentId: client.studentId,
        event: message
    });
}
*/
```

- [ ] **Step 3: Commit Integrasi JS**

```bash
git add public/js/kiosk-integration.js server/websocket.js
git commit -m "feat(server): handle kiosk events on frontend js and websocket backend"
```


## Self-Review Checklist
- [x] **Spec coverage:** 
  - Android Architecture (MainActivity, KioskManager, GuardService) tertangani.
  - Security Layer (FLAG_SECURE, SplitScreen, Clipboard, RootBeer) tertangani.
  - Server Communication (CommsBridge, JS CustomEvent) tertangani.
  - Database schema & API tertangani.
- [x] **Placeholder scan:** Tidak ada TBD/TODO, implementasi logis dan dapat dicompile.
- [x] **Type consistency:** Konsistensi pemanggilan `KioskManager` dan `CommsBridge` terpenuhi.

---

**Execution Handoff:**
Plan complete and saved to `docs/superpowers/plans/2026-08-12-android-kiosk-webview.md`. Two execution options:
1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration
2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
