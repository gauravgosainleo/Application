package com.gvifanhub.app;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.CalendarContract;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.JavascriptInterface;
import android.widget.Toast;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStream;

/**
 * Native helpers for the web app, available in JavaScript as window.GVIAndroid.
 * Methods run on the WebView's bridge thread, so UI work is posted to the main thread.
 */
public class WebAppBridge {

    private final Activity activity;

    WebAppBridge(Activity activity) {
        this.activity = activity;
    }

    @JavascriptInterface
    public String version() {
        return BuildConfig.VERSION_NAME;
    }

    /** Opens the Android share sheet. */
    @JavascriptInterface
    public void share(String title, String url) {
        String text = (title == null || title.trim().isEmpty()) ? url : title + "\n" + url;
        Intent send = new Intent(Intent.ACTION_SEND)
                .setType("text/plain")
                .putExtra(Intent.EXTRA_SUBJECT, title)
                .putExtra(Intent.EXTRA_TEXT, text);
        activity.runOnUiThread(() -> {
            try {
                activity.startActivity(Intent.createChooser(send, activity.getString(R.string.share_via)));
            } catch (ActivityNotFoundException e) {
                Toast.makeText(activity, R.string.no_app_for_link, Toast.LENGTH_SHORT).show();
            }
        });
    }

    /**
     * Opens the calendar app with a new event filled in. Times are epoch milliseconds; for all-day
     * events they must be midnight UTC. Returns false when no calendar app is installed.
     */
    @JavascriptInterface
    public boolean addToCalendar(String title, String description, double beginMillis, double endMillis, boolean allDay) {
        Intent intent = new Intent(Intent.ACTION_INSERT)
                .setData(CalendarContract.Events.CONTENT_URI)
                .putExtra(CalendarContract.Events.TITLE, title)
                .putExtra(CalendarContract.Events.DESCRIPTION, description)
                .putExtra(CalendarContract.EXTRA_EVENT_BEGIN_TIME, (long) beginMillis)
                .putExtra(CalendarContract.EXTRA_EVENT_END_TIME, (long) endMillis)
                .putExtra(CalendarContract.EXTRA_EVENT_ALL_DAY, allDay)
                .putExtra(CalendarContract.Events.AVAILABILITY, CalendarContract.Events.AVAILABILITY_FREE);
        try {
            activity.startActivity(intent);
            return true;
        } catch (ActivityNotFoundException e) {
            return false;
        }
    }

    /** Saves a base64 encoded file to Downloads (blob: links can't go through DownloadManager). */
    @JavascriptInterface
    public boolean saveFile(String name, String mime, String base64) {
        String fileName = safeFileName(name);
        String type = (mime == null || mime.isEmpty()) ? "application/octet-stream" : mime;
        try {
            byte[] bytes = Base64.decode(base64, Base64.DEFAULT);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContentResolver resolver = activity.getContentResolver();
                ContentValues values = new ContentValues();
                values.put(MediaStore.MediaColumns.DISPLAY_NAME, fileName);
                values.put(MediaStore.MediaColumns.MIME_TYPE, type);
                values.put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS);
                Uri uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                if (uri == null) {
                    throw new IOException("MediaStore insert failed");
                }
                try (OutputStream out = resolver.openOutputStream(uri)) {
                    if (out == null) {
                        throw new IOException("Could not open " + uri);
                    }
                    out.write(bytes);
                }
            } else {
                // Android 7-9: app folder under Android/data, no storage permission needed.
                File dir = activity.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
                if (dir == null || (!dir.exists() && !dir.mkdirs())) {
                    throw new IOException("No storage available");
                }
                try (OutputStream out = new FileOutputStream(new File(dir, fileName))) {
                    out.write(bytes);
                }
            }
            toast(activity.getString(R.string.file_saved, fileName));
            return true;
        } catch (IOException | IllegalArgumentException | SecurityException e) {
            toast(activity.getString(R.string.file_save_failed));
            return false;
        }
    }

    @JavascriptInterface
    public void toast(String message) {
        activity.runOnUiThread(() -> Toast.makeText(activity, message, Toast.LENGTH_SHORT).show());
    }

    private static String safeFileName(String name) {
        String clean = name == null ? "" : name.replaceAll("[\\\\/:*?\"<>|\\p{Cntrl}]", "_").trim();
        if (clean.isEmpty() || clean.startsWith(".")) {
            clean = "gvi-file" + clean;
        }
        return clean.length() > 120 ? clean.substring(clean.length() - 120) : clean;
    }
}
