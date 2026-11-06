package com.duntech.nuxbill

import android.content.Context
import android.os.Build
import android.view.WindowManager
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.launch
import org.json.JSONObject

/** App lock (fingerprint / face / phone PIN), auto-lock delay and screenshot blocking. Kept on this phone only. */
object AppLock {
    private lateinit var prefs: android.content.SharedPreferences
    var locked by mutableStateOf(false)
    private var stoppedAt = 0L

    fun init(ctx: Context) {
        prefs = ctx.getSharedPreferences("nux_security", Context.MODE_PRIVATE)
        if (enabled) locked = true
    }

    var enabled: Boolean
        get() = prefs.getBoolean("lock", false)
        set(v) { prefs.edit().putBoolean("lock", v).apply() }
    /** Seconds in the background before the app locks again. 0 = every time. */
    var delaySecs: Int
        get() = prefs.getInt("delay", 60)
        set(v) { prefs.edit().putInt("delay", v).apply() }
    var blockShots: Boolean
        get() = prefs.getBoolean("secure", false)
        set(v) { prefs.edit().putBoolean("secure", v).apply() }

    fun onStop() { stoppedAt = System.currentTimeMillis() }
    fun onStart() {
        if (enabled && stoppedAt > 0 && (System.currentTimeMillis() - stoppedAt) / 1000 >= delaySecs) locked = true
    }

    fun applySecure(a: android.app.Activity) {
        if (blockShots) a.window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        else a.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
    }

    private const val AUTH = BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL

    fun available(ctx: Context): Boolean = BiometricManager.from(ctx).canAuthenticate(AUTH) == BiometricManager.BIOMETRIC_SUCCESS

    fun prompt(a: FragmentActivity, onOk: () -> Unit, onFail: (String) -> Unit = {}) {
        val p = BiometricPrompt(a, ContextCompat.getMainExecutor(a), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) { onOk() }
            override fun onAuthenticationError(code: Int, msg: CharSequence) { onFail(msg.toString()) }
        })
        p.authenticate(
            BiometricPrompt.PromptInfo.Builder().setTitle("Eazyradius CRM").setSubtitle("Unlock to continue")
                .setAllowedAuthenticators(AUTH).build()
        )
    }
}

@Composable
fun LockScreen(onLogout: () -> Unit) {
    val act = LocalContext.current as? FragmentActivity
    var msg by remember { mutableStateOf("") }
    fun ask() {
        act ?: return
        AppLock.prompt(act, onOk = { AppLock.locked = false }, onFail = { msg = it })
    }
    LaunchedEffect(Unit) { ask() }
    Surface(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
            Text("Eazyradius CRM", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
            Text("Locked", Modifier.padding(top = 4.dp, bottom = 20.dp))
            Button(onClick = { ask() }) { Text("Unlock") }
            if (msg.isNotBlank()) Text(msg, Modifier.padding(top = 8.dp), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            TextButton(onClick = onLogout) { Text("Sign out instead") }
        }
    }
}

@Composable
fun SettingsDialog(onTheme: () -> Unit, onClose: () -> Unit) {
    val ctx = LocalContext.current
    val act = ctx as? FragmentActivity
    val scope = rememberCoroutineScope()
    var lockOn by remember { mutableStateOf(AppLock.enabled) }
    var delay by remember { mutableIntStateOf(AppLock.delaySecs) }
    var shots by remember { mutableStateOf(AppLock.blockShots) }
    var bump by remember { mutableIntStateOf(0) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Settings") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedButton(onClick = { onClose(); onTheme() }, modifier = Modifier.fillMaxWidth()) { Text("Appearance and colours") }
                HorizontalDivider()
                Text("Security", fontWeight = FontWeight.Bold)
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Lock the app (fingerprint, face or phone PIN)", Modifier.weight(1f))
                    Switch(checked = lockOn, onCheckedChange = { want ->
                        if (!want) { AppLock.enabled = false; lockOn = false }
                        else if (!AppLock.available(ctx) || act == null) toast(ctx, "Set a screen lock or fingerprint on this phone first")
                        else AppLock.prompt(act, onOk = { AppLock.enabled = true; lockOn = true }, onFail = { toast(ctx, it) })
                    })
                }
                if (lockOn) {
                    Text("Lock after leaving the app for", style = MaterialTheme.typography.bodySmall)
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf(0 to "Right away", 60 to "1 min", 300 to "5 min").forEach { (s, label) ->
                            FilterChip(selected = delay == s, onClick = { delay = s; AppLock.delaySecs = s }, label = { Text(label) })
                        }
                    }
                }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Block screenshots and hide in recent apps", Modifier.weight(1f))
                    Switch(checked = shots, onCheckedChange = { shots = it; AppLock.blockShots = it; act?.let { a -> AppLock.applySecure(a) } })
                }
                HorizontalDivider()
                Text("Signed-in devices", fontWeight = FontWeight.Bold)
                Remote(bump, Modifier.fillMaxWidth().heightIn(min = 60.dp), load = { Api.call("sessions_list") }) { j ->
                    val rows = j.optJSONArray("sessions").list()
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        rows.forEach { s -> SessionRow(s) { id ->
                            scope.launch {
                                try { Api.call("session_revoke", mapOf("id" to id.toString())); bump++ } catch (e: Exception) { toast(ctx, e.message) }
                            }
                        } }
                        if (rows.size > 1) OutlinedButton(onClick = {
                            scope.launch {
                                try { toast(ctx, Api.call("session_revoke", mapOf("others" to "1")).optString("message")); bump++ } catch (e: Exception) { toast(ctx, e.message) }
                            }
                        }, modifier = Modifier.fillMaxWidth()) { Text("Sign out all other devices") }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onClose) { Text("Done") } }
    )
}

@Composable
private fun SessionRow(s: JSONObject, onRevoke: (Int) -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(s.optString("device") + if (s.optBoolean("current")) " (this phone)" else "", fontWeight = FontWeight.Medium)
                Text("Last used " + s.optString("last_used") + (if (s.optString("ip").isNotBlank()) " · " + s.optString("ip") else ""), style = MaterialTheme.typography.bodySmall)
            }
            if (!s.optBoolean("current")) TextButton(onClick = { onRevoke(s.optInt("id")) }) { Text("Sign out") }
        }
    }
}

fun deviceName(): String = (Build.MANUFACTURER + " " + Build.MODEL).trim().take(60)
