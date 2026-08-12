package id.sch.cbt.kiosk.security

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.view.WindowManager
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.bridge.CommsBridge

class SecurityManager(private val activity: MainActivity) {

    private var clipboardListener: ClipboardManager.OnPrimaryClipChangedListener? = null
    @Volatile
    private var isClearingClipboard = false

    fun enableSecurityFlags() {
        activity.runOnUiThread {
            // 1. Block Screenshot & Screen Recording
            activity.window.setFlags(
                WindowManager.LayoutParams.FLAG_SECURE,
                WindowManager.LayoutParams.FLAG_SECURE
            )
            
            // 2. Clear & Guard Clipboard
            val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
            clipboard?.let { cb ->
                clipboardListener?.let { oldListener ->
                    cb.removePrimaryClipChangedListener(oldListener)
                }
                
                isClearingClipboard = true
                try {
                    cb.setPrimaryClip(ClipData.newPlainText("", ""))
                } finally {
                    isClearingClipboard = false
                }
                
                val newListener = ClipboardManager.OnPrimaryClipChangedListener {
                    if (isClearingClipboard) return@OnPrimaryClipChangedListener
                    isClearingClipboard = true
                    try {
                        cb.setPrimaryClip(ClipData.newPlainText("", ""))
                    } finally {
                        isClearingClipboard = false
                    }
                }
                clipboardListener = newListener
                cb.addPrimaryClipChangedListener(newListener)
            }
        }
    }

    fun disableSecurityFlags() {
        activity.runOnUiThread {
            activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
            val clipboard = activity.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
            clipboardListener?.let { listener ->
                clipboard?.removePrimaryClipChangedListener(listener)
                clipboardListener = null
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
