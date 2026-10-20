package net.learninganddevelopment.uninav;

import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.SystemClock;
import android.widget.Toast;

import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import com.google.android.play.core.appupdate.AppUpdateManagerFactory;
import com.google.android.play.core.install.model.UpdateAvailability;

import org.json.JSONObject;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * "A newer version is on Google Play" - shown only when this install really
 * is out of date.
 *
 * Either of two sources is enough to decide that:
 *  - Google Play itself (In-App Updates API), which is authoritative for
 *    installs that came from Play and needs no server setting at all;
 *  - the server's latest / minimum version (admin console, Mobile app),
 *    which also covers side-loaded installs Play does not know about and can
 *    force an update below a minimum version.
 * The server can also switch the prompt off altogether ("prompt": false).
 *
 * The same launch request delivers the push settings to PushManager.
 */
final class UpdatePrompt {

    /** "Later" holds for this app session, or 12 hours if the app stays open. */
    private static final long REPEAT_AFTER_MS = 12L * 60 * 60 * 1000;

    // Process-wide, so "once per app open" survives the activity being recreated.
    private static long lastCheckAt;
    private static long lastShownAt;
    private static boolean forced;

    private final AppCompatActivity activity;
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private AlertDialog dialog;
    private boolean checking;

    UpdatePrompt(AppCompatActivity activity) {
        this.activity = activity;
    }

    /** Call from onResume. Does nothing unless a check or a forced prompt is due. */
    void onResume() {
        if (forced) show(true);
        long now = SystemClock.elapsedRealtime();
        if (checking || (lastCheckAt != 0 && now - lastCheckAt < REPEAT_AFTER_MS)) return;
        checking = true;
        Context app = activity.getApplicationContext();
        executor.execute(() -> {
            ServerApi.Reply reply = ServerApi.appConfig();
            if (reply.ok()) PushManager.applyServerConfig(app, reply.json, true);
            ServerVersion server = ServerVersion.from(reply);
            activity.runOnUiThread(() -> askPlay(server));
        });
    }

    void onDestroy() {
        if (dialog != null) dialog.dismiss();
        dialog = null;
        executor.shutdownNow();
    }

    private void askPlay(@Nullable ServerVersion server) {
        if (isGone()) {
            checking = false;
            return;
        }
        if (server != null && !server.prompt) {
            // Switched off on the server.
            finishCheck();
            forced = false;
            return;
        }
        try {
            AppUpdateManagerFactory.create(activity).getAppUpdateInfo().addOnCompleteListener(task -> {
                boolean playHasNewer = task.isSuccessful() && task.getResult() != null
                        && task.getResult().updateAvailability() == UpdateAvailability.UPDATE_AVAILABLE;
                decide(server, playHasNewer);
            });
        } catch (Throwable t) {
            // Not installed from Play, or no Play Store on this phone.
            decide(server, false);
        }
    }

    private void decide(@Nullable ServerVersion server, boolean playHasNewer) {
        finishCheck();
        int mine = BuildConfig.VERSION_CODE;
        boolean mustUpdate = server != null && server.minVersionCode > mine;
        boolean outdated = mustUpdate || playHasNewer
                || (server != null && server.latestVersionCode > mine);
        forced = mustUpdate;
        if (!outdated) return;
        long now = SystemClock.elapsedRealtime();
        if (!mustUpdate && lastShownAt != 0 && now - lastShownAt < REPEAT_AFTER_MS) return;
        show(mustUpdate);
    }

    private void finishCheck() {
        checking = false;
        lastCheckAt = SystemClock.elapsedRealtime();
    }

    private void show(boolean mustUpdate) {
        if (isGone() || (dialog != null && dialog.isShowing())) return;
        lastShownAt = SystemClock.elapsedRealtime();
        AlertDialog.Builder builder = new AlertDialog.Builder(activity)
                .setTitle(R.string.update_title)
                .setMessage(R.string.update_message)
                .setPositiveButton(R.string.update_now, (d, which) -> openStore(activity))
                .setCancelable(!mustUpdate);
        // A forced update has no "Later"; it comes back on every resume.
        if (!mustUpdate) builder.setNegativeButton(R.string.update_later, null);
        dialog = builder.show();
    }

    private boolean isGone() {
        return activity.isFinishing() || activity.isDestroyed();
    }

    /** Opens this app's Play Store listing (the Play app if present, else the web page). */
    static void openStore(Context context) {
        String pkg = BuildConfig.STORE_PACKAGE;
        Intent market = new Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=" + pkg));
        market.setPackage("com.android.vending");
        try {
            context.startActivity(market);
            return;
        } catch (ActivityNotFoundException ignored) {
            // No Play Store app: fall back to the web listing.
        }
        try {
            context.startActivity(new Intent(Intent.ACTION_VIEW,
                    Uri.parse("https://play.google.com/store/apps/details?id=" + pkg)));
        } catch (ActivityNotFoundException e) {
            Toast.makeText(context, R.string.cannot_open_link, Toast.LENGTH_SHORT).show();
        }
    }

    /** The "update" block of api/app_config.php; null when the server gave no opinion. */
    private static final class ServerVersion {
        final boolean prompt;
        final int latestVersionCode;
        final int minVersionCode;

        private ServerVersion(boolean prompt, int latestVersionCode, int minVersionCode) {
            this.prompt = prompt;
            this.latestVersionCode = latestVersionCode;
            this.minVersionCode = minVersionCode;
        }

        @Nullable
        static ServerVersion from(ServerApi.Reply reply) {
            if (!reply.ok() || reply.json == null) return null;      // unreachable: Play decides alone
            JSONObject update = reply.json.optJSONObject("update");
            if (update == null) return null;                          // older server
            return new ServerVersion(
                    update.optBoolean("prompt", true),
                    update.optInt("latest_version_code", 0),
                    update.optInt("min_version_code", 0));
        }
    }
}
