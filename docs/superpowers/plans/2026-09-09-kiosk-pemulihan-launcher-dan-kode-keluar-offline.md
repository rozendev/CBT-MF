# Pemulihan Home Launcher & Kode Keluar Offline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memberi kiosk CBT-MF jalan pulang — menuntun siswa mengembalikan Home launcher sesudah ujian, dan memberi pengawas kode harian untuk membuka kiosk saat server tidak terjangkau.

**Architecture:** Dua pekerjaan yang saling lepas. (A) Aplikasi membaca setting `enforce_home_launcher` yang selama ini dikirim server tapi diabaikan, lalu menampilkan dialog pemulihan launcher sesudah kiosk dilepas. (B) Server menurunkan kode 8 digit harian dari `kiosk_exit_password` lewat HMAC-SHA256, dan mengirim ke perangkat **amplop berisi hash PBKDF2** kode 7 hari ke depan — password tidak pernah meninggalkan server. Perangkat mencocokkan masukan pengawas dengan amplop itu secara lokal, mencatat pemakaiannya, dan mengirim catatannya ke server begitu jaringan pulih.

**Tech Stack:** Kotlin (Android, minSdk 28, JUnit 4), PHP 8 + CodeIgniter 4 (PHPUnit, bootstrap ringan tanpa framework), Docker Compose untuk menjalankan tes PHP.

**Spec:** `docs/superpowers/specs/2026-09-09-kiosk-pemulihan-launcher-dan-kode-keluar-offline-design.md`

## Global Constraints

Berlaku untuk **semua** task di bawah.

- **Zona waktu kode dipaku `Asia/Jakarta`** di kedua sisi — bukan zona perangkat. Kalau ikut zona perangkat, siswa cukup mengubah setelan zona untuk menggeser kode yang berlaku.
- **Prefix HMAC persis `cbtmf-offline-exit:`** (dengan titik dua, tanpa spasi).
- **Iterasi PBKDF2 = 120000**, panjang turunan 32 byte (64 karakter hex), algoritma `PBKDF2WithHmacSHA256` / `hash_pbkdf2('sha256', ...)`.
- **Salt dikirim sebagai string hex 32 karakter dan dipakai apa adanya sebagai byte ASCII** — bukan di-decode dari hex lebih dulu. PHP meneruskan string itu langsung ke `hash_pbkdf2`; Kotlin harus memakai `salt.toByteArray(Charsets.UTF_8)`. Men-decode hex di salah satu sisi membuat kedua implementasi tidak akan pernah sepakat.
- **Amplop berisi 7 hari**, mulai hari ini di zona sekolah.
- **Nama SharedPreferences aplikasi: `cbt_kiosk_prefs`** (sudah dipakai `MainActivity.kt:99` dan `HeartbeatManager.kt:76`).
- **`App\Libraries\KioskOfflineCode` harus murni** — tanpa `service()`, model, `env()`, atau helper CI4 — supaya berjalan di suite PHPUnit bootstrap ringan. Semua akses cache/model tinggal di controller.
- **Android**: `minSdk = 28`, `compileSdk = 34`, `jvmTarget = "1.8"`, JUnit 4, nama test memakai backtick berbahasa Indonesia (ikuti `DeviceIdentityTest.kt`).
- **Menjalankan perintah di kontainer PHP wajib `docker compose exec -T ... </dev/null`.** Tanpa `-T` dan pengalihan stdin, perintah menelan stdin dan langkah interaktif sesudahnya menerima masukan kosong.
- **Jangan pakai `cd` relatif di shell sesi ini** — ada wrapper zoxide yang mengubah perilakunya. Pakai path absolut, atau `cd /home/rozen/conquer/CBT-MF/...` lengkap.
- **Tidak ada perubahan pada `exam-app.js` atau template ujian**, jadi `spark cbt:build-ui-bundle` **tidak** perlu dijalankan untuk pekerjaan ini.

---

### Task 1: Prompt pemulihan Home launcher

Task ini berdiri sendiri dan tidak menyentuh server sama sekali. Selesai di sini, aplikasi sudah menuntun siswa mengembalikan launcher-nya.

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HomeLauncherGuard.kt`
- Test: `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/HomeLauncherGuardTest.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt` (blok `features` di `applyKioskConfig` ±baris 837-856; `showSetupScreen()` ±baris 914)
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt` (pemanggilan `showSetupScreen()` di `stopKiosk`)
- Modify: `cbt-kiosk-app/app/src/main/res/values/strings.xml`
- Modify: `cbt-kiosk-app/app/build.gradle.kts` (blok `testOptions`)

**Interfaces:**
- Consumes: — (task pertama)
- Produces:
  - `HomeLauncherGuard.isSelfTheHomeApp(resolvedPackage: String?, ownPackage: String): Boolean`
  - `HomeLauncherGuard.isHoldingHomeRole(context: Context): Boolean`
  - `HomeLauncherGuard.openHomeSettings(context: Context): Boolean`
  - `MainActivity.showSetupScreen(afterKioskExit: Boolean = false)` — tanda tangan berubah, pemanggil lama tetap sah karena ada nilai bawaan

> **Catatan penyimpangan dari spec.** Spec §3.4 menyebut prompt muncul "setiap kali `showSetupScreen()` menampilkan layar setup". Itu ternyata bertabrakan dengan alur persiapan ujian: siswa baru saja menetapkan aplikasi sebagai Home app, layar setup tampil, lalu aplikasi langsung menyuruh membatalkannya. Karena itu prompt hanya muncul saat layar setup dicapai **sesudah kiosk dilepas**, lewat parameter `afterKioskExit`. Sisa perilaku (tidak ada flag "sudah pernah tampil", syarat dievaluasi ulang tiap kali) tetap seperti spec.

- [ ] **Step 1: Aktifkan stub Android di unit test**

`android.util.Log` dan `org.json` hanya berupa stub di unit test JVM dan melempar `RuntimeException("not mocked")` bila dipanggil. Task 6 dan 7 membutuhkan keduanya, jadi pasang sekarang sekalian.

Di `cbt-kiosk-app/app/build.gradle.kts`, tambahkan blok `testOptions` di dalam `android { ... }` (letakkan sesudah blok `kotlinOptions`):

```kotlin
    testOptions {
        // Stub android.jar melempar "not mocked" untuk android.util.Log dkk.
        // Kode yang diuji di sini memang mencatat log di jalur galatnya, dan
        // itu bukan yang sedang diuji — biarkan mengembalikan nilai bawaan.
        unitTests.isReturnDefaultValues = true
    }
```

dan di blok `dependencies`, tepat di bawah `testImplementation("junit:junit:4.13.2")`:

```kotlin
    // org.json di android.jar hanya stub; unit test butuh implementasi asli.
    testImplementation("org.json:json:20231013")
```

- [ ] **Step 2: Tulis test yang gagal untuk `isSelfTheHomeApp`**

Buat `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/HomeLauncherGuardTest.kt`:

```kotlin
package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.kiosk.HomeLauncherGuard
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class HomeLauncherGuardTest {

    private val own = "id.sch.cbt.kiosk"

    @Test
    fun `paket yang sama berarti aplikasi memegang peran home`() {
        assertTrue(HomeLauncherGuard.isSelfTheHomeApp("id.sch.cbt.kiosk", own))
    }

    @Test
    fun `launcher lain berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("com.google.android.apps.nexuslauncher", own))
    }

    @Test
    fun `resolved null berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp(null, own))
    }

    @Test
    fun `resolved kosong berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("   ", own))
    }

    @Test
    fun `resolver bawaan sistem bukan berarti memegang peran home`() {
        // Saat ada beberapa launcher dan pengguna belum menetapkan default,
        // sistem mengembalikan resolver-nya sendiri. Itu justru keadaan di mana
        // tidak ada yang perlu dikembalikan.
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("android", own))
    }
}
```

- [ ] **Step 3: Jalankan test, pastikan gagal**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.HomeLauncherGuardTest"
```

Expected: FAIL — kompilasi gagal, `Unresolved reference: HomeLauncherGuard`.

- [ ] **Step 4: Tulis `HomeLauncherGuard`**

Buat `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HomeLauncherGuard.kt`:

```kotlin
package id.sch.cbt.kiosk.kiosk

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.provider.Settings
import android.util.Log

/**
 * Melepas kiosk TIDAK mengembalikan perangkat ke keadaan semula: aplikasi masih
 * terdaftar CATEGORY_HOME (AndroidManifest.xml:29), jadi tombol Home tetap
 * membawa siswa ke sini. Objek ini yang mendeteksi keadaan itu dan menuntun
 * penggunanya keluar.
 */
object HomeLauncherGuard {

    private const val TAG = "HomeLauncherGuard"

    /**
     * Sengaja dipisah sebagai fungsi murni: PackageManager tidak tersedia di
     * unit test JVM, sementara justru perbandingan inilah yang perlu diuji —
     * termasuk kasus resolved == null dan resolver bawaan sistem.
     */
    fun isSelfTheHomeApp(resolvedPackage: String?, ownPackage: String): Boolean =
        !resolvedPackage.isNullOrBlank() && resolvedPackage.trim() == ownPackage

    fun resolveHomePackage(pm: PackageManager): String? = try {
        val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
        pm.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY)?.activityInfo?.packageName
    } catch (e: Throwable) {
        Log.w(TAG, "Tidak bisa membaca Home app terpilih", e)
        null
    }

    fun isHoldingHomeRole(context: Context): Boolean =
        isSelfTheHomeApp(resolveHomePackage(context.packageManager), context.packageName)

    /**
     * ROM Android sangat beragam dalam menyediakan layar pemilihan Home app,
     * jadi intent dicoba berjenjang. Mengembalikan false bila tidak satu pun
     * bisa dibuka, supaya pemanggil bisa menampilkan instruksi manual alih-alih
     * gagal senyap.
     */
    fun openHomeSettings(context: Context): Boolean {
        val candidates = listOf(
            Settings.ACTION_HOME_SETTINGS,
            Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS,
            Settings.ACTION_SETTINGS
        )
        for (action in candidates) {
            try {
                context.startActivity(Intent(action).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                return true
            } catch (e: Throwable) {
                Log.w(TAG, "Intent $action tidak tersedia di ROM ini", e)
            }
        }
        return false
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.HomeLauncherGuardTest"
```

