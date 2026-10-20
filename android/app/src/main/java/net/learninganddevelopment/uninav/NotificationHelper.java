package net.learninganddevelopment.uninav;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

public final class NotificationHelper {

    public static final String CHANNEL_UPDATES = "uninav-updates";

    private NotificationHelper() {}

    public static void createChannel(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationChannel channel = new NotificationChannel(
                CHANNEL_UPDATES,
                context.getString(R.string.channel_updates_name),
                NotificationManager.IMPORTANCE_DEFAULT);
        channel.setDescription(context.getString(R.string.channel_updates_description));
        channel.setShowBadge(true);
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager != null) manager.createNotificationChannel(channel);
    }

    /**
     * One notification per update. Tapping it opens the app at that module -
     * the deep link is handled by MainActivity, so it never leaves the app for
     * a browser where the resident would not be signed in.
     *
     * Updates that share a tag share one tray slot: a later "5 new photos"
     * replaces "1 new photo" for the same album instead of stacking, and
     * setOnlyAlertOnce keeps that replacement silent while the first is
     * still showing.
     *
     * @param ts server time of the update in seconds, or 0 if unknown
     */
    public static void showUpdate(Context context, String id, @Nullable String tag,
                                  String title, String body, String url, long ts) {
        createChannel(context);

        String slot = (tag == null || tag.isEmpty()) ? id : tag;
        // Masked rather than Math.abs(): abs(Integer.MIN_VALUE) is negative.
        int notificationId = slot.hashCode() & 0x7fffffff;

        Intent intent = new Intent(context, MainActivity.class);
        intent.setAction(Intent.ACTION_VIEW);
        intent.setData(Uri.parse(safeUrl(url)));
        intent.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);

        PendingIntent pending = PendingIntent.getActivity(context, notificationId, intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_UPDATES)
                .setSmallIcon(R.drawable.ic_notification)
                .setColor(ContextCompat.getColor(context, R.color.uninav_primary))
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setOnlyAlertOnce(true)
                .setGroup("uninav")
                .setPriority(NotificationCompat.PRIORITY_DEFAULT)
                .setContentIntent(pending);
        if (ts > 0) builder.setWhen(ts * 1000L).setShowWhen(true);
        Notification notification = builder.build();

        try {
            // On Android 13+ this is a no-op until the user grants
            // POST_NOTIFICATIONS; MainActivity asks on first launch.
            NotificationManagerCompat.from(context).notify(notificationId, notification);
        } catch (SecurityException ignored) {
            // Permission not granted - nothing to do.
        }
    }

    /**
     * Notification links come from the server, but only an https link on our
     * own host may open the signed-in WebView; anything else opens the home page.
     */
    static String safeUrl(@Nullable String url) {
        if (url != null) {
            try {
                Uri uri = Uri.parse(url);
                if ("https".equalsIgnoreCase(uri.getScheme())
                        && BuildConfig.APP_HOST.equalsIgnoreCase(uri.getHost())) {
                    return uri.toString();
                }
            } catch (RuntimeException ignored) {
                // Unparseable: fall through to the home page.
            }
        }
        return BuildConfig.BASE_URL + "/";
    }
}
