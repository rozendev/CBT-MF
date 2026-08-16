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
                val ok = verifyAndInstall(target)
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

    /** Pipeline verify+extract untuk download/import. Return true bila berhasil. */
    @Synchronized
    fun verifyAndInstall(zipFile: File): Boolean {
        return try {
            if (!zipFile.exists()) { fail("File bundle tidak ditemukan."); return false }
            Log.d(TAG, "verifyAndInstall: mulai, zip=${zipFile.length()} byte")
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

    fun importBundle(uri: Uri): Boolean {
        return try {
            val tmp = File(context.cacheDir, "import-ui-bundle.zip")
            tmp.delete()
            context.contentResolver.openInputStream(uri)?.use { input ->
                tmp.outputStream().use { output -> input.copyTo(output) }
            } ?: run { onError("Gagal membaca file bundle."); return false }
            verifyAndInstall(tmp)
        } catch (e: Throwable) {
            onError("Gagal import bundle: ${e.message}")
            false
        }
    }
}