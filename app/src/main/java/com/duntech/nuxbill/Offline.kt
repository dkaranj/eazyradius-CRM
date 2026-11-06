package com.duntech.nuxbill

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Offline mode.
 *  - Every screen's last answer is kept on the phone and shown when there is no connection.
 *  - New tickets, status changes, clock in/out and leave requests are queued and sent when the phone is back online.
 *  - Each queued action carries a one-time id, so a retry can never be applied twice by the server.
 */
object Offline {
    private lateinit var dir: File
    private lateinit var prefs: SharedPreferences

    /** Writes that may wait in the queue. Must match mobile_api_queueable() on the server. */
    val queueable = setOf("ops_create", "ops_update", "hr_clock", "hr_leave_request", "checkin")

    /** Answers that must always be live (or fail). */
    val neverCache = setOf("login", "logout", "ping", "onu_reboot", "admin_recharge", "client_buy", "admin_user_save", "admin_user_delete",
        "access_save", "access_reset", "hr_employee_save", "hr_leave_decide", "photo_add", "photo_get", "notify_poll") + queueable

    var pending by mutableIntStateOf(0)
        private set
    var failedCount by mutableIntStateOf(0)
        private set
    var showingSaved by mutableStateOf(false)
        private set
    private var syncing = false

    /** Who the queued actions belong to (server + person), so they survive a logout and are never sent as someone else. */
    var owner: String = ""

    fun init(ctx: Context) {
        val app = ctx.applicationContext
        dir = File(app.filesDir, "offline").apply { mkdirs() }
        prefs = app.getSharedPreferences("nux_offline", Context.MODE_PRIVATE)
        refreshCounts()
    }

    fun newOpId(): String = java.util.UUID.randomUUID().toString().replace("-", "").take(24)

    fun markOffline() { showingSaved = true }
    fun markOnline() { showingSaved = false }

    // ---- read cache ----

    private fun key(action: String, params: Map<String, String>, token: String): String {
        val raw = token + "|" + action + "|" + params.toSortedMap().entries.joinToString("&") { it.key + "=" + it.value }
        return MessageDigest.getInstance("SHA-256").digest(raw.toByteArray()).joinToString("") { "%02x".format(it) }.take(40)
    }

    fun saveCache(action: String, params: Map<String, String>, token: String, json: JSONObject) {
        try {
            File(dir, key(action, params, token) + ".json").writeText(json.toString())
        } catch (e: Exception) {
        }
    }

    fun loadCache(action: String, params: Map<String, String>, token: String): JSONObject? {
        return try {
            val f = File(dir, key(action, params, token) + ".json")
            if (!f.exists()) null else JSONObject(f.readText()).put("_saved", true)
        } catch (e: Exception) {
            null
        }
    }

    fun clearCache() {
        try {
            dir.listFiles()?.forEach { it.delete() }
        } catch (e: Exception) {
        }
    }

    // ---- write queue ----

    private fun readArr(k: String): JSONArray = try {
        JSONArray(prefs.getString(k, "[]"))
    } catch (e: Exception) {
        JSONArray()
    }

    private fun refreshCounts() {
        pending = readArr("queue").length()
        failedCount = readArr("failed").length()
    }

    fun enqueue(action: String, params: Map<String, String>, who: String) {
        val q = readArr("queue")
        val o = JSONObject().put("action", action).put("owner", who)
        val p = JSONObject()
        params.forEach { (k, v) -> p.put(k, v) }
        o.put("params", p)
        q.put(o)
        prefs.edit().putString("queue", q.toString()).apply()
        refreshCounts()
    }

    fun failed(): List<JSONObject> = readArr("failed").list()

    fun clearFailed() {
        prefs.edit().putString("failed", "[]").apply()
        refreshCounts()
    }

    /** Send everything waiting for this user. Stops quietly at the first network failure. */
    suspend fun flush() {
        if (syncing || owner.isEmpty() || Api.token.isEmpty()) return
        syncing = true
        try {
            val q = readArr("queue")
            val keep = JSONArray()
            val bad = readArr("failed")
            var offline = false
            for (i in 0 until q.length()) {
                val o = q.getJSONObject(i)
                if (offline || o.optString("owner") != owner) {
                    keep.put(o)
                    continue
                }
                val params = HashMap<String, String>()
                val p = o.getJSONObject("params")
                p.keys().forEach { k -> params[k] = p.getString(k) }
                params["queued_at"] = params["at"] ?: ""
                try {
                    Api.network(o.getString("action"), params)
                } catch (e: java.io.IOException) {
                    offline = true
                    keep.put(o)
                } catch (e: ApiException) {
                    if (e.code == 401) {
                        keep.put(o)
                        offline = true
                    } else {
                        bad.put(
                            JSONObject().put("action", o.getString("action")).put("error", e.message ?: "Rejected")
                                .put("when", System.currentTimeMillis()).put("params", p)
                        )
                    }
                }
            }
            prefs.edit().putString("queue", keep.toString()).putString("failed", bad.toString()).apply()
            refreshCounts()
        } finally {
            syncing = false
        }
    }
}

fun actionLabel(a: String): String = when (a) {
    "ops_create" -> "New ticket or lead"
    "ops_update" -> "Status or assignment change"
    "hr_clock" -> "Clock in / out"
    "hr_leave_request" -> "Leave request"
    "checkin" -> "Site check-in"
    else -> a
}
