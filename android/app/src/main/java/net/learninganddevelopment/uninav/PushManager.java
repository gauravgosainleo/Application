package net.learninganddevelopment.uninav;

import android.content.Context;
import android.util.Log;

import androidx.annotation.Nullable;

import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import com.google.firebase.messaging.FirebaseMessaging;

import org.json.JSONException;
import org.json.JSONObject;

/**
 * Instant notifications through Firebase Cloud Messaging.
 *
 * No Firebase project is baked into the APK. The server hands the app the
 * project's public client settings (project id, app id, API key and sender
 * id - the values a google-services.json would carry; none of them is a
 * secret) in the "push" block of api/app_config.php and api/app_updates.php,
 * and Firebase is initialised with them here at runtime. The society can
 * therefore switch push on, or move to another Firebase project, from the
 * admin console without publishing a new app version.
 *
 * Every phone subscribes to one topic and the server sends each new notice,
 * event, photo batch, etc. to that topic once. The messages carry only
 * generic text and a link, exactly like the background feed.
 *
 * Phones without Google Play services never become "push active" and simply
 * keep the 15-minute background check (UpdatesWorker).
 */
final class PushManager {

    private static final String TAG = "UninavPush";

    private PushManager() {}

    /**
     * Runs from Application.onCreate, so Firebase exists before FCM hands a
     * message to PushService in a freshly started process.
     */
    static void initFromCache(Context context) {
        PushConfig config = PushConfig.parse(new AppPrefs(context).pushConfigJson());
        if (config != null) initialise(context, config);
    }

    /**
     * Applies the "push" block of a server reply. A reply without the key comes
     * from an older server and changes nothing; "push": null means push is off.
     *
     * @param launch true on app start, when the topic subscription is renewed
     *               even if nothing changed (cheap, and heals a lost subscription).
     */
    static void applyServerConfig(Context context, @Nullable JSONObject reply, boolean launch) {
        if (reply == null || !reply.has("push")) return;
        Context app = context.getApplicationContext();
        AppPrefs prefs = new AppPrefs(app);
        PushConfig config = PushConfig.from(reply.optJSONObject("push"));

        if (config == null) {
            PushConfig previous = PushConfig.parse(prefs.pushConfigJson());
            if (previous != null) unsubscribe(previous.topic);
            prefs.setPushConfigJson(null);
            setActive(app, false);
            return;
        }

        PushConfig previous = PushConfig.parse(prefs.pushConfigJson());
        boolean changed = previous == null || !previous.toJson().equals(config.toJson());
        if (changed) {
            if (previous != null && !previous.topic.equals(config.topic)) unsubscribe(previous.topic);
            prefs.setPushConfigJson(config.toJson());
        }

        if (!initialise(app, config)) {
            // Firebase is already running with another project's settings and
            // cannot be re-initialised in this process; the new settings are
            // saved and take effect the next time the app starts.
            return;
        }
        if (launch || changed || !prefs.pushActive() || !config.topic.equals(prefs.subscribedTopic())) {
            subscribe(app, config.topic);
        }
    }

    /** A new FCM token keeps its topics server-side, but renew to be safe. */
    static void resubscribe(Context context) {
        PushConfig config = PushConfig.parse(new AppPrefs(context).pushConfigJson());
        if (config != null && initialise(context, config)) subscribe(context.getApplicationContext(), config.topic);
    }

    /** True when the default FirebaseApp exists with these settings. */
    private static boolean initialise(Context context, PushConfig config) {
        try {
            if (FirebaseApp.getApps(context).isEmpty()) {
                FirebaseOptions options = new FirebaseOptions.Builder()
                        .setApplicationId(config.appId)
                        .setApiKey(config.apiKey)
                        .setProjectId(config.projectId)
                        .setGcmSenderId(config.senderId)
                        .build();
                FirebaseApp.initializeApp(context.getApplicationContext(), options);
                return true;
            }
            FirebaseOptions current = FirebaseApp.getInstance().getOptions();
            return config.appId.equals(current.getApplicationId())
                    && config.projectId.equals(current.getProjectId());
        } catch (Throwable t) {
            Log.w(TAG, "Firebase initialisation failed: " + t);
            return false;
        }
    }

    private static void subscribe(Context app, String topic) {
        try {
            FirebaseMessaging.getInstance().subscribeToTopic(topic).addOnCompleteListener(task -> {
                boolean ok = task.isSuccessful();
                if (ok) {
                    new AppPrefs(app).setSubscribedTopic(topic);
                } else {
                    Log.w(TAG, "topic subscription failed: " + task.getException());
                }
                setActive(app, ok);
            });
        } catch (Throwable t) {
            // No Google Play services, or Firebase refused the settings.
            Log.w(TAG, "topic subscription unavailable: " + t);
            setActive(app, false);
        }
    }

    private static void unsubscribe(String topic) {
        try {
            FirebaseMessaging.getInstance().unsubscribeFromTopic(topic);
        } catch (Throwable ignored) {
            // Best effort: Firebase was never initialised in this process.
        }
    }

    /** Push active means the background check can relax to an hourly safety net. */
    private static void setActive(Context app, boolean active) {
        AppPrefs prefs = new AppPrefs(app);
        if (prefs.pushActive() == active) return;
        prefs.setPushActive(active);
        UpdatesWorker.schedule(app);
    }

    /** The server's "push" block. Every field is required. */
    static final class PushConfig {
        final String projectId;
        final String appId;
        final String apiKey;
        final String senderId;
        final String topic;

        private PushConfig(String projectId, String appId, String apiKey, String senderId, String topic) {
            this.projectId = projectId;
            this.appId = appId;
            this.apiKey = apiKey;
            this.senderId = senderId;
            this.topic = topic;
        }

        @Nullable
        static PushConfig from(@Nullable JSONObject push) {
            if (push == null) return null;
            String projectId = ServerApi.text(push, "project_id", "");
            String appId = ServerApi.text(push, "app_id", "");
            String apiKey = ServerApi.text(push, "api_key", "");
            String senderId = ServerApi.text(push, "sender_id", "");
            String topic = ServerApi.text(push, "topic", "");
            if (projectId.isEmpty() || appId.isEmpty() || apiKey.isEmpty()
                    || senderId.isEmpty() || topic.isEmpty()) return null;
            // FCM topic names: letters, digits and -_.~% only.
            if (!topic.matches("[A-Za-z0-9_.~%-]{1,200}")) return null;
            return new PushConfig(projectId, appId, apiKey, senderId, topic);
        }

        @Nullable
        static PushConfig parse(@Nullable String json) {
            if (json == null || json.isEmpty()) return null;
            try {
                return from(new JSONObject(json));
            } catch (JSONException e) {
                return null;
            }
        }

        String toJson() {
            JSONObject o = new JSONObject();
            try {
                o.put("project_id", projectId);
                o.put("app_id", appId);
                o.put("api_key", apiKey);
                o.put("sender_id", senderId);
                o.put("topic", topic);
            } catch (JSONException ignored) {
                // Only string values: cannot happen.
            }
            return o.toString();
        }
    }
}
