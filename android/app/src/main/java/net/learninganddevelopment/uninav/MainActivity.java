package net.learninganddevelopment.uninav;

import android.Manifest;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.MediaStore;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import androidx.activity.EdgeToEdge;
import androidx.activity.OnBackPressedCallback;
import androidx.activity.SystemBarStyle;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import java.io.File;
import java.io.IOException;
import java.util.Locale;

/**
 * WebView host for the Uni-Vishwas application.
 *
 * Deliberate security choices, matching START-HERE-SECURITY-UPGRADE.md:
 *  - onReceivedSslError is NOT overridden, so a bad certificate cancels the
 *    load. Never add an ssl.proceed() here.
 *  - Mixed content is blocked outright.
 *  - usesCleartextTraffic is false in the manifest and in the network security
 *    config, so an http:// URL cannot load at all.
 *  - Only the configured host is opened in-app; anything else is handed to the
 *    system browser, so a link in a notice cannot borrow the app's session.
 */
public class MainActivity extends AppCompatActivity {

    private static final int REQ_PERMISSIONS = 1001;
    private static final int REQ_FILE_CHOOSER = 1002;
    private static final int REQ_CAMERA_FOR_CHOOSER = 1003;
    private static final long CAMERA_FILE_MAX_AGE_MS = 24L * 60 * 60 * 1000;

    private WebView webView;
    private SwipeRefreshLayout refreshLayout;
    private OnBackPressedCallback backCallback;
    private UpdatePrompt updatePrompt;
    private ValueCallback<Uri[]> fileCallback;
    private WebChromeClient.FileChooserParams pendingChooserParams;
    private PermissionRequest pendingWebPermission;
    private Uri cameraOutputUri;
    private File cameraOutputFile;

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        // Android 15+ draws every app edge-to-edge; do it on all versions so
        // the layout is the same everywhere, with light icons on the teal bars.
        EdgeToEdge.enable(this,
                SystemBarStyle.dark(Color.TRANSPARENT),
                SystemBarStyle.dark(Color.TRANSPARENT));
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        webView = findViewById(R.id.webview);
        refreshLayout = findViewById(R.id.refresh);
        applyWindowInsets(findViewById(R.id.root));

