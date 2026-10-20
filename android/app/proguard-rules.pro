# WorkManager instantiates workers reflectively (by class name stored in its
# database), so the worker must keep its name and constructor.
-keep class net.learninganddevelopment.uninav.UpdatesWorker { *; }
-keep class * extends androidx.work.Worker { *; }

# Firebase Messaging and Play In-App Updates ship their own consumer rules.

# org.json is part of the platform.
-dontwarn org.json.**
