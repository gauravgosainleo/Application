package net.learninganddevelopment.uninav;

import android.content.Context;
import android.content.SharedPreferences;
import android.text.TextUtils;

import androidx.annotation.Nullable;

import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.Set;

/** Small wrapper around the app's own preferences. */
public class AppPrefs {

    private static final String FILE = "uninav";
    private static final String KEY_SINCE = "last_seen_server_time";
    private static final String KEY_ENABLED = "notifications_enabled";
    /** Up to 1.7.2 the history was a string set, which Android stores unordered. */
    private static final String KEY_NOTIFIED_LEGACY = "notified_ids";
    private static final String KEY_NOTIFIED = "notified_ids_ordered";
    private static final int NOTIFIED_HISTORY = 300;
    private static final String KEY_PUSH_CONFIG = "push_config";
    private static final String KEY_PUSH_ACTIVE = "push_active";
    private static final String KEY_PUSH_TOPIC = "push_subscribed_topic";
    private static final String KEY_POLL_MINUTES = "poll_scheduled_minutes";

    // The push service and the background check run in the same process and
    // can both receive the same notice at the same moment.
    private static final Object NOTIFIED_LOCK = new Object();

    private final SharedPreferences prefs;

    public AppPrefs(Context context) {
        this.prefs = context.getApplicationContext()
                .getSharedPreferences(FILE, Context.MODE_PRIVATE);
    }

    public long lastSeenServerTime() {
        return prefs.getLong(KEY_SINCE, 0L);
    }

    public void setLastSeenServerTime(long value) {
        prefs.edit().putLong(KEY_SINCE, value).apply();
    }

    public boolean notificationsEnabled() {
        return prefs.getBoolean(KEY_ENABLED, true);
    }

    public void setNotificationsEnabled(boolean value) {
        prefs.edit().putBoolean(KEY_ENABLED, value).apply();
    }

    /**
     * Records an item id and says whether it was new, as one step, so a push
     * message and a WorkManager retry (or a clock that moves backwards) cannot
     * notify the same notice twice.
     *
     * Ids are kept in arrival order so the oldest are the ones that age out.
     * The old string-set history came back from getStringSet() in no
     * particular order, so trimming it dropped random ids - including recent
     * ones, which could then be shown again.
     */
    public boolean markNotifiedIfNew(@Nullable String id) {
        if (id == null) return true;
        id = id.replace('\n', ' ').trim();
        if (id.isEmpty()) return true;
        synchronized (NOTIFIED_LOCK) {
            LinkedHashSet<String> seen = readNotified();
            if (seen.contains(id)) return false;
            seen.add(id);
            while (seen.size() > NOTIFIED_HISTORY) {
                seen.remove(seen.iterator().next());
            }
            // apply() updates the in-memory copy before it returns, so the
            // next caller in this process already sees the id.
            prefs.edit()
                    .putString(KEY_NOTIFIED, TextUtils.join("\n", seen))
                    .remove(KEY_NOTIFIED_LEGACY)
                    .apply();
            return true;
        }
    }

    private LinkedHashSet<String> readNotified() {
        LinkedHashSet<String> seen = new LinkedHashSet<>();
        // Ids from 1.7.2 go first: their order is unknown but they are older.
        Set<String> legacy = prefs.getStringSet(KEY_NOTIFIED_LEGACY, null);
        if (legacy != null) seen.addAll(legacy);
        String stored = prefs.getString(KEY_NOTIFIED, "");
        if (stored != null && !stored.isEmpty()) {
            Collections.addAll(seen, stored.split("\n"));
        }
        seen.remove("");
        return seen;
    }

    /** The server's "push" block as last received, or null when push is off. */
    @Nullable
    public String pushConfigJson() {
        return prefs.getString(KEY_PUSH_CONFIG, null);
    }

    public void setPushConfigJson(@Nullable String json) {
        if (json == null) {
            prefs.edit().remove(KEY_PUSH_CONFIG).remove(KEY_PUSH_TOPIC).apply();
        } else {
            prefs.edit().putString(KEY_PUSH_CONFIG, json).apply();
        }
    }

    /** True once this phone is subscribed for instant notifications. */
    public boolean pushActive() {
        return prefs.getBoolean(KEY_PUSH_ACTIVE, false);
    }

    public void setPushActive(boolean value) {
        prefs.edit().putBoolean(KEY_PUSH_ACTIVE, value).apply();
    }

    @Nullable
    public String subscribedTopic() {
        return prefs.getString(KEY_PUSH_TOPIC, null);
    }

    public void setSubscribedTopic(@Nullable String topic) {
        prefs.edit().putString(KEY_PUSH_TOPIC, topic).apply();
    }

    /** Interval the background check was last scheduled with, 0 if never. */
    public long scheduledPollMinutes() {
        return prefs.getLong(KEY_POLL_MINUTES, 0L);
    }

    public void setScheduledPollMinutes(long minutes) {
        prefs.edit().putLong(KEY_POLL_MINUTES, minutes).apply();
    }
}
