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

data class Auth(val base: String, val token: String, val role: String, val name: String, val userType: String = "")

object Api {
    var base: String = ""
    var token: String = ""
    var onUnauthorized: (() -> Unit)? = null

    suspend fun call(action: String, params: Map<String, String> = emptyMap()): JSONObject =
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
    }

    fun lastUrl(): String = prefs.getString("url", "") ?: ""

    fun load(): Auth? {
        val t = prefs.getString("token", "") ?: ""
        if (t.isEmpty()) return null
        return Auth(
            prefs.getString("base", "") ?: "",
            t,
            prefs.getString("role", "") ?: "",
            prefs.getString("name", "") ?: "",
            prefs.getString("utype", "") ?: ""
        )
    }

    fun save(a: Auth) {
        prefs.edit()
            .putString("base", a.base).putString("url", a.base)
            .putString("token", a.token)
            .putString("role", a.role)
            .putString("name", a.name)
            .putString("utype", a.userType)
            .apply()
    }

    fun clear() {
        prefs.edit().remove("token").remove("role").remove("name").remove("utype").apply()
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