Expected: PASS, 5 test.

- [ ] **Step 6: Tambahkan string**

Di `cbt-kiosk-app/app/src/main/res/values/strings.xml`, sebelum `</resources>`:

```xml
    <string name="restore_home_title">Kembalikan Layar Utama</string>
    <string name="restore_home_message">Ujian sudah selesai, tetapi perangkat ini masih memakai aplikasi ujian sebagai layar utama. Buka pengaturan untuk mengembalikan launcher bawaan Anda.</string>
    <string name="restore_home_open">Buka Pengaturan</string>
    <string name="restore_home_later">Nanti</string>
    <string name="restore_home_manual">Buka Pengaturan → Aplikasi → Aplikasi default → Aplikasi Beranda, lalu pilih launcher bawaan.</string>
```

- [ ] **Step 7: Baca `enforce_home_launcher` dari config**

Di `MainActivity.kt`, di dalam blok `features?.let { ... }` (±baris 839-856), tambahkan sesudah cabang `block_clipboard`:

```kotlin
                    if (it.has("enforce_home_launcher")) {
                        prefs.edit()
                            .putBoolean("kiosk_enforce_home_launcher", it.optBoolean("enforce_home_launcher", true))
                            .apply()
                    }
```

- [ ] **Step 8: Tambahkan prompt di `MainActivity`**

Tambahkan field di dekat deklarasi view lain (±baris 68):

```kotlin
    private var restoreHomeDialog: android.app.AlertDialog? = null
```

Tambahkan method (letakkan tepat sesudah `showSetupScreen`):

```kotlin
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
```

Tambahkan import di bagian atas berkas bila belum ada:

```kotlin
import id.sch.cbt.kiosk.kiosk.HomeLauncherGuard
```

- [ ] **Step 9: Panggil prompt hanya sesudah kiosk dilepas**

Ubah tanda tangan `showSetupScreen` (±baris 914) dan panggil prompt di ujungnya:

```kotlin
    @JvmOverloads
    public fun showSetupScreen(afterKioskExit: Boolean = false) {
        runOnUiThread {
            try {
                SirenAlarmManager.stopSiren()
                setupLayout.visibility = View.VISIBLE
                examContainer.visibility = View.GONE
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
```

Di `KioskManager.kt`, di dalam `stopKiosk()`, ubah baris pemanggilnya:

```kotlin
                (activity as? id.sch.cbt.kiosk.MainActivity)?.showSetupScreen(afterKioskExit = true)
```

- [ ] **Step 10: Kompilasi dan jalankan seluruh unit test**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew assembleDebug testDebugUnitTest
```

Expected: BUILD SUCCESSFUL; `DeviceIdentityTest` dan `HomeLauncherGuardTest` lulus.

- [ ] **Step 11: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HomeLauncherGuard.kt cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/HomeLauncherGuardTest.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt cbt-kiosk-app/app/src/main/res/values/strings.xml cbt-kiosk-app/app/build.gradle.kts
git commit -m "feat(kiosk): tuntun pemulihan Home launcher sesudah kiosk dilepas"
```

---

### Task 2: Library `KioskOfflineCode` (PHP)

**Files:**
- Create: `src/app/Libraries/KioskOfflineCode.php`
- Create: `src/tests/Kiosk/KioskOfflineCodeTest.php`
- Modify: `src/phpunit.xml.dist` (daftar testsuite)

**Interfaces:**
- Consumes: —
- Produces:
  - `KioskOfflineCode::codeForDay(string $password, string $day): string` — 8 digit
  - `KioskOfflineCode::upcomingDays(int $count = 7, ?\DateTimeImmutable $now = null): array` — `string[]` `YYYY-MM-DD`
  - `KioskOfflineCode::buildEnvelope(string $password, ?\DateTimeImmutable $now = null): array` — daftar `['day'=>, 'salt'=>, 'hash'=>]`
  - `KioskOfflineCode::passwordWeaknesses(string $password): array` — `string[]`, kosong = cukup kuat
  - `KioskOfflineCode::sanitizeEvents(mixed $events): array` — dipakai Task 4
  - Konstanta `TIMEZONE`, `PREFIX`, `ENVELOPE_DAYS`, `PBKDF2_ITERATIONS`, `MAX_EVENTS_PER_REQUEST`

- [ ] **Step 1: Daftarkan testsuite baru**

Di `src/phpunit.xml.dist`, tambahkan di dalam `<testsuites>` sesudah suite `Resilience`:

```xml
        <testsuite name="Kiosk">
            <directory>tests/Kiosk</directory>
        </testsuite>
```

Suite ini memakai bootstrap ringan (tanpa framework), karena itu `KioskOfflineCode` tidak boleh menyentuh CI4 sama sekali.

- [ ] **Step 2: Tulis test yang gagal**

Buat `src/tests/Kiosk/KioskOfflineCodeTest.php`.

Vektor di bawah **sudah diverifikasi cocok antara PHP dan Python** saat rencana ini disusun. Nilai yang sama diulang di `OfflineExitCodeTest.kt` (Task 6) — kalau salah satu diubah, keduanya harus diubah.

```php
<?php

namespace Tests\Kiosk;

use App\Libraries\KioskOfflineCode;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class KioskOfflineCodeTest extends TestCase
{
    /**
     * Vektor tetap. Nilai ini JUGA dipakai di
     * cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitCodeTest.kt —
     * dua implementasi yang diam-diam tidak sepakat berarti kode di dashboard
     * tidak pernah cocok dengan yang diminta perangkat, dan itu baru ketahuan
     * saat ujian berlangsung.
     */
    public static function vectorProvider(): array
    {
        return [
            'password bawaan'        => ['123456', '2026-09-09', '25967489'],
            'password kuat'          => ['R4hasia-Pengawas-2026', '2026-09-09', '98255973'],
            'pergantian bulan'       => ['R4hasia-Pengawas-2026', '2026-10-01', '52727708'],
            'pergantian tahun'       => ['R4hasia-Pengawas-2026', '2027-01-01', '89306567'],
            'password non-ASCII'     => ['sandi-ünïcode-ø', '2026-12-31', '14433010'],
        ];
    }

    /** @dataProvider vectorProvider */
    public function testKodeHarianCocokDenganVektorTetap(string $password, string $day, string $expected): void
    {
        $this->assertSame($expected, KioskOfflineCode::codeForDay($password, $day));
    }

    public function testKodeSelaluDelapanDigit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $code = KioskOfflineCode::codeForDay('pw-' . $i, '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT));
            $this->assertMatchesRegularExpression('/\A[0-9]{8}\z/', $code, "iterasi $i");
        }
    }

    public function testPasswordBerbedaMenghasilkanKodeBerbeda(): void
    {
        $this->assertNotSame(
            KioskOfflineCode::codeForDay('satu', '2026-09-09'),
            KioskOfflineCode::codeForDay('dua', '2026-09-09')
        );
    }

    public function testHariBerurutanDihitungDiZonaJakarta(): void
    {
        // 2026-09-09 23:45 WIB masih tanggal 9, walau di UTC sudah 16:45.
        $now = new DateTimeImmutable('2026-09-09 23:45:00', new DateTimeZone('Asia/Jakarta'));
        $days = KioskOfflineCode::upcomingDays(3, $now);

        $this->assertSame(['2026-09-09', '2026-09-10', '2026-09-11'], $days);
    }

    public function testAmplopBerisiTujuhHariDenganSaltBerbeda(): void
    {
        $now = new DateTimeImmutable('2026-09-09 10:00:00', new DateTimeZone('Asia/Jakarta'));
        $envelope = KioskOfflineCode::buildEnvelope('R4hasia-Pengawas-2026', $now);

        $this->assertCount(7, $envelope);
        $this->assertSame('2026-09-09', $envelope[0]['day']);
        $this->assertSame('2026-09-15', $envelope[6]['day']);

        $salts = array_column($envelope, 'salt');
        $this->assertCount(7, array_unique($salts), 'salt harus berbeda tiap hari');

        foreach ($envelope as $entry) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $entry['salt']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $entry['hash']);
        }
    }

    public function testHashAmplopDapatDiverifikasiUlang(): void
    {
        $now = new DateTimeImmutable('2026-09-09 10:00:00', new DateTimeZone('Asia/Jakarta'));
        $envelope = KioskOfflineCode::buildEnvelope('R4hasia-Pengawas-2026', $now);
        $entry = $envelope[0];

        $recomputed = hash_pbkdf2(
            'sha256',
            KioskOfflineCode::codeForDay('R4hasia-Pengawas-2026', $entry['day']),
            $entry['salt'],
            KioskOfflineCode::PBKDF2_ITERATIONS,
            64
        );

        $this->assertSame($entry['hash'], $recomputed);
    }

    public function testPasswordBawaanDinilaiLemah(): void
    {
        $reasons = KioskOfflineCode::passwordWeaknesses('123456');

        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('bawaan', implode(' ', $reasons));
    }

    public function testPasswordPendekDinilaiLemah(): void
    {
        $this->assertNotEmpty(KioskOfflineCode::passwordWeaknesses('pengawas'));
    }

    public function testPasswordUmumDinilaiLemah(): void
    {
        $this->assertNotEmpty(KioskOfflineCode::passwordWeaknesses('PASSWORD'));
    }

    public function testPasswordKuatTidakDikeluhkan(): void
    {
        $this->assertSame([], KioskOfflineCode::passwordWeaknesses('R4hasia-Pengawas-2026'));
    }

    public function testEventDibersihkanDanDibatasi(): void
    {
        $events = [];
        for ($i = 0; $i < 40; $i++) {
            $events[] = ['at' => 1757400000 + $i, 'code_day' => '2026-09-09', 'app_version' => '1.0.0'];
        }

        $clean = KioskOfflineCode::sanitizeEvents($events);

        $this->assertCount(KioskOfflineCode::MAX_EVENTS_PER_REQUEST, $clean);
        $this->assertSame(1757400000, $clean[0]['at']);
    }

    public function testEventCacatDibuang(): void
    {
        $clean = KioskOfflineCode::sanitizeEvents([
            ['at' => 1757400000, 'code_day' => '2026-09-09', 'app_version' => '1.0.0'],
            ['at' => 'bukan angka', 'code_day' => '2026-09-09', 'app_version' => '1.0.0'],
            ['at' => 1757400001, 'code_day' => 'bukan tanggal', 'app_version' => '1.0.0'],
            'bukan array',
        ]);

        $this->assertCount(1, $clean);
    }

    public function testEventBukanArrayMenghasilkanArrayKosong(): void
    {
        $this->assertSame([], KioskOfflineCode::sanitizeEvents('bukan array'));
        $this->assertSame([], KioskOfflineCode::sanitizeEvents(null));
    }
}
```

