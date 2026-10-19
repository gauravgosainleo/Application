package com.gvifanhub.app;

import android.annotation.SuppressLint;
import android.annotation.TargetApi;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.Insets;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.SystemClock;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.webkit.CookieManager;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.SslErrorHandler;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;
import android.window.OnBackInvokedCallback;
import android.window.OnBackInvokedDispatcher;

import java.net.URISyntaxException;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/**
 * G VI Fan Hub - Android wrapper around the web app.
 * The web address (app_url) is set in app/build.gradle.
 */
public class MainActivity extends Activity {

    private static final int FILE_CHOOSER_REQUEST = 4201;

    private WebView webView;
    private ProgressBar progressBar;
    private View loadingView;
    private View errorView;
    private FrameLayout fullscreenContainer;
    private View customView;
    private WebChromeClient.CustomViewCallback customViewCallback;
    private ValueCallback<Uri[]> filePathCallback;

    private String appUrl;
    private String appHost;
    private boolean firstPageLoaded = false;
    private boolean mainFrameError = false;
    /** Last page address, kept here because a WebView can't be asked after its renderer is gone. */
    private String lastUrl;
    private long lastRendererCrash = 0;

    /** android.window.OnBackInvokedCallback (API 33+), kept as Object for older devices. */
    private Object backCallback;
    private boolean backCallbackRegistered = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        appUrl = getString(R.string.app_url);
        appHost = normalizeHost(Uri.parse(appUrl).getHost());

        webView = findViewById(R.id.webview);
        progressBar = findViewById(R.id.progress);
        loadingView = findViewById(R.id.loading);
        errorView = findViewById(R.id.error);
        fullscreenContainer = findViewById(R.id.fullscreen_container);
        findViewById(R.id.retry).setOnClickListener(v -> retry());

        applyWindowInsets();
        setupWebView();
        setupBackHandling();