        // Back walks the WebView history first. The callback is only enabled
        // while there is history, so on the first page Android's predictive
        // back gesture can show (and perform) the return to the home screen.
        backCallback = new OnBackPressedCallback(false) {
            @Override
            public void handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack();
                } else {
                    setEnabled(false);
                    getOnBackPressedDispatcher().onBackPressed();
                }
            }
        };
        getOnBackPressedDispatcher().addCallback(this, backCallback);

        configureWebView();

        NotificationHelper.createChannel(this);
        requestStartupPermissions();
        UpdatesWorker.schedule(this);
        updatePrompt = new UpdatePrompt(this);
        deleteOldCameraFiles();

        refreshLayout.setOnRefreshListener(() -> webView.reload());

        if (savedInstanceState == null) {
            webView.loadUrl(resolveStartUrl(getIntent()));
        }
    }

    /**
     * The page sits between the status bar and the navigation bar (or the
     * keyboard, whichever is taller); the teal root shows behind both bars.
     */
    private void applyWindowInsets(View root) {
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars()
                    | WindowInsetsCompat.Type.displayCutout());
            Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
            v.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, ime.bottom));
            return WindowInsetsCompat.CONSUMED;
        });
    }

    @Override
    protected void onResume() {
        super.onResume();
        updatePrompt.onResume();
    }

    @Override
    protected void onPause() {
        // Persist the saved-login cookies now; onDestroy is not guaranteed.
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override
    protected void onNewIntent(@NonNull Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        // A notification tapped while the app is already running.
        String url = resolveStartUrl(intent);
        if (url != null) webView.loadUrl(url);
    }

    /**
     * Notifications carry an absolute URL from the server. Only accept one that
     * is https and on our own host, so a crafted link cannot aim the signed-in
     * WebView somewhere else.
     */
    private String resolveStartUrl(@Nullable Intent intent) {
        if (intent != null && intent.getData() != null) {
            Uri data = intent.getData();
            if ("https".equalsIgnoreCase(data.getScheme())
                    && BuildConfig.APP_HOST.equalsIgnoreCase(data.getHost())) {
                return data.toString();
            }
        }
        return BuildConfig.BASE_URL + "/";
    }

    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setSupportZoom(false);
        settings.setBuiltInZoomControls(false);
        settings.setMediaPlaybackRequiresUserGesture(false);  // QR scanner camera preview
        settings.setGeolocationEnabled(true);                 // attendance in/out location
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        // Tells the server this is the current app, so it never shows the
        // "please update" page meant for old installs (the app has its own prompt).
        settings.setUserAgentString(settings.getUserAgentString() + " " + ServerApi.userAgentToken());

        // Persistent cookies keep the 30-day "saved login" working.
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, false);

        webView.setWebViewClient(new WebViewClient() {

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);

                // Phone numbers and e-mail links in the SPOC/vendor directories.
                if (scheme.equals("tel") || scheme.equals("mailto")
                        || scheme.equals("sms") || scheme.equals("whatsapp")) {
                    return openExternally(uri);
                }
                // Our own site stays in the app; everything else goes to the browser.
                if (scheme.equals("https") && BuildConfig.APP_HOST.equalsIgnoreCase(uri.getHost())) {
                    return false;
                }
                return openExternally(uri);
            }

            @Override
            public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
                backCallback.setEnabled(view.canGoBack());
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                refreshLayout.setRefreshing(false);
                backCallback.setEnabled(view.canGoBack());
            }

            // NOTE: onReceivedSslError is intentionally not implemented.
            // The default behaviour cancels the load on a certificate error,
            // which is required. Do not add an override that calls proceed().
        });

        webView.setWebChromeClient(new WebChromeClient() {

            /** Grants the page the camera, once Android itself has granted it. */
            @Override
            public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> {
                    boolean wantsCamera = false;
                    for (String resource : request.getResources()) {
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) wantsCamera = true;
                    }
                    if (!wantsCamera) { request.deny(); return; }

                    if (hasPermission(Manifest.permission.CAMERA)) {
                        request.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                    } else {
                        pendingWebPermission = request;
                        ActivityCompat.requestPermissions(MainActivity.this,
                                new String[]{Manifest.permission.CAMERA}, REQ_PERMISSIONS);
                    }
                });
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin,
                                                           GeolocationPermissions.Callback callback) {
                boolean allowed = hasPermission(Manifest.permission.ACCESS_FINE_LOCATION)
                        || hasPermission(Manifest.permission.ACCESS_COARSE_LOCATION);
                callback.invoke(origin, allowed, false);
                if (!allowed) {
                    ActivityCompat.requestPermissions(MainActivity.this, new String[]{
                            Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_PERMISSIONS);
                }
            }

            /**
             * Photo and video pickers: gallery uploads, complaint photos,
             * profile pictures - and inputs marked capture="..." (attendance,
             * vehicle plate, gym QR), which open the camera directly.
             */
            @Override
            public boolean onShowFileChooser(WebView view,
                                             ValueCallback<Uri[]> callback,
                                             FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = callback;
                clearCameraOutput();

                if (params.isCaptureEnabled() && acceptsImages(params)) {
                    if (hasPermission(Manifest.permission.CAMERA)) {
                        if (launchCamera()) return true;
                    } else {
                        // Declaring CAMERA makes the camera intent require it.
                        pendingChooserParams = params;
                        ActivityCompat.requestPermissions(MainActivity.this,
                                new String[]{Manifest.permission.CAMERA}, REQ_CAMERA_FOR_CHOOSER);
                        return true;
                    }
                }
                launchPicker(params);
                return true;
            }
        });

        // CSV / XLS / XLSX / PDF exports from the reports and gym pages.
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            try {
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                request.setMimeType(mimeType);
                request.addRequestHeader("User-Agent", userAgent);
                // Carry the session cookie, or the server returns the login page.
                String cookie = CookieManager.getInstance().getCookie(url);
                if (cookie != null) request.addRequestHeader("Cookie", cookie);
                String name = URLUtil.guessFileName(url, contentDisposition, mimeType);
                request.setTitle(name);
                request.setNotificationVisibility(
                        DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS,
                        File.separator + name);
                DownloadManager manager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                if (manager != null) {
                    manager.enqueue(request);
                    toast(R.string.download_started);
                }
            } catch (Exception e) {
                toast(R.string.download_failed);
            }
        });
    }

    private String resolveAcceptType(WebChromeClient.FileChooserParams params) {
        String[] accept = params.getAcceptTypes();
        if (accept != null && accept.length > 0 && accept[0] != null && !accept[0].isEmpty()) {
            // "image/*", "video/*" and friends come straight from the page.
            if (accept[0].contains("/")) return accept[0];
        }
        return "*/*";
    }

    /** True when the page accepts images (or did not restrict the type). */
    private boolean acceptsImages(WebChromeClient.FileChooserParams params) {
        String[] accept = params.getAcceptTypes();
        if (accept == null || accept.length == 0) return true;
        boolean any = false;
        for (String type : accept) {
            if (type == null || type.trim().isEmpty()) continue;
            any = true;
            String t = type.trim().toLowerCase(Locale.ROOT);
            if (t.startsWith("image/") || t.equals("*/*") || t.endsWith(".jpg")
                    || t.endsWith(".jpeg") || t.endsWith(".png")) return true;
        }
        return !any;
    }

    /** Gallery / files, with the camera offered alongside when allowed. */
    private void launchPicker(WebChromeClient.FileChooserParams params) {
        Intent content = new Intent(Intent.ACTION_GET_CONTENT);
        content.addCategory(Intent.CATEGORY_OPENABLE);
        content.setType(resolveAcceptType(params));
        if (params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) {
            content.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
        }

        Intent chooser = Intent.createChooser(content, getString(R.string.choose_file));

        // Offer the camera alongside the file picker, so attendance and
        // QR flows can take a photo without leaving the page.
        if (acceptsImages(params) && hasPermission(Manifest.permission.CAMERA)) {
            Intent capture = cameraIntent();
            if (capture != null) chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{capture});
        }

        try {
            startActivityForResult(chooser, REQ_FILE_CHOOSER);
        } catch (ActivityNotFoundException e) {
            finishChooser(null);
            toast(R.string.no_file_picker);
        }
    }

    private boolean launchCamera() {
        Intent capture = cameraIntent();
        if (capture == null) return false;
        try {
            startActivityForResult(capture, REQ_FILE_CHOOSER);
            return true;
        } catch (ActivityNotFoundException e) {
            clearCameraOutput();
            return false;
        }
    }

    /**
     * A camera intent that writes a full-size photo to a file we can hand back
     * to the page. Without EXTRA_OUTPUT the camera only returns a thumbnail
     * bitmap and no URI, so the page received nothing.
     */
    @Nullable
    private Intent cameraIntent() {
        Intent capture = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
        // Needs the <queries> entry in the manifest on Android 11+.
        if (capture.resolveActivity(getPackageManager()) == null) return null;
        try {
            File dir = new File(getCacheDir(), "camera");
            if (!dir.isDirectory() && !dir.mkdirs()) return null;
            cameraOutputFile = File.createTempFile("capture_", ".jpg", dir);
            cameraOutputUri = FileProvider.getUriForFile(this,
                    getPackageName() + ".fileprovider", cameraOutputFile);
        } catch (IOException | IllegalArgumentException e) {
            clearCameraOutput();
            return null;
        }
        capture.putExtra(MediaStore.EXTRA_OUTPUT, cameraOutputUri);
        capture.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
        // Carries the grant to the camera app even when wrapped in a chooser.
        capture.setClipData(ClipData.newRawUri("", cameraOutputUri));
        return capture;
    }

    private void clearCameraOutput() {
        cameraOutputUri = null;
        cameraOutputFile = null;
    }

    /** Uploads read the photo after the chooser closes, so old ones are cleared at start-up. */
    private void deleteOldCameraFiles() {
        File[] old = new File(getCacheDir(), "camera").listFiles();
        if (old == null) return;
        long cutoff = System.currentTimeMillis() - CAMERA_FILE_MAX_AGE_MS;
        for (File f : old) {
            if (f.lastModified() < cutoff && !f.delete()) f.deleteOnExit();
        }
    }

    /** Hands the result to the page exactly once. */
    private void finishChooser(@Nullable Uri[] results) {
        if (fileCallback != null) fileCallback.onReceiveValue(results);
        fileCallback = null;
        pendingChooserParams = null;
        clearCameraOutput();
    }

    private boolean openExternally(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (ActivityNotFoundException e) {
            toast(R.string.cannot_open_link);
        }
        return true;
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, @Nullable Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_FILE_CHOOSER) return;
        if (fileCallback == null) return;

        Uri[] results = null;
        if (resultCode == RESULT_OK) {
            if (data != null && data.getClipData() != null && data.getClipData().getItemCount() > 0
                    && data.getClipData().getItemAt(0).getUri() != null
                    && !data.getClipData().getItemAt(0).getUri().equals(cameraOutputUri)) {
                int count = data.getClipData().getItemCount();
                results = new Uri[count];
                for (int i = 0; i < count; i++) {
                    results[i] = data.getClipData().getItemAt(i).getUri();
                }
            } else if (data != null && data.getData() != null) {
                results = new Uri[]{data.getData()};
            } else if (cameraOutputUri != null && cameraOutputFile != null
                    && cameraOutputFile.length() > 0) {
                // The camera wrote the photo to our file and returned no data.
                results = new Uri[]{cameraOutputUri};
            }
        }
        finishChooser(results);
    }

    private void requestStartupPermissions() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
                && !hasPermission(Manifest.permission.POST_NOTIFICATIONS)) {
            ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.POST_NOTIFICATIONS}, REQ_PERMISSIONS);
        }
    }

    private boolean hasPermission(String permission) {
        return ContextCompat.checkSelfPermission(this, permission)
                == PackageManager.PERMISSION_GRANTED;
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions,
                                           @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);

        if (requestCode == REQ_CAMERA_FOR_CHOOSER) {
            // A capture="..." input was waiting for the camera permission.
            WebChromeClient.FileChooserParams params = pendingChooserParams;
            pendingChooserParams = null;
            if (fileCallback == null) return;
            if (hasPermission(Manifest.permission.CAMERA) && launchCamera()) return;
            if (params != null) launchPicker(params);   // denied: pick an existing photo instead
            else finishChooser(null);
            return;
        }

        if (requestCode != REQ_PERMISSIONS) return;

        // Hand the camera to the page that was waiting for it.
        if (pendingWebPermission != null) {
            if (hasPermission(Manifest.permission.CAMERA)) {
                pendingWebPermission.grant(
                        new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            } else {
                pendingWebPermission.deny();
            }
            pendingWebPermission = null;
        }
    }

    @Override
    protected void onSaveInstanceState(@NonNull Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }

    @Override
    protected void onRestoreInstanceState(@NonNull Bundle savedInstanceState) {
        super.onRestoreInstanceState(savedInstanceState);
        // A state too large to save comes back empty; load the start page
        // instead of leaving a blank screen.
        if (webView.restoreState(savedInstanceState) == null) {
            webView.loadUrl(resolveStartUrl(getIntent()));
        }
    }

    @Override
    protected void onDestroy() {
        CookieManager.getInstance().flush();
        updatePrompt.onDestroy();
        // A pending chooser must still be answered, or the page's input stays dead.
        if (fileCallback != null) {
            fileCallback.onReceiveValue(null);
            fileCallback = null;
        }
        super.onDestroy();
    }

    private void toast(int resId) {
        Toast.makeText(this, resId, Toast.LENGTH_SHORT).show();
    }
}
