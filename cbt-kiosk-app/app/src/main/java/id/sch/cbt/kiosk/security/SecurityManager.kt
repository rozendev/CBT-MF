package id.sch.cbt.kiosk.security

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.util.Log
import android.view.WindowManager
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.bridge.CommsBridge

class SecurityManager(private val activity: MainActivity) {

    private var clipboardListener: ClipboardManager.OnPrimaryClipChangedListener? = null
    @Volatile
    private var isClearingClipboard = false

    @Volatile
    private var clipboardGuardEnabled = true

    fun setClipboardGuard(enabled: Boolean) {
        clipboardGuardEnabled = enabled
    }

    private val dndPolicyEnabled: Boolean
        get() = activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)
            .getBoolean("kiosk_enforce_dnd", true)

    private val dndPrefs
        get() = activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)

    /**
     * Menegaskan ulang DND di tengah sesi, dipanggil dari `onResume` bersama
     * `ensureLockTask()`. Siswa yang sempat menjangkau quick settings bisa
     * mematikan DND; sekali dipasang saja tidak cukup.
     */
    fun reassertDnd() {
        if (!dndPolicyEnabled) return
        DndGuard.apply(activity, dndPrefs, policyEnabled = true)
    }

    /** Status DND perangkat saat ini, untuk dilaporkan ke pengawas. */
    fun dndStatus(): String =
        DndGuard.statusFor(dndPolicyEnabled, DndGuard.currentFilter(activity))

    fun enableSecurityFlags() {
        // Sengaja DI LUAR runOnUiThread: DND tidak menyentuh window sama sekali,
        // dan penyimpanan filter aslinya memakai commit() sinkron yang tidak
        // pantas menahan main thread.
        DndGuard.apply(activity, dndPrefs, dndPolicyEnabled)

        activity.runOnUiThread {
            try {
                // 1. Block Screenshot & Screen Recording
                activity.window.setFlags(
                    WindowManager.LayoutParams.FLAG_SECURE,
                    WindowManager.LayoutParams.FLAG_SECURE
                )

                // 2. Layar wajib menyala selama kiosk aktif: layar yang mati di
                // tengah ujian memaksa siswa membuka kunci, yang pada sebagian
                // perangkat menjatuhkan lock task. addFlags dipakai (bukan
                // setFlags) agar FLAG_SECURE di atas tidak ikut tertimpa.
                // Tanpa WakeLock: flag ini hanya berlaku selama window terlihat,
                // jadi otomatis lepas saat aplikasi tidak di depan.
                activity.window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

                if (!clipboardGuardEnabled) {
                    clipboardListener?.let { oldListener ->
                        try {
                            val cb = activity.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
                            cb?.removePrimaryClipChangedListener(oldListener)
                        } catch (e: Throwable) {}
                    }
                    clipboardListener = null
                    return@runOnUiThread
                }

                // 2. Clear & Guard Clipboard
                val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
                clipboard?.let { cb ->
                    clipboardListener?.let { oldListener ->
                        try { cb.removePrimaryClipChangedListener(oldListener) } catch (e: Throwable) {}
                    }
                    
                    isClearingClipboard = true
                    try {
                        cb.setPrimaryClip(ClipData.newPlainText("", ""))
                    } catch (e: Throwable) {
                        Log.e("SecurityManager", "Failed setting primary clip", e)
                    } finally {
                        isClearingClipboard = false
                    }
                    
                    val newListener = ClipboardManager.OnPrimaryClipChangedListener {
                        if (isClearingClipboard) return@OnPrimaryClipChangedListener
                        isClearingClipboard = true
                        try {
                            cb.setPrimaryClip(ClipData.newPlainText("", ""))
                        } catch (e: Throwable) {
                            Log.e("SecurityManager", "Failed clearing primary clip in listener", e)
                        } finally {
                            isClearingClipboard = false
                        }
                    }
                    clipboardListener = newListener
                    try {
                        cb.addPrimaryClipChangedListener(newListener)
                    } catch (e: Throwable) {
                        Log.e("SecurityManager", "Failed adding clipboard listener", e)
                    }
                }
            } catch (e: Throwable) {
                Log.e("SecurityManager", "Error enabling security flags", e)
            }
        }
    }

    fun disableSecurityFlags() {
        // Perangkat HARUS kembali ke filter aslinya, termasuk ketika sesi kiosk
        // gagal dimulai (KioskManager memanggil ini di jalur FAILED). Siswa yang
        // pulang membawa HP yang senyap selamanya adalah kegagalan kita.
        DndGuard.restore(activity, dndPrefs)

        activity.runOnUiThread {
            try {
                activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                // Kiosk selesai: layar boleh mati lagi mengikuti setelan perangkat.
                activity.window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
                clipboardListener?.let { listener ->
                    try { clipboard?.removePrimaryClipChangedListener(listener) } catch (e: Throwable) {}
                    clipboardListener = null
                }
            } catch (e: Throwable) {
                Log.e("SecurityManager", "Error disabling security flags", e)
            }
        }
    }

    fun handleMultiWindow(isInMultiWindowMode: Boolean, isInPictureInPictureMode: Boolean = false) {
        if (isInMultiWindowMode || isInPictureInPictureMode) {
            activity.getSafeWebView()?.let { safeWebView ->
                CommsBridge.sendEventToJS(safeWebView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
            }
        }
    }
}
