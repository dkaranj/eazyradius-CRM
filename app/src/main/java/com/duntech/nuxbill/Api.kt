package com.duntech.nuxbill

import android.content.Context
import android.content.SharedPreferences
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class ApiException(message: String, val code: Int) : Exception(message)

data class Auth(
    val base: String,
    val token: String,
    val role: String,
    val name: String,
    val userType: String = "",
    val modules: List<String> = emptyList(),
    val scopeAll: Boolean = false,
    val canAssign: Boolean = false,
    val canCreate: Boolean = false,
    val isSuper: Boolean = false,
    val isTech: Boolean = false
)

/** Applies the "access" block the server returns from login / me. */
fun Auth.withAccess(a: JSONObject?, userType: String? = null): Auth {
    if (a == null) return this
    return copy(
        userType = userType ?: this.userType,
        modules = a.optJSONArray("modules").strings(),
        scopeAll = a.optString("scope") == "all",
        canAssign = a.optBoolean("assign"),
        canCreate = a.optBoolean("create"),
        isSuper = a.optBoolean("super"),
        isTech = a.optBoolean("is_tech")
    )
}

object Api {
    var base: String = ""
    var token: String = ""
    var onUnauthorized: (() -> Unit)? = null
    /** Modules the signed-in user may open; lets screens hide fields the server would refuse anyway. */
    var modules: List<String> = emptyList()

    /**
     * The one door to the server. Reads fall back to the last saved answer when the phone is offline;
     * a few writes (new tickets, status changes, clocking, leave) wait in a queue and are sent later.
     */
    suspend fun call(action: String, params: Map<String, String> = emptyMap()): JSONObject {
        if (token.isEmpty()) return network(action, params)
        if (action in Offline.queueable) {
            val p = params + ("op_id" to Offline.newOpId()) + ("at" to (System.currentTimeMillis() / 1000).toString())
            return try {
                val r = network(action, p)
                Offline.markOnline()
                r
            } catch (e: java.io.IOException) {
                Offline.enqueue(action, p, Offline.owner)
                JSONObject().put("ok", true).put("queued", true)
                    .put("message", "No connection. Saved on this phone and will be sent automatically")
            }
        }
        return try {
            val r = network(action, params)
            Offline.markOnline()
            if (action !in Offline.neverCache) Offline.saveCache(action, params, token, r)
            r
        } catch (e: java.io.IOException) {
            val c = if (action in Offline.neverCache) null else Offline.loadCache(action, params, token)
            if (c != null) {
                Offline.markOffline()
                c
            } else {
                throw ApiException(if (action in Offline.neverCache) "No connection. This needs internet." else "No connection and nothing saved for this screen yet", 0)
            }
        }
    }

    suspend fun network(action: String, params: Map<String, String> = emptyMap()): JSONObject =
        withContext(Dispatchers.IO) {
            val url = URL(
                base.trimEnd('/') + "/?_route=plugin/mobile_api&a=" + URLEncoder.encode(action, "UTF-8")
            )
            val c = url.openConnection() as HttpURLConnection
            try {
                c.requestMethod = "POST"
                c.connectTimeout = 15000
                c.readTimeout = 30000
                c.doOutput = true
                c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
                if (token.isNotEmpty()) {
                    c.setRequestProperty("X-Mobile-Token", token)
                }
                val all = if (token.isNotEmpty()) params + ("token" to token) else params
                val body = all.entries.joinToString("&") {
                    URLEncoder.encode(it.key, "UTF-8") + "=" + URLEncoder.encode(it.value, "UTF-8")
                }
                c.outputStream.use { it.write(body.toByteArray()) }
                val code = c.responseCode
                val stream = if (code in 200..299) c.inputStream else c.errorStream
                val text = stream?.bufferedReader()?.use { it.readText() } ?: ""
                val json = try {
                    JSONObject(text)
                } catch (e: Exception) {
                    throw ApiException(
                        "Unexpected response ($code). Check the server address and that the mobile_api plugin is installed.",
                        code
                    )
                }
                if (!json.optBoolean("ok", false)) {
                    if (code == 401 && token.isNotEmpty()) {
                        onUnauthorized?.invoke()
                    }
                    throw ApiException(json.optString("error", "Request failed"), code)
                }
                json
            } finally {
                c.disconnect()
            }
        }
}

object Store {
    private lateinit var prefs: SharedPreferences

    fun init(ctx: Context) {
        prefs = ctx.getSharedPreferences("nux", Context.MODE_PRIVATE)
        Offline.init(ctx)
    }

    fun lastUrl(): String = prefs.getString("url", "") ?: ""

    fun load(): Auth? {
        val t = prefs.getString("token", "") ?: ""
        if (t.isEmpty()) return null
        val mods = (prefs.getString("modules", "") ?: "").split(",").filter { it.isNotBlank() }
        return Auth(
            prefs.getString("base", "") ?: "",
            t,
            prefs.getString("role", "") ?: "",
            prefs.getString("name", "") ?: "",
            prefs.getString("utype", "") ?: "",
            mods,
            prefs.getBoolean("scope_all", false),
            prefs.getBoolean("can_assign", false),
            prefs.getBoolean("can_create", false),
            prefs.getBoolean("is_super", false),
            prefs.getBoolean("is_tech", false)
        )
    }

    fun save(a: Auth) {
        prefs.edit()
            .putString("base", a.base).putString("url", a.base)
            .putString("token", a.token)
            .putString("role", a.role)
            .putString("name", a.name)
            .putString("utype", a.userType)
            .putString("modules", a.modules.joinToString(","))
            .putBoolean("scope_all", a.scopeAll)
            .putBoolean("can_assign", a.canAssign)
            .putBoolean("can_create", a.canCreate)
            .putBoolean("is_super", a.isSuper)
            .putBoolean("is_tech", a.isTech)
            .apply()
    }

    fun clear() {
        Offline.clearCache()
        prefs.edit().remove("token").remove("role").remove("name").remove("utype")
            .remove("modules").remove("scope_all").remove("can_assign").remove("can_create")
            .remove("is_super").remove("is_tech").apply()
    }
}

fun JSONArray?.list(): List<JSONObject> {
    if (this == null) return emptyList()
    val out = ArrayList<JSONObject>(length())
    for (i in 0 until length()) out.add(getJSONObject(i))
    return out
}

fun JSONArray?.strings(): List<String> {
    if (this == null) return emptyList()
    val out = ArrayList<String>(length())
    for (i in 0 until length()) out.add(getString(i))
    return out
}
