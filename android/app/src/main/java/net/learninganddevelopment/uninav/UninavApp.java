package net.learninganddevelopment.uninav;

import android.app.Application;

/** Process start-up: runs before any activity, receiver or service. */
public class UninavApp extends Application {

    @Override
    public void onCreate() {
        super.onCreate();
        NotificationHelper.createChannel(this);
        // When FCM wakes a stopped app to deliver a message, this is the only
        // code that runs before PushService - so Firebase has to be ready here.
        PushManager.initFromCache(this);
    }
}
