package com.example.teamup.fcm           // change if your root package differs

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context.NOTIFICATION_SERVICE
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import com.example.teamup.R              // small icon + app name
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlin.random.Random
import com.example.teamup.util.ActiveChat

class MyFirebaseMessagingService : FirebaseMessagingService() {

    companion object {
        private const val TAG        = "FCM"
        private const val CHANNEL_ID = "chat"   // single channel for chat pushes
    }

    /* Called whenever the registration token changes (optional to handle) */
    override fun onNewToken(token: String) {
        Log.d(TAG, "NEW FCM TOKEN -> $token")
        // Optional: ping backend if you won't wait for next login
    }

    /* Called for every incoming push */
    override fun onMessageReceived(msg: RemoteMessage) {

        /* 1️⃣  Grab eventId from the push’s *data* payload */
        val pushedEventId = msg.data["event_id"]?.toIntOrNull()

        /* 2️⃣  If I’m already inside that chat → do nothing */
        if (pushedEventId != null &&
            pushedEventId == ActiveChat.currentEventId) {
            Log.d(TAG, "Ignoring push for event $pushedEventId – user already inside chat.")
            return
        }

        /* 3️⃣  Otherwise build & show the usual notification */
        val title = msg.notification?.title ?: getString(R.string.app_name)
        val body  = msg.notification?.body  ?: ""

        createNotificationChannelIfNeeded()

        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.icon_up)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        (getSystemService(NOTIFICATION_SERVICE) as NotificationManager)
            .notify(Random.nextInt(), notification)
    }


    /* One-time channel setup for Android 8+ */
    private fun createNotificationChannelIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val mgr = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
            if (mgr.getNotificationChannel(CHANNEL_ID) == null) {
                val channel = NotificationChannel(
                    CHANNEL_ID,
                    "Chat messages",
                    NotificationManager.IMPORTANCE_HIGH
                ).apply {
                    description = "Push notifications for new chat messages"
                    enableLights(true)
                    enableVibration(true)
                }
                mgr.createNotificationChannel(channel)
            }
        }
    }
}
