package id.sch.cbt.kiosk.bundle

import android.app.DownloadManager
import android.content.Context
import android.content.SharedPreferences
import android.net.Uri
import android.os.Environment
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Menyediakan bundle UI lokal:
 *  - download via DownloadManager (resume native) / import dari file picker
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
        const val DL_DIR = "ui-bundle-download"
    }

    private val bundleDir: File get() = File(context.filesDir, BUNDLE_DIR)
    private val stagingDir: File get() = File(context.filesDir, STAGING_DIR)
    private val dlDir: File get() = File(context.filesDir, DL_DIR)

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

    /** Panggil saat config server menyebut versi baru / versi belum ada. */
    fun downloadViaDownloadManager(serverBaseUrl: String, zipUrl: String, expectedVersion: String) {
        dlDir.mkdirs()
        val request = DownloadManager.Request(Uri.parse(zipUrl))
            .setTitle("UI Bundle")
            .setDescription("Mengunduh paket UI ujian ($expectedVersion)")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
            .setAllowedOverMetered(true)
            .setDestinationInExternalFilesDir(context, Environment.DIRECTORY_DOWNLOADS, "ui-bundle.zip")
        val dm = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        dm.enqueue(request)
        Log.i(TAG, "DownloadManager enqueue: $zipUrl (v$expectedVersion)")
    }

    /** Pipeline verify+extract untuk download/import. Return true bila berhasil. */
    @Synchronized
    fun verifyAndInstall(zipFile: File): Boolean {
        return try {
            if (!zipFile.exists()) { onError("File bundle tidak ditemukan."); return false }
            // ekstrak ke staging (hapus staging lama dulu)
            stagingDir.deleteRecursively()
            stagingDir.mkdirs()
            val zin = java.util.zip.ZipInputStream(zipFile.inputStream().buffered())
            var entry = zin.nextEntry
            while (entry != null) {
                val target = File(stagingDir, entry.name)
                if (!target.canonicalPath.startsWith(stagingDir.canonicalPath + File.separator) && entry.name != "manifest.json") {
                    onError("Bundle tidak valid (path traversal)."); zin.close(); return false
                }
                if (!entry.isDirectory) {
                    target.parentFile?.mkdirs()
                    target.outputStream().use { out -> zin.copyTo(out) }
                }
                entry = zin.nextEntry
            }
            zin.close()

            // verifikasi per-file vs manifest
            val mf = File(stagingDir, "manifest.json")
            if (!mf.exists()) { onError("manifest.json tidak ada di bundle."); return false }
            val manifest = JSONObject(mf.readText())
            val files = manifest.getJSONObject("files")
            val expectedVersion = manifest.getString("version")
            val it = files.keys()
            while (it.hasNext()) {
                val rel = it.next()
                val f = File(stagingDir, rel)
                if (!f.exists()) { onError("File hilang di bundle: $rel"); return false }
                val actual = sha256(f.readBytes())
                if (actual != files.getString(rel)) { onError("Hash tidak cocok: $rel"); return false }
            }

            // atomic rename: hapus staging lama bila ada sisa, ganti ui-bundle
            val backup = File(context.filesDir, "ui-bundle.old")
            backup.deleteRecursively()
            if (bundleDir.exists()) bundleDir.renameTo(backup)
            if (!stagingDir.renameTo(bundleDir)) {
                if (backup.exists()) backup.renameTo(bundleDir)
                onError("Gagal menginstal bundle (rename).")
                return false
            }
            backup.deleteRecursively()
            prefs.edit().putString(PREFS_VERSION, expectedVersion).apply()
            zipFile.delete()
            onReady(true)
            Log.i(TAG, "Bundle v$expectedVersion terinstal")
            true
        } catch (e: Throwable) {
            onError("Gagal memproses bundle: ${e.message}")
            false
        }
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