        if (savedInstanceState != null && webView.restoreState(savedInstanceState) != null) {
            firstPageLoaded = true;
            loadingView.setVisibility(View.GONE);
        } else {
            Uri data = getIntent() != null ? getIntent().getData() : null;
            webView.loadUrl(data != null && isAppUri(data) ? data.toString() : appUrl);
        }
    }

    /** Edge-to-edge (Android 15+): keep the web content clear of the status bar, navigation bar and keyboard. */
    private void applyWindowInsets() {
        View root = findViewById(R.id.root);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            // Same layout behaviour on Android 11-14 as the enforced edge-to-edge of Android 15+.
            getWindow().setDecorFitsSystemWindows(false);
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                Insets bars = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
                Insets ime = insets.getInsets(WindowInsets.Type.ime());
                v.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, ime.bottom));
                return WindowInsets.CONSUMED;
            });
            WindowInsetsController controller = getWindow().getInsetsController();
            if (controller != null) {
                // Light icons on the dark app background.
                controller.setSystemBarsAppearance(0, WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS
                        | WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS);
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(true);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setDisplayZoomControls(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        // Lets the web app know it runs inside the Android app (hides "install" buttons etc).
        s.setUserAgentString(s.getUserAgentString() + " GVIFanHubApp/" + BuildConfig.VERSION_NAME);

        CookieManager.getInstance().setAcceptCookie(true);

        webView.setBackgroundColor(getColor(R.color.bg));
        webView.addJavascriptInterface(new WebAppBridge(this), "GVIAndroid");
        webView.setWebViewClient(new AppWebViewClient());
        webView.setWebChromeClient(new AppChromeClient());
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) ->
                download(url, userAgent, contentDisposition, mimeType));

        if (BuildConfig.DEBUG) {
            WebView.setWebContentsDebuggingEnabled(true);
        }
    }

    /* ------------------------------------------------------------ back */

    private void setupBackHandling() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            backCallback = (OnBackInvokedCallback) this::handleBack;
        }
    }

    /** Registers the back callback only while there is something to go back to (keeps predictive back for exiting). */
    void updateBackHandling() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU || backCallback == null) {
            return;
        }
        boolean needed = customView != null || (webView != null && webView.canGoBack());
        OnBackInvokedDispatcher dispatcher = getOnBackInvokedDispatcher();
        if (needed && !backCallbackRegistered) {
            dispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT, (OnBackInvokedCallback) backCallback);
            backCallbackRegistered = true;
        } else if (!needed && backCallbackRegistered) {
            dispatcher.unregisterOnBackInvokedCallback((OnBackInvokedCallback) backCallback);
            backCallbackRegistered = false;
        }
    }

    private void handleBack() {
        if (customView != null) {
            hideCustomView();
        } else if (webView.canGoBack()) {
            webView.goBack();
        } else {
            finish();
        }
        updateBackHandling();
    }

    @Override
    @SuppressLint("GestureBackNavigation")
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        // Only called on Android 12 and older; newer versions use the OnBackInvokedCallback above.
        if (customView != null || webView.canGoBack()) {
            handleBack();
        } else {
            super.onBackPressed();
        }
    }

    /* ------------------------------------------------------- lifecycle */

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        Uri data = intent.getData();
        if (data != null && isAppUri(data)) {
            webView.loadUrl(data.toString());
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
        webView.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        webView.onResume();
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.removeJavascriptInterface("GVIAndroid");
            webView.destroy();
        }
        super.onDestroy();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == FILE_CHOOSER_REQUEST && filePathCallback != null) {
            filePathCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data));
            filePathCallback = null;
        }
    }

    /* --------------------------------------------------------- helpers */

    private static String normalizeHost(String host) {
        if (host == null) {
            return "";
        }
        host = host.toLowerCase(Locale.ROOT);
        return host.startsWith("www.") ? host.substring(4) : host;
    }

    boolean isAppUri(Uri uri) {
        String scheme = uri.getScheme();
        if (!"https".equalsIgnoreCase(scheme) && !"http".equalsIgnoreCase(scheme)) {
            return false;
        }
        return normalizeHost(uri.getHost()).equals(appHost);
    }

    private void openExternal(Uri uri) {
        try {
            Intent intent;
            if ("intent".equalsIgnoreCase(uri.getScheme())) {
                intent = Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME);
                intent.addCategory(Intent.CATEGORY_BROWSABLE);
                intent.setComponent(null);
                intent.setSelector(null);
            } else {
                intent = new Intent(Intent.ACTION_VIEW, uri);
            }
            startActivity(intent);
        } catch (ActivityNotFoundException | URISyntaxException e) {
            Toast.makeText(this, R.string.no_app_for_link, Toast.LENGTH_SHORT).show();
        }
    }

    private void download(String url, String userAgent, String contentDisposition, String mimeType) {
        if (url == null || !(url.startsWith("https://") || url.startsWith("http://"))) {
            return;
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            openExternal(Uri.parse(url));
            return;
        }
        try {
            String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            request.setMimeType(mimeType);
            String cookie = CookieManager.getInstance().getCookie(url);
            if (cookie != null) {
                request.addRequestHeader("Cookie", cookie);
            }
            request.addRequestHeader("User-Agent", userAgent);
            request.setTitle(fileName);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
            DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            dm.enqueue(request);
            Toast.makeText(this, getString(R.string.downloading, fileName), Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            openExternal(Uri.parse(url));
        }
    }

    private void retry() {
        // Keep the native screen up until the page really loads (no flash of the WebView error page).
        errorView.setVisibility(View.GONE);
        loadingView.setVisibility(View.VISIBLE);
        mainFrameError = false;
        String current = webView.getUrl();
        if (current == null || current.startsWith("about:")) {
            webView.loadUrl(lastUrl != null ? lastUrl : appUrl);
        } else {
            webView.reload();
        }
    }

    private void rememberUrl(String url) {
        if (url != null && (url.startsWith("https://") || url.startsWith("http://"))) {
            lastUrl = url;
        }
    }

    /** Swaps a WebView whose renderer died for a fresh one (a dead WebView can't be used again). */
    private void replaceWebView(boolean reload) {
        if (customView != null) {
            // Its callback belongs to the dead WebView, so just drop the full-screen video.
            fullscreenContainer.removeView(customView);
            fullscreenContainer.setVisibility(View.GONE);
            customView = null;
            customViewCallback = null;
            setSystemBarsHidden(false);
        }
        ViewGroup parent = (ViewGroup) webView.getParent();
        int index = parent.indexOfChild(webView);
        parent.removeView(webView);
        webView.destroy();
        webView = new WebView(this);
        webView.setId(R.id.webview);
        parent.addView(webView, index, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));
        setupWebView();
        updateBackHandling();
        if (reload) {
            loadingView.setVisibility(View.VISIBLE);
            webView.loadUrl(lastUrl != null ? lastUrl : appUrl);
        }
    }

    private void showError() {
        loadingView.setVisibility(View.GONE);
        errorView.setVisibility(View.VISIBLE);
    }

    private void hideCustomView() {
        if (customView == null) {
            return;
        }
        fullscreenContainer.removeView(customView);
        fullscreenContainer.setVisibility(View.GONE);
        customView = null;
        if (customViewCallback != null) {
            customViewCallback.onCustomViewHidden();
            customViewCallback = null;
        }
        webView.setVisibility(View.VISIBLE);
        setSystemBarsHidden(false);
        updateBackHandling();
    }

    @SuppressWarnings("deprecation")
    private void setSystemBarsHidden(boolean on) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            WindowInsetsController c = getWindow().getInsetsController();
            if (c == null) {
                return;
            }
            if (on) {
                c.hide(WindowInsets.Type.systemBars());
                c.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            } else {
                c.show(WindowInsets.Type.systemBars());
            }
        } else {
            getWindow().getDecorView().setSystemUiVisibility(on
                    ? View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    : View.SYSTEM_UI_FLAG_VISIBLE);
        }
    }

    /* ---------------------------------------------------------- clients */

    private class AppWebViewClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            // Embedded frames (e.g. YouTube trailers) navigate on their own; only page links are checked.
            if (!request.isForMainFrame() || isAppUri(uri)) {
                return false;
            }
            String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
            if (scheme.equals("about") || scheme.equals("data") || scheme.equals("blob") || scheme.equals("javascript")) {
                return false;
            }
            openExternal(uri);
            return true;
        }

        @Override
        public void onPageStarted(WebView view, String url, Bitmap favicon) {
            mainFrameError = false;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            rememberUrl(url);
            CookieManager.getInstance().flush();
            if (!mainFrameError) {
                firstPageLoaded = true;
                loadingView.setVisibility(View.GONE);
                errorView.setVisibility(View.GONE);
            }
            updateBackHandling();
        }

        @Override
        public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
            rememberUrl(url);
            updateBackHandling();
        }

        @Override
        @TargetApi(Build.VERSION_CODES.O) // only called on Android 8+
        public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
            // The page's renderer crashed or Android killed it to free memory. Returning true keeps the
            // app alive; the dead WebView is replaced. If it keeps crashing, show the retry screen.
            long now = SystemClock.elapsedRealtime();
            boolean crashLoop = detail.didCrash() && now - lastRendererCrash < 30_000;
            if (detail.didCrash()) {
                lastRendererCrash = now;
            }
            if (view == webView) {
                replaceWebView(!crashLoop);
                if (crashLoop) {
                    mainFrameError = true;
                    showError();
                }
            } else if (view.getParent() instanceof ViewGroup) {
                ((ViewGroup) view.getParent()).removeView(view);
                view.destroy();
            }
            return true;
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            // Never load pages with a broken certificate (e.g. before HTTPS is set up in hPanel).
            handler.cancel();
            if (!firstPageLoaded || (error.getUrl() != null && error.getUrl().equals(view.getUrl()))) {
                mainFrameError = true;
                showError();
            }
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            if (request.isForMainFrame()) {
                mainFrameError = true;
                showError();
            }
        }
    }

    private class AppChromeClient extends WebChromeClient {
        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            progressBar.setProgress(newProgress);
            progressBar.setVisibility(newProgress < 100 && firstPageLoaded ? View.VISIBLE : View.GONE);
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            if (filePathCallback != null) {
                filePathCallback.onReceiveValue(null);
            }
            filePathCallback = callback;
            // Build the picker ourselves: createIntent() only keeps the first accept type,
            // which would hide videos for accept="image/*,video/mp4".
            List<String> types = new ArrayList<>();
            String[] accept = params.getAcceptTypes();
            for (String t : accept == null ? new String[0] : accept) {
                t = t.trim().toLowerCase(Locale.ROOT);
                if (t.contains("/") && !types.contains(t)) {
                    types.add(t);
                }
            }
            Intent intent = new Intent(Intent.ACTION_GET_CONTENT).addCategory(Intent.CATEGORY_OPENABLE);
            if (types.size() == 1) {
                intent.setType(types.get(0));
            } else {
                intent.setType("*/*");
                if (!types.isEmpty()) {
                    intent.putExtra(Intent.EXTRA_MIME_TYPES, types.toArray(new String[0]));
                }
            }
            try {
                startActivityForResult(Intent.createChooser(intent, getString(R.string.choose_file)), FILE_CHOOSER_REQUEST);
            } catch (ActivityNotFoundException e) {
                filePathCallback = null;
                Toast.makeText(MainActivity.this, R.string.no_file_picker, Toast.LENGTH_SHORT).show();
                return false;
            }
            return true;
        }

        @Override
        public void onShowCustomView(View view, CustomViewCallback callback) {
            if (customView != null) {
                callback.onCustomViewHidden();
                return;
            }
            customView = view;
            customViewCallback = callback;
            fullscreenContainer.addView(view, new FrameLayout.LayoutParams(
                    FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));
            fullscreenContainer.setVisibility(View.VISIBLE);
            webView.setVisibility(View.GONE);
            setSystemBarsHidden(true);
            updateBackHandling();
        }

        @Override
        public void onHideCustomView() {
            hideCustomView();
        }

        @Override
        public Bitmap getDefaultVideoPoster() {
            // Avoids the grey "play" placeholder before a video starts.
            return Bitmap.createBitmap(1, 1, Bitmap.Config.ARGB_8888);
        }
    }
}
