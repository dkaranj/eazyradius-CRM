package com.duntech.nuxbill

import android.content.Context
import android.os.Build
import android.util.Log
import java.io.File

/** Keeps the last crash so the next launch can show what went wrong instead of closing silently. */
object CrashLog {
    private fun file(ctx: Context) = File(ctx.filesDir, "last_crash.txt")

    fun install(ctx: Context) {
        val app = ctx.applicationContext
        val prev = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            try {
                file(app).writeText("Android " + Build.VERSION.RELEASE + " · " + Build.MODEL + "\n" + Log.getStackTraceString(e).take(5000))
            } catch (x: Throwable) {
            }
            prev?.uncaughtException(t, e)
        }
    }

    fun pending(ctx: Context): String? = try { file(ctx).takeIf { it.exists() }?.readText() } catch (e: Exception) { null }

    fun clear(ctx: Context) {
        try { file(ctx).delete() } catch (e: Exception) { }
    }
}