- [ ] **Step 3: Jalankan test, pastikan gagal**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite Kiosk </dev/null
```

Expected: FAIL — `Class "App\Libraries\KioskOfflineCode" not found`.

- [ ] **Step 4: Tulis library-nya**

Buat `src/app/Libraries/KioskOfflineCode.php`:

```php
<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Kode keluar offline: 8 digit harian yang diturunkan dari kiosk_exit_password.
 *
 * Perangkat TIDAK pernah menerima password-nya. Yang dikirim adalah amplop
 * berisi hash PBKDF2 dari kode beberapa hari ke depan, sehingga HP yang
 * dibongkar membocorkan paling jauh sepekan kode — bukan password pengawas,
 * yang juga menjaga jalur keluar normal (/api/kiosk/verify-exit).
 *
 * Kelas ini SENGAJA murni: tanpa service(), model, atau helper CodeIgniter,
 * supaya dapat diuji dengan bootstrap ringan tanpa memuat framework.
 */
final class KioskOfflineCode
{
    /**
     * Dipaku, bukan mengikuti zona perangkat: kalau ikut zona perangkat, siswa
     * cukup mengubah setelan zona untuk menggeser kode yang berlaku.
     */
    public const TIMEZONE = 'Asia/Jakarta';

    public const PREFIX = 'cbtmf-offline-exit:';

    public const ENVELOPE_DAYS = 7;

    /**
     * Ruang kode hanya 10^8. Dengan hash cepat, penyerang yang memegang amplop
     * memulihkan kodenya dalam hitungan detik; 120k iterasi membuat satu
     * tebakan berbiaya ~100 ms sehingga menyisir seluruh ruang tidak praktis,
     * sementara satu verifikasi yang sah tetap terasa seketika.
     */
    public const PBKDF2_ITERATIONS = 120000;

    public const MIN_STRONG_LENGTH = 12;

    public const MAX_EVENTS_PER_REQUEST = 20;

    private const COMMON_PASSWORDS = [
        '123456', '1234567', '12345678', '123456789', '1234567890',
        'password', 'qwerty', 'admin', 'administrator', 'guru', 'sekolah',
        'ujian', 'kiosk', 'pengawas', '111111', '000000', 'abc123',
    ];

