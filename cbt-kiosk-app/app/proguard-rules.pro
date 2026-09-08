# Keep methods exposed to the trusted exam UI through WebView.
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
