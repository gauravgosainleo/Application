package net.learninganddevelopment.uninav;

import android.content.Context;
import android.util.Log;

import androidx.annotation.NonNull;
import androidx.work.BackoffPolicy;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.concurrent.TimeUnit;

/**
 * Background "what's new" check.
 *
 * Polls api/app_updates.php and raises one notification per new item. The
 * endpoint is deliberately unauthenticated (the phone polls while nobody is
 * signed in), so it returns only generic text plus a link - the resident signs
 * in to see anything real.
 *
 * When instant push is active (PushManager) this becomes an hourly safety net
 * behind Firebase; otherwise it runs every 15 minutes, the platform minimum.
 * Android may batch either further to save battery.
 */
public class UpdatesWorker extends Worker {

    private static final String TAG = "UninavUpdates";
    private static final String WORK_NAME = "uninav-updates";
    private static final long PUSH_SAFETY_NET_MINUTES = 60L;

    public UpdatesWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    /** Registers the repeating check. Safe to call on every app start. */
    public static void schedule(Context context) {
        AppPrefs prefs = new AppPrefs(context);
        long minutes = prefs.pushActive()
                ? PUSH_SAFETY_NET_MINUTES
                : Math.max(15L, BuildConfig.POLL_MINUTES);

        Constraints constraints = new Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build();

        PeriodicWorkRequest request = new PeriodicWorkRequest.Builder(
                UpdatesWorker.class, minutes, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 5, TimeUnit.MINUTES)
                .build();

        // KEEP, so an app restart does not reset the schedule and cause a
        // burst; UPDATE only when the interval itself changed (push switched
        // on or off).
        boolean intervalChanged = prefs.scheduledPollMinutes() != minutes;
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(WORK_NAME,
                intervalChanged ? ExistingPeriodicWorkPolicy.UPDATE : ExistingPeriodicWorkPolicy.KEEP,
                request);
        if (intervalChanged) prefs.setScheduledPollMinutes(minutes);
    }

    @NonNull
    @Override
    public Result doWork() {
        Context context = getApplicationContext();
        AppPrefs prefs = new AppPrefs(context);

        // The user can switch notifications off inside the app.
        if (!prefs.notificationsEnabled()) return Result.success();

        long since = prefs.lastSeenServerTime();
        ServerApi.Reply reply = ServerApi.appUpdates(since);
        JSONObject json = reply.json;
        if (!reply.ok() || json == null) {
            Log.w(TAG, "app_updates.php not ok: " + reply.error + " retryable=" + reply.retryable);
            return reply.retryable ? Result.retry() : Result.success();
        }

        // Picks up push being switched on or off in the admin console even if
        // nobody has opened the app since.
        PushManager.applyServerConfig(context, json, false);

        long serverTime = json.optLong("server_time", 0L);
        JSONArray items = json.optJSONArray("items");

        // since=0 is the first run: learn the server clock and notify nothing,
        // so installing the app never dumps a week of history on the user.
        if (since > 0 && items != null) {
            String fallbackTitle = context.getString(R.string.default_update_title);
            for (int i = 0; i < items.length(); i++) {
                JSONObject item = items.optJSONObject(i);
                if (item == null) continue;
                String id = ServerApi.text(item, "id", "");
                if (id.isEmpty()) continue;
                // Survives a retry, and skips what push already delivered.
                if (!prefs.markNotifiedIfNew(id)) continue;
                NotificationHelper.showUpdate(
                        context,
                        id,
                        ServerApi.text(item, "tag", id),
                        ServerApi.text(item, "title", fallbackTitle),
                        ServerApi.text(item, "body", ""),
                        ServerApi.text(item, "url", BuildConfig.BASE_URL + "/"),
                        item.optLong("ts", 0L));
            }
        }

        if (serverTime > 0) prefs.setLastSeenServerTime(serverTime);
        return Result.success();
    }
}
