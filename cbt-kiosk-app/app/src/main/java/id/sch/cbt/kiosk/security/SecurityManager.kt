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

    fun enableSecurityFlags() {
        activity.runOnUiThread {
            try {
                // 1. Block Screenshot & Screen Recording
                activity.window.setFlags(
                    WindowManager.LayoutParams.FLAG_SECURE,
                    WindowManager.LayoutParams.FLAG_SECURE
                )

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
        activity.runOnUiThread {
            try {
                activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
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