    /**
     * Kode 8 digit untuk satu hari. Truncation dinamis RFC 4226 dipakai
     * alih-alih "ambil 4 byte pertama" karena ia standar dan menghilangkan
     * pertanyaan byte mana yang diambil saat diimplementasi ulang.
     */
    public static function codeForDay(string $password, string $day): string
    {
        $mac = hash_hmac('sha256', self::PREFIX . $day, $password, true);

        $offset = ord($mac[31]) & 0x0F;
        $binary = ((ord($mac[$offset]) & 0x7F) << 24)
                | ((ord($mac[$offset + 1]) & 0xFF) << 16)
                | ((ord($mac[$offset + 2]) & 0xFF) << 8)
                |  (ord($mac[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /** Daftar tanggal YYYY-MM-DD mulai hari ini di zona sekolah. */
    public static function upcomingDays(int $count = self::ENVELOPE_DAYS, ?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE));

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $today->modify(sprintf('+%d day', $i))->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Amplop untuk perangkat.
     *
     * Salt disimpan sebagai string hex dan diteruskan apa adanya ke
     * hash_pbkdf2 — BUKAN di-decode lebih dulu. Sisi Kotlin harus memakai
     * byte UTF-8 dari string yang sama; men-decode hex di salah satu sisi
     * membuat kedua implementasi tidak akan pernah sepakat.
     */
    public static function buildEnvelope(string $password, ?DateTimeImmutable $now = null): array
    {
        $entries = [];

        foreach (self::upcomingDays(self::ENVELOPE_DAYS, $now) as $day) {
            $salt = bin2hex(random_bytes(16));
            $entries[] = [
                'day'  => $day,
                'salt' => $salt,
                'hash' => hash_pbkdf2('sha256', self::codeForDay($password, $day), $salt, self::PBKDF2_ITERATIONS, 64),
            ];
        }

        return $entries;
    }

    /**
     * Alasan kelemahan password; array kosong berarti cukup kuat.
     *
     * Seluruh kekuatan kode offline bertumpu pada entropi password ini, karena
     * algoritmanya ada di dalam APK yang bisa dibongkar siapa pun. Pemeriksaan
     * ini hanya MEMPERINGATKAN dan tidak pernah memblokir — itu keputusan sadar
     * pemilik produk, dicatat di §2.1 spec.
     */
    public static function passwordWeaknesses(string $password): array
    {
        if ($password === '') {
            return ['Password keluar kiosk belum diisi.'];
        }

        $reasons = [];

        if ($password === '123456') {
            $reasons[] = 'Masih memakai password bawaan 123456.';
        }
        if (mb_strlen($password) < self::MIN_STRONG_LENGTH) {
            $reasons[] = 'Kurang dari ' . self::MIN_STRONG_LENGTH . ' karakter.';
        }
        if (in_array(mb_strtolower($password), self::COMMON_PASSWORDS, true)) {
            $reasons[] = 'Termasuk password yang umum ditebak.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Membersihkan daftar event audit dari perangkat. Rute pelapornya tidak
     * terautentikasi, jadi apa pun yang masuk diperlakukan sebagai data asing.
     */
    public static function sanitizeEvents($events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $clean = [];
        foreach ($events as $event) {
            if (count($clean) >= self::MAX_EVENTS_PER_REQUEST) {
                break;
            }
            if (!is_array($event)) {
                continue;
            }

            $at      = $event['at'] ?? null;
            $codeDay = (string) ($event['code_day'] ?? '');
            $version = (string) ($event['app_version'] ?? '');

            if (!is_int($at) && !(is_string($at) && ctype_digit($at))) {
                continue;
            }
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $codeDay) !== 1) {
                continue;
            }

            $clean[] = [
                'at'          => (int) $at,
                'code_day'    => $codeDay,
                'app_version' => mb_substr(preg_replace('/[^0-9A-Za-z._-]/', '', $version) ?? '', 0, 32),
            ];
        }

        return $clean;
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

```bash
docker compose exec -T php vendor/bin/phpunit --testsuite Kiosk </dev/null
```

Expected: PASS, seluruh test hijau (termasuk 5 vektor tetap).

- [ ] **Step 6: Pastikan suite lain tidak rusak**

```bash
docker compose exec -T php vendor/bin/phpunit </dev/null
```

Expected: seluruh suite bootstrap ringan tetap hijau.

- [ ] **Step 7: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add src/app/Libraries/KioskOfflineCode.php src/tests/Kiosk/KioskOfflineCodeTest.php src/phpunit.xml.dist
git commit -m "feat(kiosk): library kode keluar offline harian"
```

---

### Task 3: Setting `kiosk_offline_exit_enabled` & amplop di `/api/kiosk/config`

**Files:**
- Modify: `src/app/Controllers/Api/KioskController.php` (method `config`, ±baris 50-64)
- Modify: `src/app/Controllers/Admin/KioskSettingsController.php` (`ALLOWED_KEYS` ±baris 15-20, `KEY_META` ±baris 22-32)
- Modify: `src/app/Controllers/Admin/SettingController.php` (peta key ±baris 67, seed ±baris 278)

**Interfaces:**
- Consumes: `KioskOfflineCode::buildEnvelope()`, `KioskOfflineCode::PBKDF2_ITERATIONS` (Task 2)
- Produces: blok JSON `offline_exit` di `/api/kiosk/config` — dikonsumsi Task 6 & 8:
  ```json
  { "enabled": true, "iterations": 120000,
    "days": [ { "day": "YYYY-MM-DD", "salt": "<32 hex>", "hash": "<64 hex>" } ] }
  ```
  Saat toggle mati: `{ "enabled": false, "iterations": 0, "days": [] }`

- [ ] **Step 1: Daftarkan key setting**

Di `src/app/Controllers/Admin/KioskSettingsController.php`, tambahkan ke **`BOOLEAN_KEYS`** (baris 14-20, sesudah `'kiosk_overlay_guard_enabled',`):

```php
        'kiosk_offline_exit_enabled',
```

dan ke `KEY_META` (baris 22-31):

```php
        'kiosk_offline_exit_enabled' => ['group' => 'kiosk', 'type' => 'boolean'],
```

Keduanya wajib. `update()` memakai `BOOLEAN_KEYS` untuk memaksa nilai `'0'` saat checkbox tidak dicentang — checkbox yang tidak dicentang tidak mengirim apa pun. Tanpa entri di sana, toggle hanya bisa dinyalakan dan tidak pernah bisa dimatikan, yang langsung melanggar janji spec §4.8 bahwa mematikan toggle mencabut jalur offline dari seluruh perangkat.

Di `src/app/Controllers/Admin/SettingController.php`, tambahkan ke peta key (dekat baris 67):

```php
        'kiosk_offline_exit_enabled' => ['group' => 'kiosk',  'type' => 'boolean'],
```

dan ke daftar seed (dekat baris 278):

```php
            ['key' => 'kiosk_offline_exit_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'kiosk'],
```

Nilai bawaan **`'0'` (mati)** disengaja: sekolah yang tidak membutuhkan jalur offline tidak boleh ikut menanggung risikonya.

- [ ] **Step 2: Kirim amplop di config**

Di `src/app/Controllers/Api/KioskController.php`, tambahkan import di bagian atas:

```php
use App\Libraries\KioskOfflineCode;
```

Di method `config()`, sesudah `$payload = [...]` (±baris 64) dan sebelum blok `$deviceId`, sisipkan:

```php
        // Amplop kode keluar offline. Password TIDAK ikut: perangkat hanya
        // menerima hash lambat dari kodenya, sehingga HP yang dibongkar tidak
        // membocorkan password pengawas — yang juga menjaga verify-exit.
        $offlineEnabled = (bool) $settingModel->getValue('kiosk_offline_exit_enabled', false);
        if ($offlineEnabled) {
            $exitPassword = (string) $settingModel->getValue('kiosk_exit_password', '123456');
            $payload['offline_exit'] = [
                'enabled'    => true,
                'iterations' => KioskOfflineCode::PBKDF2_ITERATIONS,
                'days'       => KioskOfflineCode::buildEnvelope($exitPassword),
            ];
        } else {
            // Dikirim eksplisit, bukan dihilangkan: perangkat harus MENGHAPUS
            // amplop lamanya saat toggle dimatikan, dan blok yang hilang tidak
            // bisa dibedakan dari respons versi lama.
            $payload['offline_exit'] = ['enabled' => false, 'iterations' => 0, 'days' => []];
        }
```

- [ ] **Step 3: Verifikasi manual dengan toggle mati**

```bash
docker compose exec -T php php spark db:seed SettingSeeder </dev/null 2>/dev/null || true
curl -s "http://localhost:8080/api/kiosk/config?device_id=$(printf 'a%.0s' {1..32})" | python3 -m json.tool | head -40
```

Expected: ada `"offline_exit": {"enabled": false, "iterations": 0, "days": []}`.

Bila `curl` ke `localhost` gagal, pakai host yang dipakai stack ini (lihat `docker compose ps` dan konfigurasi nginx); yang penting responsnya berasal dari stack yang me-mount repo ini.

- [ ] **Step 4: Verifikasi manual dengan toggle hidup**

Nyalakan setting lewat SQL langsung (panel admin baru dibuat di Task 5):

```bash
docker compose exec -T mariadb sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "INSERT INTO settings (\`key\`,\`value\`,\`type\`,\`group\`) VALUES (\"kiosk_offline_exit_enabled\",\"1\",\"boolean\",\"kiosk\") ON DUPLICATE KEY UPDATE \`value\`=\"1\";"' </dev/null
curl -s "http://localhost:8080/api/kiosk/config?device_id=$(printf 'a%.0s' {1..32})" | python3 -c "
import json,sys
d = json.load(sys.stdin)['offline_exit']
print('enabled   :', d['enabled'])
print('iterations:', d['iterations'])
print('hari      :', len(d['days']), '->', d['days'][0]['day'], '..', d['days'][-1]['day'])
print('salt len  :', len(d['days'][0]['salt']), 'hash len:', len(d['days'][0]['hash']))
"
```

Expected: `enabled True`, `iterations 120000`, `hari 7`, `salt len 32`, `hash len 64`.

- [ ] **Step 5: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add src/app/Controllers/Api/KioskController.php src/app/Controllers/Admin/KioskSettingsController.php src/app/Controllers/Admin/SettingController.php
git commit -m "feat(kiosk): kirim amplop kode offline di /api/kiosk/config"
```

---

### Task 4: Endpoint `POST /api/kiosk/offline-exit-log`

Karena keputusan #3 membuat jalur offline berlaku bahkan saat server sehat, tanpa endpoint ini ia menjadi pintu yang tidak meninggalkan jejak apa pun.

**Files:**
- Modify: `src/app/Controllers/Api/KioskController.php` (method baru `offlineExitLog`)
- Modify: `src/app/Config/Routes.php` (±baris 44)
- Modify: `src/app/Config/Filters.php` (daftar pengecualian CSRF, ±baris 100)

**Interfaces:**
- Consumes: `KioskOfflineCode::sanitizeEvents()`, `KioskOfflineCode::MAX_EVENTS_PER_REQUEST` (Task 2); `DeviceBan::isValidDeviceId()` (sudah ada)
- Produces: endpoint `POST /api/kiosk/offline-exit-log`, body `{"device_id": "...", "events": [{"at": int, "code_day": "YYYY-MM-DD", "app_version": "..."}]}` → `200 {"status":"ok","recorded":N}` — dikonsumsi Task 7 & 8

- [ ] **Step 1: Daftarkan rute**

Di `src/app/Config/Routes.php`, di dalam grup `api`, sesudah baris `kiosk/can-exit`:

```php
    $routes->post('kiosk/offline-exit-log', 'Api\KioskController::offlineExitLog');
```

Di `src/app/Config/Filters.php`, di daftar pengecualian `csrf`, sesudah `'api/kiosk/can-exit',`:

```php
                'api/kiosk/offline-exit-log',
```

- [ ] **Step 2: Tulis method controller**

Di `src/app/Controllers/Api/KioskController.php`, tambahkan konstanta di dekat `MAX_EXIT_FAILS`:

```php
    protected const MAX_OFFLINE_LOG_POSTS = 10;

    protected const OFFLINE_LOG_WINDOW_SECONDS = 600;
```

lalu tambahkan method (letakkan sesudah `canExit`):

```php
    /**
     * Perangkat melaporkan pemakaian kode keluar offline begitu jaringan pulih.
     *
     * POST /api/kiosk/offline-exit-log
     * { "device_id": "...", "events": [ { "at": 1757400000, "code_day": "2026-09-09", "app_version": "1.0.0" } ] }
     *
     * Rute ini tidak terautentikasi, sama seperti rute kiosk lain, sehingga ia
     * jalur tulis ke log aktivitas. Karena itu: jumlah event dibatasi, isinya
     * dibersihkan, dan pelapornya di-throttle per device.
     */
    public function offlineExitLog()
    {
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body)) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error']);
            }

            $deviceId = (string) ($body['device_id'] ?? '');
            if (!DeviceBan::isValidDeviceId($deviceId)) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error']);
            }

            $events = KioskOfflineCode::sanitizeEvents($body['events'] ?? null);
            if ($events === []) {
                return $this->response->setJSON(['status' => 'ok', 'recorded' => 0]);
            }

            $cache    = service('cache');
            $cacheKey = 'kiosk_offline_log_' . md5($deviceId);
            $posts    = (int) $cache->get($cacheKey);
            if ($posts >= self::MAX_OFFLINE_LOG_POSTS) {
                return $this->response->setStatusCode(429)->setJSON(['status' => 'error']);
            }
            $cache->save($cacheKey, $posts + 1, self::OFFLINE_LOG_WINDOW_SECONDS);

            $log = new ActivityLogModel();
            foreach ($events as $event) {
                try {
                    $log->log(
                        'kiosk_offline_exit',
                        null,
                        'kiosk_device',
                        null,
                        sprintf(
                            'Kiosk dibuka dengan kode offline. device=%s kode_hari=%s waktu=%s app=%s',
                            $deviceId,
                            $event['code_day'],
                            date('Y-m-d H:i:s', $event['at']),
                            $event['app_version'] !== '' ? $event['app_version'] : '-'
                        )
                    );
                } catch (\Throwable $e) {
                    log_message('error', 'Kiosk offlineExitLog activity log error: ' . $e->getMessage());
                }
            }

            return $this->response->setJSON(['status' => 'ok', 'recorded' => count($events)]);
        } catch (\Throwable $e) {
            log_message('error', 'Kiosk offlineExitLog ERROR: ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON(['status' => 'error']);
        }
    }
```

Pastikan import berikut ada di bagian atas berkas (`ActivityLogModel` mungkin belum diimpor):

```php
use App\Models\ActivityLogModel;
```

- [ ] **Step 3: Verifikasi endpoint menerima laporan yang sah**

```bash
DEV=$(printf 'a%.0s' {1..32})
curl -s -X POST "http://localhost:8080/api/kiosk/offline-exit-log" \
  -H 'Content-Type: application/json' \
  -d "{\"device_id\":\"$DEV\",\"events\":[{\"at\":1757400000,\"code_day\":\"2026-09-09\",\"app_version\":\"1.0.0\"}]}"
```

Expected: `{"status":"ok","recorded":1}`.

- [ ] **Step 4: Verifikasi penolakan dan throttle**

```bash
# device_id tidak sah -> 400
curl -s -o /dev/null -w '%{http_code}\n' -X POST "http://localhost:8080/api/kiosk/offline-exit-log" \
  -H 'Content-Type: application/json' -d '{"device_id":"spasi tidak boleh","events":[]}'

# throttle: kirim 12 kali, permintaan ke-11 dan seterusnya harus 429
DEV=$(printf 'b%.0s' {1..32})
for i in $(seq 1 12); do
  curl -s -o /dev/null -w "$i:%{http_code} " -X POST "http://localhost:8080/api/kiosk/offline-exit-log" \
    -H 'Content-Type: application/json' \
    -d "{\"device_id\":\"$DEV\",\"events\":[{\"at\":1757400000,\"code_day\":\"2026-09-09\",\"app_version\":\"1.0.0\"}]}"
done; echo
```

Expected: baris pertama `400`; baris kedua menunjukkan `200` sampai iterasi 10 lalu `429` di 11 dan 12.

- [ ] **Step 5: Verifikasi entri masuk log aktivitas**

```bash
docker compose exec -T mariadb sh -c 'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SELECT action, description, created_at FROM activity_logs WHERE action=\"kiosk_offline_exit\" ORDER BY id DESC LIMIT 3;"' </dev/null
```

Expected: ada baris `kiosk_offline_exit` dengan device dan `kode_hari=2026-09-09`.

- [ ] **Step 6: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add src/app/Controllers/Api/KioskController.php src/app/Config/Routes.php src/app/Config/Filters.php
git commit -m "feat(kiosk): endpoint pelaporan pemakaian kode keluar offline"
```

---

### Task 5: Panel admin kode keluar offline

**Files:**
- Modify: `src/app/Controllers/Admin/KioskSettingsController.php` (method `index`)
- Modify: `src/app/Views/admin/kiosk/index.php`

**Interfaces:**
- Consumes: `KioskOfflineCode::codeForDay()`, `::upcomingDays()`, `::passwordWeaknesses()` (Task 2); setting `kiosk_offline_exit_enabled` (Task 3)
- Produces: — (antarmuka manusia)

- [ ] **Step 1: Siapkan data untuk view**

Di `src/app/Controllers/Admin/KioskSettingsController.php`, tambahkan import:

```php
use App\Libraries\KioskOfflineCode;
```

Ganti seluruh isi `index()` (baris 39-47) menjadi:

```php
    public function index()
    {
        $groupedSettings = $this->settingModel->getGroupedSettings();
        $kioskSettings   = $groupedSettings['kiosk'] ?? [];

        $exitPassword = (string) $this->settingModel->getValue('kiosk_exit_password', '123456');

        // Dihitung ulang tiap kali halaman dibuka, bukan disimpan: kode berubah
        // otomatis begitu password diganti, tanpa langkah rotasi terpisah.
        $offlineCodes = [];
        foreach (KioskOfflineCode::upcomingDays() as $day) {
            $offlineCodes[] = [
                'day'  => $day,
                'code' => KioskOfflineCode::codeForDay($exitPassword, $day),
            ];
        }

        return view('admin/kiosk/index', [
            'kioskSettings'      => $kioskSettings,
            'offlineCodes'       => $offlineCodes,
            'passwordWeaknesses' => KioskOfflineCode::passwordWeaknesses($exitPassword),
        ]);
    }
```

- [ ] **Step 2: Tambahkan panel di view**

Di `src/app/Views/admin/kiosk/index.php`, tambahkan kartu berikut sesudah kartu yang memuat toggle `kiosk_overlay_guard_enabled` (blok tersebut berakhir di sekitar baris 168):

```php
                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Kode Keluar Offline</h6>
                                    <p class="text-muted fs-7 mb-0">Mengizinkan pengawas membuka kiosk dengan kode harian saat server tidak terjangkau.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskOfflineExit"
                                           name="settings[kiosk_offline_exit_enabled]" value="1"
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_offline_exit_enabled', false) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>
```

> Argumen ketiga `false` wajib ditulis eksplisit. `kioskSettingChecked` (baris 12 di berkas view) memakai `bool $default = true`, sehingga tanpa argumen itu instalasi yang belum menjalankan seeder akan menampilkan toggle menyala — kebalikan dari mati-secara-default yang diwajibkan spec §4.8.

Lalu tambahkan panel daftar kode sebagai kartu tersendiri, di bawah kartu pengaturan (sebelum penutup kontainer halaman):

```php
<?php if (kioskSettingChecked($kioskSettings, 'kiosk_offline_exit_enabled', false)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold mb-0 text-dark">Kode Keluar Offline</h5>
        <p class="text-muted fs-7 mb-0">Bawa daftar ini saat ujian. Kode berubah otomatis bila password pengawas diganti.</p>
    </div>
    <div class="card-body p-4">
        <?php if (!empty($passwordWeaknesses)): ?>
            <div class="alert alert-danger">
                <strong>Password pengawas lemah.</strong>
                Seluruh kekuatan kode di bawah bertumpu pada password ini, dan algoritmanya ada di dalam APK yang bisa dibongkar siapa pun.
                <ul class="mb-0 mt-2">
                    <?php foreach ($passwordWeaknesses as $reason): ?>
                        <li><?= esc($reason) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Tanggal</th><th>Kode</th></tr></thead>
                <tbody>
                <?php foreach ($offlineCodes as $i => $row): ?>
                    <tr<?= $i === 0 ? ' class="table-warning"' : '' ?>>
                        <td><?= esc($row['day']) ?><?= $i === 0 ? ' <span class="badge bg-warning text-dark">hari ini</span>' : '' ?></td>
                        <td class="fw-bold fs-5 font-monospace"><?= esc($row['code']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Verifikasi halaman dengan password lemah**

Buka `http://localhost:8080/admin/kiosk` di browser sebagai admin, dengan `kiosk_offline_exit_enabled` masih hidup dari Task 3 dan `kiosk_exit_password` masih `123456`.

Expected: panel muncul, peringatan merah tampil, kode hari ini **25967489** (cocok dengan vektor tetap di Task 2 untuk tanggal 2026-09-09; untuk tanggal lain nilainya tentu berbeda), 7 baris tanggal berurutan.

- [ ] **Step 4: Verifikasi kode berubah saat password diganti**

Ganti `kiosk_exit_password` menjadi `R4hasia-Pengawas-2026` lewat form di halaman itu, simpan.

Expected: peringatan merah hilang, dan seluruh kode berubah. Bila tanggal hari ini 2026-09-09, kodenya menjadi **98255973**.

- [ ] **Step 5: Verifikasi panel hilang saat toggle dimatikan**

Matikan toggle "Kode Keluar Offline", simpan.

Expected: kartu daftar kode tidak lagi muncul; toggle-nya sendiri tetap ada.

- [ ] **Step 6: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add src/app/Controllers/Admin/KioskSettingsController.php src/app/Views/admin/kiosk/index.php
git commit -m "feat(kiosk): panel admin kode keluar offline"
```

---

### Task 6: `OfflineExitCode` — verifikasi kode di perangkat

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitCode.kt`
- Test: `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitCodeTest.kt`

**Interfaces:**
- Consumes: blok JSON `offline_exit` (Task 3); `testOptions`/`org.json` dari Task 1 Step 1
- Produces:
  - `OfflineExitCode.Entry(day: String, salt: String, hash: String)`
  - `OfflineExitCode.Envelope(enabled: Boolean, iterations: Int, entries: List<Entry>)`
  - `OfflineExitCode.parseEnvelope(raw: String?): Envelope`
  - `OfflineExitCode.dayOf(epochMillis: Long): String`
  - `OfflineExitCode.candidateDays(epochMillis: Long, anchorDay: String?): List<String>`
  - `OfflineExitCode.advanceAnchor(current: String?, incoming: String?): String?`
  - `OfflineExitCode.pbkdf2Hex(code: String, salt: String, iterations: Int): String`
  - `OfflineExitCode.verifyAgainst(input: String, envelope: Envelope, days: List<String>): String?` — mengembalikan tanggal yang cocok, atau null
  - `OfflineExitCode.FailureState(fails: Int, lockUntil: Long)`
  - `OfflineExitCode.nextFailureState(currentFails: Int, nowMillis: Long): FailureState`
  - `OfflineExitCode.KEY_ENVELOPE`, `KEY_ANCHOR`, `KEY_FAILS`, `KEY_LOCK_UNTIL`, `MAX_FAILS`, `LOCKOUT_MS`

- [ ] **Step 1: Tulis test yang gagal**

Buat `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitCodeTest.kt`.

Vektor di bawah **sama persis** dengan `src/tests/Kiosk/KioskOfflineCodeTest.php` dan sudah diverifikasi cocok lintas bahasa saat rencana ini disusun.

```kotlin
package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.security.OfflineExitCode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class OfflineExitCodeTest {

    /** Salt dipakai sebagai byte ASCII dari string hex ini, BUKAN hasil decode hex. */
    private val salt = "0123456789abcdef0123456789abcdef"

    // 2026-09-09 10:00 WIB
    private val siang = 1788922800000L
    // 2026-09-09 00:30 WIB — masih tanggal 9 di Jakarta, tapi 8 di UTC
    private val dinihari = 1788888600000L
    // 2026-09-09 23:45 WIB — masih tanggal 9 di Jakarta, tapi 10 di UTC+10
    private val larutMalam = 1788972300000L

    @Test
    fun `tanggal dihitung di zona Jakarta bukan UTC`() {
        assertEquals("2026-09-09", OfflineExitCode.dayOf(siang))
        assertEquals("2026-09-09", OfflineExitCode.dayOf(dinihari))
        assertEquals("2026-09-09", OfflineExitCode.dayOf(larutMalam))
    }

    @Test
    fun `hari kandidat mencakup kemarin hari ini dan besok`() {
        assertEquals(
            listOf("2026-09-08", "2026-09-09", "2026-09-10"),
            OfflineExitCode.candidateDays(siang, null)
        )
    }

    @Test
    fun `hari lebih tua dari jangkar dibuang`() {
        // Jangkar dari server = 2026-09-09, jadi memundurkan jam ke kemarin
        // tidak lagi membuka kode kemarin.
        assertEquals(
            listOf("2026-09-09", "2026-09-10"),
            OfflineExitCode.candidateDays(siang, "2026-09-09")
        )
    }

    @Test
    fun `jam yang dimundurkan jauh tidak menghasilkan kandidat sama sekali`() {
        assertTrue(OfflineExitCode.candidateDays(siang, "2026-10-01").isEmpty())
    }

    @Test
    fun `jangkar hanya boleh maju`() {
        assertEquals("2026-09-10", OfflineExitCode.advanceAnchor("2026-09-09", "2026-09-10"))
        assertEquals("2026-09-10", OfflineExitCode.advanceAnchor("2026-09-10", "2026-09-09"))
        assertEquals("2026-09-09", OfflineExitCode.advanceAnchor(null, "2026-09-09"))
        assertEquals("2026-09-09", OfflineExitCode.advanceAnchor("2026-09-09", null))
        assertNull(OfflineExitCode.advanceAnchor(null, null))
    }

    @Test
    fun `pbkdf2 cocok dengan vektor tetap dari sisi PHP`() {
        // kode 98255973 = password "R4hasia-Pengawas-2026" pada 2026-09-09
        assertEquals(
            "854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66",
            OfflineExitCode.pbkdf2Hex("98255973", salt, 1000)
        )
    }

    @Test
    fun `pbkdf2 cocok pada iterasi produksi`() {
        assertEquals(
            "68839ca6493f3ef3bfa077e6df34eaace3b468fec755729b01efc429d900e623",
            OfflineExitCode.pbkdf2Hex("98255973", salt, 120000)
        )
    }

    private fun envelope(enabled: Boolean = true, iterations: Int = 1000): OfflineExitCode.Envelope =
        OfflineExitCode.Envelope(
            enabled = enabled,
            iterations = iterations,
            entries = listOf(
                OfflineExitCode.Entry(
                    "2026-09-09", salt,
                    "854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66"
                ),
                OfflineExitCode.Entry(
                    "2026-09-10", salt,
                    "78b0f414f108d98ec1274ff1e6ed439b33b2d867cb9fba2409f2f97b51062806"
                )
            )
        )

    @Test
    fun `kode hari ini diterima`() {
        assertEquals(
            "2026-09-09",
            OfflineExitCode.verifyAgainst("98255973", envelope(), listOf("2026-09-09"))
        )
    }

    @Test
    fun `kode besok diterima lewat jendela kandidat`() {
        assertEquals(
            "2026-09-10",
            OfflineExitCode.verifyAgainst("11219294", envelope(), listOf("2026-09-09", "2026-09-10"))
        )
    }

    @Test
    fun `spasi di sekitar kode diabaikan`() {
        assertEquals(
            "2026-09-09",
            OfflineExitCode.verifyAgainst("  98255973 ", envelope(), listOf("2026-09-09"))
        )
    }

    @Test
    fun `kode salah ditolak`() {
        assertNull(OfflineExitCode.verifyAgainst("00000000", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `kode hari yang tidak ada di kandidat ditolak`() {
        assertNull(OfflineExitCode.verifyAgainst("11219294", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `amplop mati menolak apa pun`() {
        assertNull(OfflineExitCode.verifyAgainst("98255973", envelope(enabled = false), listOf("2026-09-09")))
    }

    @Test
    fun `masukan yang bukan delapan digit ditolak tanpa menghitung pbkdf2`() {
        // Ini juga yang menjaga percobaan password normal tetap murah.
        assertNull(OfflineExitCode.verifyAgainst("password-pengawas", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("9825597", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("982559730", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `amplop dari json server terbaca`() {
        val raw = """
            {"enabled":true,"iterations":1000,"days":[
              {"day":"2026-09-09","salt":"$salt","hash":"854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66"}
            ]}
        """.trimIndent()

        val parsed = OfflineExitCode.parseEnvelope(raw)

        assertTrue(parsed.enabled)
        assertEquals(1000, parsed.iterations)
        assertEquals(1, parsed.entries.size)
        assertEquals("2026-09-09", parsed.entries[0].day)
    }

    @Test
    fun `amplop mati atau rusak menghasilkan envelope tidak aktif`() {
        assertFalse(OfflineExitCode.parseEnvelope("""{"enabled":false,"iterations":0,"days":[]}""").enabled)
        assertFalse(OfflineExitCode.parseEnvelope("bukan json").enabled)
        assertFalse(OfflineExitCode.parseEnvelope(null).enabled)
        assertFalse(OfflineExitCode.parseEnvelope("").enabled)
    }

    @Test
    fun `empat kegagalan pertama menaikkan hitungan tanpa mengunci`() {
        for (fails in 0..3) {
            val next = OfflineExitCode.nextFailureState(fails, 1_000_000L)
            assertEquals(fails + 1, next.fails)
            assertEquals(0L, next.lockUntil)
        }
    }

    @Test
    fun `kegagalan kelima mengunci dan mereset hitungan`() {
        val next = OfflineExitCode.nextFailureState(OfflineExitCode.MAX_FAILS - 1, 1_000_000L)

        assertEquals(0, next.fails)
        assertEquals(1_000_000L + OfflineExitCode.LOCKOUT_MS, next.lockUntil)
    }

    @Test
    fun `entri amplop yang tidak lengkap dibuang`() {
        val raw = """
            {"enabled":true,"iterations":1000,"days":[
              {"day":"2026-09-09","salt":"","hash":"abc"},
              {"day":"","salt":"$salt","hash":"abc"},
              {"day":"2026-09-10","salt":"$salt","hash":"78b0f414f108d98ec1274ff1e6ed439b33b2d867cb9fba2409f2f97b51062806"}
            ]}
        """.trimIndent()

        val parsed = OfflineExitCode.parseEnvelope(raw)

        assertEquals(1, parsed.entries.size)
        assertEquals("2026-09-10", parsed.entries[0].day)
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.OfflineExitCodeTest"
```

Expected: FAIL — `Unresolved reference: OfflineExitCode`.

- [ ] **Step 3: Tulis `OfflineExitCode`**

Buat `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitCode.kt`:

```kotlin
package id.sch.cbt.kiosk.security

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

/**
 * Verifikasi kode keluar offline di perangkat.
 *
 * Perangkat TIDAK pernah memegang kiosk_exit_password: server mengirim amplop
 * berisi hash PBKDF2 dari kode beberapa hari ke depan, dan di sini masukan
 * pengawas dicocokkan dengan hash itu. HP yang dibongkar karenanya membocorkan
 * paling jauh sepekan kode, bukan password pengawas.
 */
object OfflineExitCode {

    private const val TAG = "OfflineExitCode"

    /**
     * Dipaku, bukan mengikuti zona perangkat: kalau ikut zona perangkat, siswa
     * cukup mengubah setelan zona untuk menggeser kode yang berlaku.
     */
    private const val TIMEZONE = "Asia/Jakarta"

    private const val ONE_DAY_MS = 86_400_000L

    const val PREFS = "cbt_kiosk_prefs"
    const val KEY_ENVELOPE = "kiosk_offline_envelope"
    const val KEY_ANCHOR = "kiosk_server_day_anchor"
    const val KEY_FAILS = "offline_exit_fails"
    const val KEY_LOCK_UNTIL = "offline_exit_lock_until"

    /** Mencerminkan MAX_EXIT_FAILS / EXIT_LOCKOUT_SECONDS di KioskController.php:12-14. */
    const val MAX_FAILS = 5
    const val LOCKOUT_MS = 600_000L

    data class Entry(val day: String, val salt: String, val hash: String)

    data class Envelope(val enabled: Boolean, val iterations: Int, val entries: List<Entry>)

    // ---------- Bagian murni: diuji tanpa perangkat ----------

    fun dayOf(epochMillis: Long): String {
        val fmt = SimpleDateFormat("yyyy-MM-dd", Locale.US)
        fmt.timeZone = TimeZone.getTimeZone(TIMEZONE)
        return fmt.format(Date(epochMillis))
    }

    /**
     * H-1, H, H+1 menurut jam perangkat, dibuang yang lebih tua dari jangkar
     * waktu server. Jendela ±1 hari menoleransi jam yang melenceng; jangkar
     * menutup trik memundurkan jam ke tanggal yang kodenya terlanjur bocor.
     *
     * Format YYYY-MM-DD membuat urutan leksikografis sama dengan urutan
     * kronologis, jadi perbandingan string sudah cukup.
     */
    fun candidateDays(epochMillis: Long, anchorDay: String?): List<String> {
        val days = listOf(
            dayOf(epochMillis - ONE_DAY_MS),
            dayOf(epochMillis),
            dayOf(epochMillis + ONE_DAY_MS)
        )
        if (anchorDay.isNullOrBlank()) return days
        return days.filter { it >= anchorDay }
    }

    /** Jangkar hanya boleh maju; kalau tidak, ia bisa dimundurkan bersama jam. */
    fun advanceAnchor(current: String?, incoming: String?): String? {
        if (incoming.isNullOrBlank()) return current
        if (current.isNullOrBlank()) return incoming
        return if (incoming > current) incoming else current
    }

    /**
     * Salt dipakai sebagai byte UTF-8 dari string hex apa adanya — sama seperti
     * PHP yang meneruskan string itu langsung ke hash_pbkdf2. Men-decode hex di
     * salah satu sisi membuat kedua implementasi tidak akan pernah sepakat.
     */
    fun pbkdf2Hex(code: String, salt: String, iterations: Int): String {
        val spec = PBEKeySpec(code.toCharArray(), salt.toByteArray(Charsets.UTF_8), iterations, 256)
        val factory = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256")
        val bytes = factory.generateSecret(spec).encoded
        val sb = StringBuilder(bytes.size * 2)
        for (b in bytes) {
            sb.append("0123456789abcdef"[(b.toInt() shr 4) and 0x0F])
            sb.append("0123456789abcdef"[b.toInt() and 0x0F])
        }
        return sb.toString()
    }

    private fun constantTimeEquals(a: String, b: String): Boolean {
        if (a.length != b.length) return false
        var diff = 0
        for (i in a.indices) diff = diff or (a[i].code xor b[i].code)
        return diff == 0
    }

    /**
     * Mengembalikan tanggal yang cocok, atau null. Gerbang "8 digit" di depan
     * bukan sekadar validasi: ia yang membuat percobaan password normal tidak
     * membayar tiga kali PBKDF2.
     */
    fun verifyAgainst(input: String, envelope: Envelope, days: List<String>): String? {
        if (!envelope.enabled || envelope.iterations <= 0) return null

        val cleaned = input.trim()
        if (cleaned.length != 8 || !cleaned.all { it in '0'..'9' }) return null

        for (day in days) {
            val entry = envelope.entries.firstOrNull { it.day == day } ?: continue
            try {
                val computed = pbkdf2Hex(cleaned, entry.salt, envelope.iterations)
                if (constantTimeEquals(computed, entry.hash.lowercase(Locale.US))) return day
            } catch (e: Throwable) {
                Log.e(TAG, "Gagal menghitung PBKDF2 untuk $day", e)
            }
        }
        return null
    }

    fun parseEnvelope(raw: String?): Envelope {
        val kosong = Envelope(false, 0, emptyList())
        if (raw.isNullOrBlank()) return kosong

        return try {
            val o = JSONObject(raw)
            val enabled = o.optBoolean("enabled", false)
            val iterations = o.optInt("iterations", 0)
            val arr: JSONArray = o.optJSONArray("days") ?: JSONArray()

            val list = ArrayList<Entry>(arr.length())
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                val day = e.optString("day", "")
                val salt = e.optString("salt", "")
                val hash = e.optString("hash", "")
                if (day.isNotBlank() && salt.isNotBlank() && hash.isNotBlank()) {
                    list.add(Entry(day, salt, hash))
                }
            }

            if (!enabled || iterations <= 0 || list.isEmpty()) Envelope(false, iterations, list)
            else Envelope(true, iterations, list)
        } catch (e: Throwable) {
            Log.w(TAG, "Amplop offline tidak terbaca", e)
            kosong
        }
    }

    // ---------- Bagian yang menyentuh prefs ----------

    fun storeEnvelope(context: Context, rawJson: String?) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (rawJson.isNullOrBlank()) {
            prefs.edit().remove(KEY_ENVELOPE).apply()
        } else {
            prefs.edit().putString(KEY_ENVELOPE, rawJson).apply()
        }
    }

    fun storedEnvelope(context: Context): Envelope =
        parseEnvelope(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_ENVELOPE, null))

    fun rememberServerDay(context: Context, serverDay: String?) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val next = advanceAnchor(prefs.getString(KEY_ANCHOR, null), serverDay) ?: return
        prefs.edit().putString(KEY_ANCHOR, next).apply()
    }

    fun isLockedOut(context: Context, nowMillis: Long = System.currentTimeMillis()): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong(KEY_LOCK_UNTIL, 0L) > nowMillis

    data class FailureState(val fails: Int, val lockUntil: Long)

    /**
     * Murni supaya bisa diuji: SharedPreferences tidak tersedia di unit test JVM,
     * sementara justru aturan "kelima mengunci" inilah yang perlu diuji.
     *
     * `lockUntil == 0L` berarti kuncian tidak berubah — pemanggil tidak boleh
     * menuliskannya, karena itu akan menghapus kuncian yang sedang berjalan.
     */
    fun nextFailureState(currentFails: Int, nowMillis: Long): FailureState {
        val fails = currentFails + 1
        return if (fails >= MAX_FAILS) FailureState(0, nowMillis + LOCKOUT_MS)
        else FailureState(fails, 0L)
    }

    /**
     * Dicatat di prefs, bukan di memori, supaya menutup-buka dialog tidak
     * mengatur ulang penghitung.
     */
    fun recordFailure(context: Context, nowMillis: Long = System.currentTimeMillis()) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val next = nextFailureState(prefs.getInt(KEY_FAILS, 0), nowMillis)
        val editor = prefs.edit().putInt(KEY_FAILS, next.fails)
        if (next.lockUntil > 0L) {
            editor.putLong(KEY_LOCK_UNTIL, next.lockUntil)
        }
        editor.apply()
    }

    fun clearFailures(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit().putInt(KEY_FAILS, 0).putLong(KEY_LOCK_UNTIL, 0L).apply()
    }

    /**
     * Verifikasi lengkap terhadap amplop tersimpan. Mengembalikan tanggal kode
     * yang cocok, atau null bila ditolak / terkunci / jalur offline mati.
     */
    fun attempt(context: Context, input: String, nowMillis: Long = System.currentTimeMillis()): String? {
        if (isLockedOut(context, nowMillis)) return null

        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val envelope = storedEnvelope(context)
        if (!envelope.enabled) return null

        val days = candidateDays(nowMillis, prefs.getString(KEY_ANCHOR, null))
        if (days.isEmpty()) return null

        return verifyAgainst(input, envelope, days)
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.OfflineExitCodeTest"
```

Expected: PASS, seluruh test hijau. Test `pbkdf2 cocok pada iterasi produksi` memakan ratusan milidetik — itu memang biayanya.

- [ ] **Step 5: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitCode.kt cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitCodeTest.kt
git commit -m "feat(kiosk): verifikasi kode keluar offline di perangkat"
```

---

### Task 7: `OfflineExitAudit` — antrian dan pengiriman jejak

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitAudit.kt`
- Test: `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitAuditTest.kt`

**Interfaces:**
- Consumes: endpoint `POST /api/kiosk/offline-exit-log` (Task 4); `OfflineExitCode.PREFS` (Task 6)
- Produces:
  - `OfflineExitAudit.appendToQueue(rawQueue: String?, at: Long, codeDay: String, appVersion: String): String` — murni
  - `OfflineExitAudit.queueSize(rawQueue: String?): Int`
  - `OfflineExitAudit.record(context: Context, codeDay: String, appVersion: String)`
  - `OfflineExitAudit.flush(context: Context, baseUrl: String, deviceId: String)` — dipanggil Task 8
  - `OfflineExitAudit.MAX_QUEUE`

- [ ] **Step 1: Tulis test yang gagal**

Buat `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitAuditTest.kt`:

```kotlin
package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.security.OfflineExitAudit
import org.json.JSONArray
import org.junit.Assert.assertEquals
import org.junit.Test

class OfflineExitAuditTest {

    @Test
    fun `antrian kosong menerima entri pertama`() {
        val q = OfflineExitAudit.appendToQueue(null, 1757400000L, "2026-09-09", "1.0.0")
        val arr = JSONArray(q)

        assertEquals(1, arr.length())
        assertEquals(1757400000L, arr.getJSONObject(0).getLong("at"))
        assertEquals("2026-09-09", arr.getJSONObject(0).getString("code_day"))
        assertEquals("1.0.0", arr.getJSONObject(0).getString("app_version"))
    }

    @Test
    fun `entri baru ditambahkan di belakang`() {
        var q = OfflineExitAudit.appendToQueue(null, 1L, "2026-09-09", "1.0.0")
        q = OfflineExitAudit.appendToQueue(q, 2L, "2026-09-10", "1.0.0")

        val arr = JSONArray(q)
        assertEquals(2, arr.length())
        assertEquals(1L, arr.getJSONObject(0).getLong("at"))
        assertEquals(2L, arr.getJSONObject(1).getLong("at"))
    }

    @Test
    fun `antrian dibatasi dan membuang yang terlama`() {
        var q: String? = null
        for (i in 1..(OfflineExitAudit.MAX_QUEUE + 5)) {
            q = OfflineExitAudit.appendToQueue(q, i.toLong(), "2026-09-09", "1.0.0")
        }

        val arr = JSONArray(q)
        assertEquals(OfflineExitAudit.MAX_QUEUE, arr.length())
        // Lima terlama terbuang, jadi entri pertama sekarang bernilai 6.
        assertEquals(6L, arr.getJSONObject(0).getLong("at"))
    }

    @Test
    fun `antrian rusak tidak menghilangkan entri baru`() {
        val q = OfflineExitAudit.appendToQueue("bukan json", 42L, "2026-09-09", "1.0.0")
        val arr = JSONArray(q)

        assertEquals(1, arr.length())
        assertEquals(42L, arr.getJSONObject(0).getLong("at"))
    }

    @Test
    fun `ukuran antrian terbaca`() {
        assertEquals(0, OfflineExitAudit.queueSize(null))
        assertEquals(0, OfflineExitAudit.queueSize("bukan json"))
        assertEquals(1, OfflineExitAudit.queueSize(OfflineExitAudit.appendToQueue(null, 1L, "2026-09-09", "1.0.0")))
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.OfflineExitAuditTest"
```

Expected: FAIL — `Unresolved reference: OfflineExitAudit`.

- [ ] **Step 3: Tulis `OfflineExitAudit`**

Buat `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitAudit.kt`:

```kotlin
package id.sch.cbt.kiosk.security

import android.content.Context
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

/**
 * Jejak pemakaian kode keluar offline.
 *
 * Kode offline berlaku bahkan saat server sehat (keputusan #3 di spec), jadi
 * tanpa jejak ini ia menjadi pintu yang tidak meninggalkan bekas apa pun.
 * Antrian ditahan di prefs sampai server sempat menerimanya.
 */
object OfflineExitAudit {

    private const val TAG = "OfflineExitAudit"

    const val KEY_QUEUE = "pending_offline_exits"

    /** Sama dengan MAX_EVENTS_PER_REQUEST di KioskOfflineCode.php. */
    const val MAX_QUEUE = 20

    private const val TIMEOUT_MS = 8000

    // ---------- Bagian murni ----------

    fun appendToQueue(rawQueue: String?, at: Long, codeDay: String, appVersion: String): String {
        val arr = try {
            if (rawQueue.isNullOrBlank()) JSONArray() else JSONArray(rawQueue)
        } catch (e: Throwable) {
            Log.w(TAG, "Antrian audit rusak, dimulai ulang", e)
            JSONArray()
        }

        arr.put(
            JSONObject()
                .put("at", at)
                .put("code_day", codeDay)
                .put("app_version", appVersion)
        )

        // Buang yang terlama bila melampaui batas: laporan terbaru lebih
        // berguna daripada yang sudah lama tertahan.
        if (arr.length() <= MAX_QUEUE) return arr.toString()

        val trimmed = JSONArray()
        for (i in (arr.length() - MAX_QUEUE) until arr.length()) {
            trimmed.put(arr.get(i))
        }
        return trimmed.toString()
    }

    fun queueSize(rawQueue: String?): Int = try {
        if (rawQueue.isNullOrBlank()) 0 else JSONArray(rawQueue).length()
    } catch (e: Throwable) {
        0
    }

    // ---------- Bagian yang menyentuh prefs & jaringan ----------

    fun record(context: Context, codeDay: String, appVersion: String) {
        try {
            val prefs = context.getSharedPreferences(OfflineExitCode.PREFS, Context.MODE_PRIVATE)
            val next = appendToQueue(
                prefs.getString(KEY_QUEUE, null),
                System.currentTimeMillis() / 1000L,
                codeDay,
                appVersion
            )
            prefs.edit().putString(KEY_QUEUE, next).apply()
        } catch (e: Throwable) {
            Log.e(TAG, "Gagal mencatat pemakaian kode offline", e)
        }
    }

    /**
     * Dikirim di thread latar. Antrian hanya dikosongkan setelah server membalas
     * 2xx — kegagalan jaringan tidak boleh menghilangkan jejak.
     */
    fun flush(context: Context, baseUrl: String, deviceId: String) {
        if (baseUrl.isBlank() || deviceId.isBlank()) return

        val prefs = context.getSharedPreferences(OfflineExitCode.PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_QUEUE, null)
        if (queueSize(raw) == 0) return

        kotlin.concurrent.thread(start = true, isDaemon = true, name = "OfflineExitAuditFlush") {
            var connection: HttpURLConnection? = null
            try {
                val payload = JSONObject()
                    .put("device_id", deviceId)
                    .put("events", JSONArray(raw))
                    .toString()

                connection = (URL("$baseUrl/api/kiosk/offline-exit-log").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    connectTimeout = TIMEOUT_MS
                    readTimeout = TIMEOUT_MS
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json")
                }

                OutputStreamWriter(connection.outputStream, Charsets.UTF_8).use { it.write(payload) }

                val code = connection.responseCode
                if (code in 200..299) {
                    prefs.edit().remove(KEY_QUEUE).apply()
                    Log.w(TAG, "Jejak kode offline terkirim ($code)")
                } else {
                    Log.w(TAG, "Server menolak jejak kode offline: $code; antrian ditahan")
                }
            } catch (e: Throwable) {
                Log.w(TAG, "Gagal mengirim jejak kode offline; antrian ditahan", e)
            } finally {
                try { connection?.disconnect() } catch (e: Throwable) { /* diabaikan */ }
            }
        }
    }
}
```

> Log memakai `Log.w`, bukan `Log.d`/`Log.i`: perangkat uji di proyek ini menyaring level debug/info, sehingga instrumentasi di level itu tidak akan terlihat di logcat.

- [ ] **Step 4: Jalankan test, pastikan lulus**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew testDebugUnitTest --tests "id.sch.cbt.kiosk.OfflineExitAuditTest"
```

Expected: PASS, 5 test.

- [ ] **Step 5: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/security/OfflineExitAudit.kt cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitAuditTest.kt
git commit -m "feat(kiosk): antrian jejak pemakaian kode keluar offline"
```

---

### Task 8: Sambungkan ke `MainActivity`

Task terakhir: menyalakan seluruh rangkaian.

**Files:**
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt` (`fetchServerKioskConfig` ±763-803, `applyKioskConfig` blok features ±837-856, `verifyExitPassword` ±395-418, dialog keluar ±301-337)
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HeartbeatManager.kt` (pembaruan jangkar waktu)
- Modify: `cbt-kiosk-app/app/src/main/res/values/strings.xml`

**Interfaces:**
- Consumes: `OfflineExitCode.attempt/storeEnvelope/rememberServerDay/recordFailure/clearFailures` (Task 6); `OfflineExitAudit.record/flush` (Task 7); blok `offline_exit` (Task 3)
- Produces: — (task penutup)

- [ ] **Step 1: Tambahkan string**

Di `strings.xml`, sebelum `</resources>`:

```xml
    <string name="exit_dialog_hint_with_code">Password Pengawas atau Kode Offline</string>
    <string name="toast_kiosk_unlocked_offline">Kiosk dibuka dengan kode offline</string>
```

- [ ] **Step 2: Simpan jangkar waktu server dari config fetch**

Di `MainActivity.fetchServerKioskConfig`, ubah blok penanganan respons (±baris 787-792) menjadi:

```kotlin
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
```

Tambahkan helper di `MainActivity` (letakkan dekat `getOrCreateDeviceId`):

```kotlin
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
```

Tambahkan import:

```kotlin
import id.sch.cbt.kiosk.security.OfflineExitAudit
import id.sch.cbt.kiosk.security.OfflineExitCode
```

- [ ] **Step 3: Simpan amplop dari config**

Di `applyKioskConfig`, sesudah blok `features?.let { ... }` selesai (sebelum `if (!SirenAlarmManager.isSirenEnabled)`), tambahkan:

```kotlin
            // Toggle mati dikirim eksplisit oleh server, sehingga mematikannya
            // di admin benar-benar mencabut amplop dari perangkat.
            val offlineExit = json.optJSONObject("offline_exit")
            if (offlineExit != null) {
                if (offlineExit.optBoolean("enabled", false)) {
                    OfflineExitCode.storeEnvelope(this, offlineExit.toString())
                } else {
                    OfflineExitCode.storeEnvelope(this, null)
                }
            }
```

- [ ] **Step 4: Perbarui jangkar dari heartbeat**

`HeartbeatManager.postJson` (baris 143-156) hanya mengembalikan kode status dan menutup koneksinya di blok `finally`, jadi pemanggilnya tidak punya apa pun untuk dibaca header-nya. Jangkar harus dibaca di dalam method itu, sebelum `disconnect()`.

Ganti isi `postJson` menjadi:

```kotlin
    private fun postJson(url: String, body: String): Int {
        val conn = URL(url).openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = TIMEOUT_MS
            conn.readTimeout = TIMEOUT_MS
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            // Header Date adalah satu-satunya sumber waktu tepercaya yang
            // dimiliki perangkat, dan heartbeat tiap 15 detik adalah kesempatan
            // paling sering memperbaruinya. Harus dibaca di sini: pemanggil
            // hanya menerima kode status, dan koneksinya sudah ditutup.
            id.sch.cbt.kiosk.security.OfflineExitCode.rememberServerDay(
                activity,
                serverDayFromHeader(conn.getHeaderField("Date"))
            )
            code
        } finally {
            conn.disconnect()
        }
    }
```

dan tambahkan helper privat di kelas itu:

```kotlin
    /** Header HTTP Date (RFC 1123, selalu GMT) → tanggal YYYY-MM-DD di zona sekolah. */
    private fun serverDayFromHeader(dateHeader: String?): String? {
        if (dateHeader.isNullOrBlank()) return null
        return try {
            val parser = java.text.SimpleDateFormat("EEE, dd MMM yyyy HH:mm:ss zzz", java.util.Locale.US)
            parser.timeZone = java.util.TimeZone.getTimeZone("GMT")
            val parsed = parser.parse(dateHeader) ?: return null
            id.sch.cbt.kiosk.security.OfflineExitCode.dayOf(parsed.time)
        } catch (e: Throwable) {
            Log.w(TAG, "Header Date tidak terbaca: $dateHeader", e)
            null
        }
    }
```

- [ ] **Step 5: Cocokkan kode offline lebih dulu di verifikasi**

Ganti isi `verifyExitPassword` (±baris 395-418) — pertahankan namanya agar pemanggil tidak berubah, tetapi ubah perilakunya:

```kotlin
    /**
     * Verifikasi kredensial pembuka kiosk: kode offline lebih dulu, baru server.
     *
     * Urutannya disengaja. Jalur ini justru dipakai saat server tidak
     * terjangkau; mendahulukan server berarti pengawas menunggu dua kali
     * timeout 8 detik sebelum kode yang benar diterima — persis pada momen
     * paling menegangkan. Gerbang "8 digit" di dalam OfflineExitCode membuat
     * percobaan password biasa tidak membayar biaya PBKDF2.
     */
    private fun verifyExitPassword(password: String, callback: (Boolean, String?) -> Unit) {
        val baseUrl = prefs.getString("server_url", "") ?: ""
        if (password.isBlank()) {
            callback(false, getString(R.string.toast_password_empty))
            return
        }

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
```

Tambahkan import `id.sch.cbt.kiosk.BuildConfig` bila belum ada.

- [ ] **Step 6: Tampilkan pesan sukses yang benar & perbarui hint**

Di `showExitPasswordDialog` (±baris 312-322), ganti cabang sukses agar memakai pesan yang dikirim callback:

```kotlin
                verifyExitPassword(enteredPassword) { allowed, message ->
                    runOnUiThread {
                        if (allowed) {
                            SirenAlarmManager.stopSiren()
                            kioskManager.stopKiosk()
                            Toast.makeText(this, message ?: getString(R.string.toast_kiosk_unlocked), Toast.LENGTH_SHORT).show()
                        } else {
                            SirenAlarmManager.playWarningBeep(this)
                            Toast.makeText(this, message ?: getString(R.string.toast_wrong_password), Toast.LENGTH_LONG).show()
                        }
                    }
                }
```

dan ubah hint field agar pengawas tahu kode offline diterima di kolom yang sama (±baris 309):

```kotlin
            input.hint = if (OfflineExitCode.storedEnvelope(this).enabled) {
                getString(R.string.exit_dialog_hint_with_code)
            } else {
                getString(R.string.exit_dialog_hint)
            }
```

- [ ] **Step 7: Kompilasi dan jalankan seluruh unit test**

```bash
cd /home/rozen/conquer/CBT-MF/cbt-kiosk-app && ./gradlew assembleDebug testDebugUnitTest
```

Expected: BUILD SUCCESSFUL; empat kelas test (`DeviceIdentityTest`, `HomeLauncherGuardTest`, `OfflineExitCodeTest`, `OfflineExitAuditTest`) lulus.

- [ ] **Step 8: Commit**

```bash
cd /home/rozen/conquer/CBT-MF && git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HeartbeatManager.kt cbt-kiosk-app/app/src/main/res/values/strings.xml
git commit -m "feat(kiosk): terima kode keluar offline di dialog pembuka kiosk"
```

- [ ] **Step 9: Uji manual di perangkat**

Bagian ini dijalankan pemilik produk di HP; sampaikan sebagai langkah bernomor dan jangan mengemudikan perangkat lewat `adb input`.

Pasang APK debug (`cbt-kiosk-app/app/build/outputs/apk/debug/app-debug.apk`), lalu:

1. Di admin, nyalakan **Kode Keluar Offline**, set password pengawas ke sesuatu yang kuat, catat kode hari ini dari panel.
2. Di HP, buka aplikasi dan mulai ujian sampai kiosk terkunci. (Config fetch di langkah ini yang mengantarkan amplop.)
3. **Matikan WiFi dan data seluler.**
4. Tekan tombol Keluar, masukkan kode hari ini.
   Expected: kiosk terbuka, toast "Kiosk dibuka dengan kode offline", **tanpa sirene**.
5. Expected: dialog "Kembalikan Layar Utama" muncul. Tekan "Buka Pengaturan" dan pastikan layar pemilihan Home app terbuka.
6. Kembalikan launcher bawaan, buka lagi aplikasi ujian.
   Expected: dialog pemulihan tidak muncul lagi.
7. Nyalakan kembali jaringan, buka aplikasi, tunggu config fetch.
8. Di admin, buka log aktivitas.
   Expected: ada entri `kiosk_offline_exit` dengan tanggal kode yang dipakai.
9. Ulangi langkah 3-4 dengan kode **salah** lima kali.
   Expected: percobaan keenam ditolak walau kodenya benar (kuncian 10 menit), dan menutup lalu membuka dialog tidak mengatur ulang hitungan.

---

## Catatan Penutup

Sesudah seluruh task selesai, gunakan `superpowers:finishing-a-development-branch` untuk memutuskan cara mengintegrasikan branch `feat/kiosk-launcher-restore-offline-exit`.

Dua hal yang **tidak** dikerjakan rencana ini, dan memang bukan bagiannya:

- Toggle `kiosk_overlay_guard_enabled` tetap dikirim server dan tetap diabaikan aplikasi. Itu cacat terpisah dan pantas mendapat perbaikannya sendiri.
- Kekuatan kode offline tetap sepenuhnya bergantung pada entropi `kiosk_exit_password`, yang hanya diperingatkan dan tidak pernah dipaksakan (keputusan #5 di spec). Bila sekolah menyalakan jalur offline sambil mempertahankan password lemah, jalur itu memang menjadi pintu belakang — dan itu keputusan sadar yang tercatat di §2.1 spec.
