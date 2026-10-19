# Keep the methods the web app calls through window.GVIAndroid
-keepclassmembers class com.gvifanhub.app.WebAppBridge {
    @android.webkit.JavascriptInterface <methods>;
}
-keepattributes JavascriptInterface
