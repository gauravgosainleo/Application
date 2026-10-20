package net.learninganddevelopment.uninav;

import android.net.Uri;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import org.json.JSONException;
import org.json.JSONObject;

import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.Reader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * The two server endpoints the app calls outside the WebView:
 *  - api/app_config.php: launch settings (update prompt, push set-up), and
 *  - api/app_updates.php: the background "what's new" feed.
 *
 * Both run while nobody may be signed in, so they take the shared app key
 * instead of a session and only ever return generic text and links - never
 * resident data (the key is inside the APK and therefore extractable).
 */
final class ServerApi {

    // These endpoints return a few short items; anything bigger is not ours.
    private static final int MAX_BODY_CHARS = 256 * 1024;
    private static final int TIMEOUT_MS = 20000;

    private ServerApi() {}

    /** A parsed reply, or a failure with a hint on whether retrying can help. */
    static final class Reply {
        final int status;
        @Nullable final JSONObject json;
        final boolean retryable;
        final String error;

        Reply(int status, @Nullable JSONObject json, boolean retryable, String error) {
            this.status = status;
            this.json = json;
            this.retryable = retryable;
            this.error = error;
        }

        boolean ok() {
            return json != null && json.optBoolean("ok", false);
        }
    }

    /** "UninavApp/1.8.0 (Android; build 180)": lets the server tell this app from old installs. */
    static String userAgentToken() {
        return "UninavApp/" + BuildConfig.VERSION_NAME + " (Android; build " + BuildConfig.VERSION_CODE + ")";
    }

    static Reply appConfig() {
        return get(BuildConfig.BASE_URL + "/api/app_config.php?k=" + Uri.encode(BuildConfig.NOTIFY_KEY)
                + "&v=" + BuildConfig.VERSION_CODE);
    }

    static Reply appUpdates(long since) {
        return get(BuildConfig.BASE_URL + "/api/app_updates.php?since=" + since
                + "&k=" + Uri.encode(BuildConfig.NOTIFY_KEY) + "&v=" + BuildConfig.VERSION_CODE);
    }

    /** Text value, treating a missing key and JSON null alike (optString returns "null"). */
    @NonNull
    static String text(@Nullable JSONObject object, String key, String fallback) {
        if (object == null || !object.has(key) || object.isNull(key)) return fallback;
        String value = object.optString(key, fallback).trim();
        return value.isEmpty() ? fallback : value;
    }

    private static Reply get(String url) {
        HttpURLConnection connection = null;
        try {
            connection = (HttpURLConnection) new URL(url).openConnection();
            connection.setRequestMethod("GET");
            connection.setConnectTimeout(TIMEOUT_MS);
            connection.setReadTimeout(TIMEOUT_MS);
            connection.setUseCaches(false);
            connection.setRequestProperty("Accept", "application/json");
            connection.setRequestProperty("User-Agent", userAgentToken());

            int status = connection.getResponseCode();
            InputStream stream = (status >= 200 && status < 400)
                    ? connection.getInputStream()
                    : connection.getErrorStream();
            String body = readAll(stream);
            if (body == null || body.isEmpty()) {
                return new Reply(status, null, true, "empty reply (HTTP " + status + ")");
            }

            JSONObject json;
            try {
                json = new JSONObject(body);
            } catch (JSONException badJson) {
                // A maintenance page or an HTML error instead of JSON. Older
                // server builds replied with a plain-text 503 here; retry
                // rather than give up.
                return new Reply(status, null, true, "non-JSON reply (HTTP " + status + ")");
            }

            if (!json.optBoolean("ok", false)) {
                // The server tells a passing hiccup from something an
                // administrator has to fix. Only the former is worth a retry,
                // so a locked installation is not polled every few minutes forever.
                boolean retryable = json.optBoolean("retryable", status >= 500);
                return new Reply(status, json, retryable, text(json, "error", "HTTP " + status));
            }
            return new Reply(status, json, false, "");

        } catch (Exception e) {
            return new Reply(0, null, true, e.getClass().getSimpleName() + " " + e.getMessage());
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    @Nullable
    private static String readAll(@Nullable InputStream stream) throws Exception {
        if (stream == null) return null;
        StringBuilder out = new StringBuilder();
        char[] buffer = new char[8192];
        try (Reader reader = new InputStreamReader(stream, StandardCharsets.UTF_8)) {
            int read;
            while ((read = reader.read(buffer)) != -1) {
                out.append(buffer, 0, read);
                if (out.length() > MAX_BODY_CHARS) return null;
            }
        }
        return out.toString();
    }
}
