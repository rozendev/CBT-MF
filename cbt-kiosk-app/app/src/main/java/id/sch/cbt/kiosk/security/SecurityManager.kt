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

    fun handleMultiWindow(isInMultiWindowMode: Boolean, isInPictureInPictureMode: Boolean = false) {
        if (isInMultiWindowMode || isInPictureInPictureMode) {
            CommsBridge.sendEventToJS(activity.webView, "security_alert", "{\"type\": \"SPLIT_SCREEN_DETECTED\"}")
        }
    }
}
