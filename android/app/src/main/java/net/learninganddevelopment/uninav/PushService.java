package net.learninganddevelopment.uninav;

import android.content.Context;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * Receives instant notifications from Firebase Cloud Messaging.
 *
 * The server sends data-only messages (id, tag, type, title, body, url, ts)
 * so this runs whether or not the app is open, and the notification is built
 * the same way as one found by the background check - same dedupe by id, same
 * tray slot per tag. A plain notification message (for example a test sent
 * from the Firebase console) is shown too.
 */
public class PushService extends FirebaseMessagingService {

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        Context context = getApplicationContext();
        AppPrefs prefs = new AppPrefs(context);

        // The user can switch notifications off inside the app.
        if (!prefs.notificationsEnabled()) return;

        Map<String, String> data = message.getData();
        String fallbackTitle = getString(R.string.default_update_title);
        String id = clean(data.get("id"));
        String tag;
        String title;
        String body;
        String url;
        long ts;

        if (id != null) {
            tag = valueOr(clean(data.get("tag")), id);
            title = valueOr(clean(data.get("title")), fallbackTitle);
            body = valueOr(clean(data.get("body")), "");
            url = valueOr(clean(data.get("url")), BuildConfig.BASE_URL + "/");
            ts = parseLong(data.get("ts"));
        } else if (message.getNotification() != null) {
            RemoteMessage.Notification n = message.getNotification();
            id = valueOr(clean(message.getMessageId()), "fcm:" + message.getSentTime());
            tag = id;
            title = valueOr(clean(n.getTitle()), fallbackTitle);
            body = valueOr(clean(n.getBody()), "");
            url = BuildConfig.BASE_URL + "/";
            ts = message.getSentTime() / 1000L;
        } else {
            return;
        }

        // The background check may already have shown this one.
        if (!prefs.markNotifiedIfNew(id)) return;
        NotificationHelper.showUpdate(context, id, tag, title, body, url, ts);
    }

    @Override
    public void onNewToken(@NonNull String token) {
        // Topic subscriptions belong to the token; renew them for a new one.
        PushManager.resubscribe(getApplicationContext());
    }

    @Nullable
    private static String clean(@Nullable String value) {
        if (value == null) return null;
        String trimmed = value.trim();
        return trimmed.isEmpty() ? null : trimmed;
    }

    private static String valueOr(@Nullable String value, String fallback) {
        return value != null ? value : fallback;
    }

    private static long parseLong(@Nullable String value) {
        if (value == null) return 0L;
        try {
            return Long.parseLong(value.trim());
        } catch (NumberFormatException e) {
            return 0L;
        }
    }
}
