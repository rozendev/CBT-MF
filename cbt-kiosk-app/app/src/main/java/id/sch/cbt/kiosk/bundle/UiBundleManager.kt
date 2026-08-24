package id.sch.cbt.kiosk.bundle

import android.content.Context
import android.content.SharedPreferences
import android.net.Uri
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Menyediakan bundle UI lokal:
 *  - download langsung (HttpURLConnection, tak bergantung DownloadManager OEM)
 *    / import dari file picker; keduanya memakai pipeline verify+extract yang sama
 *  - verifikasi sha256 per file vs manifest.json (satu pipeline untuk keduanya)
 *  - extract ke staging dir lalu ATOMIC rename ke ui-bundle/
 */
class UiBundleManager(
    private val context: Context,
    private val prefs: SharedPreferences,
    private val onReady: (Boolean) -> Unit,   // true = bundle siap digunakan
    private val onError: (String) -> Unit
) {
    companion object {
        private const val TAG = "UiBundleManager"
        private const val PREFS_VERSION = "ui_bundle_version"
        private const val PREFS_EXAM_ACTIVE = "ui_exam_active"
        /** sha256 zip resmi terakhir yang diumumkan server lewat /api/kiosk/config. */
        private const val PREFS_EXPECTED_SHA = "ui_bundle_expected_sha256"
        const val BUNDLE_DIR = "ui-bundle"
        const val STAGING_DIR = "ui-bundle.staging"
    }

    private val bundleDir: File get() = File(context.filesDir, BUNDLE_DIR)
    private val stagingDir: File get() = File(context.filesDir, STAGING_DIR)

    var examActive: Boolean
        get() = prefs.getBoolean(PREFS_EXAM_ACTIVE, false)
        set(v) {
            prefs.edit().putBoolean(PREFS_EXAM_ACTIVE, v).apply()
            Log.i(TAG, "examActive = $v")
        }

    /** Gate: refresh bundle hanya di cold start / tanpa attempt aktif. */
    fun canRefresh(): Boolean = !examActive

    fun bundleVersion(): String? = prefs.getString(PREFS_VERSION, null)

    /**
     * sha256 zip resmi yang terakhir diumumkan server. Dipin saat config diambil
     * lewat HTTPS, lalu dipakai sebagai jangkar untuk side-load offline.
     */
    var expectedZipSha: String?
        get() = prefs.getString(PREFS_EXPECTED_SHA, null)?.takeIf { it.isNotBlank() }
        set(v) {
            if (v.isNullOrBlank()) return
            prefs.edit().putString(PREFS_EXPECTED_SHA, v.lowercase()).apply()
        }

    /** Baca manifest lokal untuk bandingkan versi. */
    fun localVersion(): String? {
        return try {
            val mf = File(bundleDir, "manifest.json")
            if (!mf.exists()) return null
            JSONObject(mf.readText()).optString("version", "").takeIf { it.isNotBlank() }
        } catch (e: Throwable) {
            Log.w(TAG, "localVersion error", e); null
        }
    }

    /** Unduh langsung via HttpURLConnection (bukan DownloadManager sistem: di banyak OEM
     *  layanan DM dimatikan/tidak berjalan → download macet tanpa error apapun).
     *  Jalankan di thread daemon; progress/done dijembatani ke UI oleh pemanggil. */
    fun downloadDirect(
        zipUrl: String,
        expectedVersion: String,
        expectedZipSha256: String?,
        onProgress: (Long, Long) -> Unit,
        onDone: (Boolean, String?) -> Unit
    ) {
        kotlin.concurrent.thread(start = true, isDaemon = true, name = "BundleDownload") {
            val target = File(context.cacheDir, "dl-ui-bundle-v$expectedVersion.zip")
            var conn: java.net.HttpURLConnection? = null
            try {
                val url = java.net.URL(zipUrl)
                conn = url.openConnection() as java.net.HttpURLConnection
                conn.connectTimeout = 15000
                conn.readTimeout = 30000
                conn.setRequestProperty("Accept", "application/zip")
                conn.setRequestProperty("Accept-Encoding", "identity")
                val code = conn.responseCode
                if (code != 200) {
                    Log.w(TAG, "Download HTTP $code untuk $zipUrl")
                    onDone(false, "Server menjawab HTTP $code.")
                    return@thread
                }
                val total = conn.contentLengthLong
                target.delete()
                target.parentFile?.mkdirs()
                val input = conn.inputStream.buffered()
                val output = target.outputStream().buffered()
                val buf = ByteArray(64 * 1024)
                var done: Long = 0
                while (true) {
                    val read = input.read(buf)
                    if (read < 0) break
                    output.write(buf, 0, read)
                    done += read
                    if (total > 0) onProgress(done, total)
                }
                output.flush()
                output.close()
                input.close()
                Log.i(TAG, "Download selesai: ${target.length()} byte (v$expectedVersion)")
                val ok = verifyAndInstall(target, expectedZipSha256)
                onDone(ok, if (ok) null else "Verifikasi bundle gagal.")
            } catch (e: Throwable) {
                Log.e(TAG, "Download bundle gagal", e)
                target.delete()
                onDone(false, "Gagal mengunduh: ${e.message}")
            } finally {
                try {
                    conn?.disconnect()
                } catch (_: Throwable) {
                }
            }
        }
    }

    /**
     * Pipeline verify+extract untuk download/import. Return true bila berhasil.
     *
     * [expectedZipSha256] WAJIB dan harus berasal dari luar zip (config server
     * lewat HTTPS). Tanpa itu verifikasi tidak membuktikan apa pun: manifest.json
     * ikut terbungkus di dalam zip yang sama, sehingga penyusun zip palsu
     * mengendalikan payload sekaligus tabel hash pembandingnya dan selalu lolos.
     * Bundle yang lolos dipasang ke origin appassets.androidplatform.net yang
     * memegang CommsBridge, jadi kegagalan di sini berarti kendali penuh atas
     * klien ujian.
     */
    @Synchronized
    fun verifyAndInstall(zipFile: File, expectedZipSha256: String?): Boolean {
        return try {
            if (!zipFile.exists()) { fail("File bundle tidak ditemukan."); return false }
            Log.d(TAG, "verifyAndInstall: mulai, zip=${zipFile.length()} byte")

            // Jangkar luar dulu, sebelum sebutir byte pun diekstrak.
            val anchor = expectedZipSha256?.trim()?.lowercase()
            if (anchor.isNullOrBlank()) {
                fail("Bundle ditolak: belum ada sidik jari resmi dari server. " +
                    "Hubungkan perangkat ke server sekali (isi URL lalu Periksa Pembaruan) sebelum impor manual.")
                zipFile.delete()
                return false
            }
            val actualZipSha = sha256(zipFile.readBytes())
            if (!constantTimeEquals(actualZipSha, anchor)) {
                fail("Bundle ditolak: sidik jari tidak cocok dengan yang diumumkan server.")
                Log.w(TAG, "verifyAndInstall: sha zip=$actualZipSha, diharapkan=$anchor")
                zipFile.delete()
                return false
            }
            // ekstrak ke staging (hapus staging lama dulu)
            stagingDir.deleteRecursively()
            stagingDir.mkdirs()
            val zin = java.util.zip.ZipInputStream(zipFile.inputStream().buffered())
            var entry = zin.nextEntry
            var entryCount = 0
            while (entry != null) {
                val target = File(stagingDir, entry.name)
                if (!target.canonicalPath.startsWith(stagingDir.canonicalPath + File.separator) && entry.name != "manifest.json") {
                    fail("Bundle tidak valid (path traversal): ${entry.name}"); zin.close(); return false
                }
                if (!entry.isDirectory) {
                    target.parentFile?.mkdirs()
                    target.outputStream().use { out -> zin.copyTo(out) }
                    entryCount++
                }
                entry = zin.nextEntry
            }
            zin.close()
            Log.d(TAG, "verifyAndInstall: ekstrak selesai, $entryCount file")

            // verifikasi per-file vs manifest
            val mf = File(stagingDir, "manifest.json")
            if (!mf.exists()) { fail("manifest.json tidak ada di bundle."); return false }
            val manifest = JSONObject(mf.readText())
            val files = manifest.getJSONObject("files")
            val expectedVersion = manifest.getString("version")
            Log.d(TAG, "verifyAndInstall: manifest version=$expectedVersion, ${files.length()} file terdaftar")
            val it = files.keys()
            while (it.hasNext()) {
                val rel = it.next()
                val f = File(stagingDir, rel)
                if (!f.exists()) { fail("File hilang di bundle: $rel"); return false }
                val actual = sha256(f.readBytes())
                val expected = files.getString(rel)
                if (actual != expected) {
                    fail("Hash tidak cocok: $rel (dapat=$actual milik manifest=$expected, size=${f.length()})")
                    return false
                }
            }

            // atomic rename: hapus staging lama bila ada sisa, ganti ui-bundle
            val backup = File(context.filesDir, "ui-bundle.old")
            backup.deleteRecursively()
            if (bundleDir.exists()) bundleDir.renameTo(backup)
            if (!stagingDir.renameTo(bundleDir)) {
                if (backup.exists()) backup.renameTo(bundleDir)
                fail("Gagal menginstal bundle (rename).")
                return false
            }
            backup.deleteRecursively()
            prefs.edit().putString(PREFS_VERSION, expectedVersion).apply()
            zipFile.delete()
            onReady(true)
            Log.i(TAG, "Bundle v$expectedVersion terinstal")
            true
        } catch (e: Throwable) {
            fail("Gagal memproses bundle: ${e.message}", e)
            false
        }
    }

    private fun fail(message: String, e: Throwable? = null) {
        if (e != null) Log.e(TAG, "verifyAndInstall gagal: $message", e) else Log.w(TAG, "verifyAndInstall gagal: $message")
        onError(message)
    }

    private fun sha256(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    /**
     * Side-load manual dari file picker. Tetap diverifikasi terhadap sidik jari
     * resmi yang sudah dipin dari server — zip yang dipilih operator tidak lebih
     * tepercaya daripada zip yang datang dari mana pun.
     */
    fun importBundle(uri: Uri): Boolean {
        return try {
            val tmp = File(context.cacheDir, "import-ui-bundle.zip")
            tmp.delete()
            context.contentResolver.openInputStream(uri)?.use { input ->
                tmp.outputStream().use { output -> input.copyTo(output) }
            } ?: run { onError("Gagal membaca file bundle."); return false }
            verifyAndInstall(tmp, expectedZipSha)
        } catch (e: Throwable) {
            onError("Gagal import bundle: ${e.message}")
            false
        }
    }

    /** Perbandingan hash tanpa short-circuit; murah dan menghilangkan satu kelas keraguan. */
    private fun constantTimeEquals(a: String, b: String): Boolean {
        if (a.length != b.length) return false
        var diff = 0
        for (i in a.indices) diff = diff or (a[i].code xor b[i].code)
        return diff == 0
    }
}