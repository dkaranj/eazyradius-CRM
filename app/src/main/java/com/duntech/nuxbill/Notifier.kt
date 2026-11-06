package com.duntech.nuxbill

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.util.concurrent.TimeUnit

/**
 * Alerts without Google services: the phone asks the server "anything new?" every 30 seconds while the app is open
 * and about every 15 minutes in the background (Android's minimum), and shows what comes back as notifications.
 */
object Notifier {
    private const val CHANNEL = "crm_alerts"
    private val lock = Mutex()

    fun ensureChannel(ctx: Context) {
        if (Build.VERSION.SDK_INT >= 26) {
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Work and network alerts", NotificationManager.IMPORTANCE_HIGH))
        }
    }

    fun canShow(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    fun schedule(ctx: Context) {
        val req = PeriodicWorkRequestBuilder<PollWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(ctx.applicationContext)
            .enqueueUniquePeriodicWork("crm_poll", ExistingPeriodicWorkPolicy.UPDATE, req)
    }

    private fun show(ctx: Context, key: String, title: String, body: String) {
        if (!canShow(ctx)) return
        ensureChannel(ctx)
        val open = PendingIntent.getActivity(
            ctx, 0, Intent(ctx, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val n = NotificationCompat.Builder(ctx, CHANNEL)
            .setSmallIcon(R.drawable.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(open)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        try {
            NotificationManagerCompat.from(ctx).notify(key.hashCode(), n)
        } catch (e: SecurityException) {
        }
    }

    /** Ask the server for anything new for the signed-in person and show it. Safe to call from anywhere. */
    suspend fun poll(ctx: Context) {
        if (Api.token.isEmpty() || Offline.owner.isEmpty()) return
        lock.withLock {
            val prefs = ctx.applicationContext.getSharedPreferences("nux_notify", Context.MODE_PRIVATE)
            val k = Offline.owner
            val j = Api.network(
                "notify_poll",
                mapOf("since_id" to prefs.getLong("id_$k", 0).toString(), "since_ts" to prefs.getLong("ts_$k", 0).toString())
            )
            j.optJSONArray("items").list().forEach { n ->
                show(ctx.applicationContext, n.optString("key"), n.optString("title"), n.optString("body"))
            }
            prefs.edit()
                .putLong("id_$k", j.optLong("max_id", 0))
                .putLong("ts_$k", j.optLong("now", System.currentTimeMillis() / 1000))
                .apply()
        }
    }
}

class PollWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result {
        Store.init(applicationContext)
        val a = Store.load() ?: return Result.success()
        Api.base = a.base
        Api.token = a.token
        Offline.owner = a.base.trimEnd('/') + "|" + a.name
        try {
            Offline.flush()
            Notifier.poll(applicationContext)
        } catch (e: Exception) {
        }
        return Result.success()
    }
}
