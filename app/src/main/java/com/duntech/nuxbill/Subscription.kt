package com.duntech.nuxbill

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

/** Customer: pause the plan (a few times a year, up to a few weeks each). Internet is off while paused; the time left is kept. */
@Composable
fun PauseDialog(onClose: () -> Unit, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var days by remember { mutableStateOf("14") }
    var confirm by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text("Pause my plan") },
        text = {
            Remote(bump, Modifier.fillMaxWidth().heightIn(min = 160.dp), load = { Api.call("client_pause_status") }) { j ->
                val maxDays = j.optInt("max_days")
                val active = j.optJSONObject("active")
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(
                        "You can pause up to " + j.optInt("yearly") + " times a year, up to " + maxDays + " days each. Your internet is switched off while paused and the time you have left is kept. It comes back by itself.",
                        style = MaterialTheme.typography.bodyMedium
                    )
                    Text("Pauses used this year: " + j.optInt("used") + " of " + j.optInt("yearly"), fontWeight = FontWeight.SemiBold)
                    if (active != null) {
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text("Paused now", fontWeight = FontWeight.Bold)
                                Text("Since " + active.optString("started").take(16) + ", comes back on " + active.optString("ends").take(16))
                                Text(active.optInt("days_kept").toString() + " day(s) of your plan are kept.", style = MaterialTheme.typography.bodySmall)
                                Button(enabled = !busy, onClick = {
                                    busy = true
                                    scope.launch {
                                        try { toast(ctx, Api.call("client_pause_resume").optString("message")); bump++; onChanged() } catch (e: Exception) { toast(ctx, e.message) }
                                        busy = false
                                    }
                                }) { Text("Resume now") }
                            }
                        }
                    } else if (!j.optBoolean("eligible")) {
                        Text(j.optString("reason"), color = MaterialTheme.colorScheme.error)
                    } else {
                        Text("How many days?", style = MaterialTheme.typography.titleSmall)
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            listOf(7, 14, 21, maxDays).filter { it <= maxDays }.distinct().forEach { d ->
                                FilterChip(selected = days == d.toString(), onClick = { days = d.toString() }, label = { Text(d.toString()) })
                            }
                        }
                        OutlinedTextField(days, { days = it.filter { c -> c.isDigit() }.take(2) }, label = { Text("Days (1 to $maxDays)") }, singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                        Button(enabled = !busy && days.isNotBlank(), onClick = { confirm = true }, modifier = Modifier.fillMaxWidth()) { Text("Pause my plan") }
                    }
                    val hist = j.optJSONArray("history").list()
                    if (hist.isNotEmpty()) {
                        Text("Earlier pauses", style = MaterialTheme.typography.titleSmall)
                        hist.forEach { Text(it.optString("started").take(10) + " for " + it.optInt("days_planned") + " day(s) (" + it.optString("status") + ")", style = MaterialTheme.typography.bodySmall) }
                    }
                }
            }
        }
    )
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false },
        title = { Text("Switch off for $days day(s)?") },
        text = { Text("Your internet goes off now and comes back automatically after $days day(s), or when you tap Resume. This uses one of your pauses for the year.") },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try { toast(ctx, Api.call("client_pause_start", mapOf("days" to days)).optString("message")); confirm = false; bump++; onChanged() }
                    catch (e: Exception) { toast(ctx, e.message); confirm = false }
                    busy = false
                }
            }) { Text("Pause") }
        },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } }
    )
}

/** Banner on Home while the plan is paused. */
@Composable
fun PausedBanner(pause: JSONObject, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color(0xFF2563EB).copy(alpha = 0.15f))) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("Your plan is paused", fontWeight = FontWeight.Bold)
            Text("Your internet is off and comes back on " + pause.optString("until").take(16) + ". " + pause.optInt("days_kept") + " day(s) of your plan are kept.")
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try { toast(ctx, Api.call("client_pause_resume").optString("message")); onChanged() } catch (e: Exception) { toast(ctx, e.message) }
                    busy = false
                }
            }) { Text("Resume now") }
        }
    }
}

/** Switch: renew from the wallet when the plan ends. */
@Composable
fun AutoRenewRow(info: JSONObject, onChanged: () -> Unit) {
    if (!info.optBoolean("available")) return
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    val on = info.optBoolean("on")
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Auto-renew from wallet", fontWeight = FontWeight.SemiBold)
                    Text(if (info.isNull("price")) "Renews your plan when it ends" else "Renews for " + info.optString("price") + " when your plan ends", style = MaterialTheme.typography.bodySmall)
                }
                Switch(checked = on, enabled = !busy, onCheckedChange = { v ->
                    busy = true
                    scope.launch {
                        try { toast(ctx, Api.call("client_autorenew_set", mapOf("on" to if (v) "1" else "0")).optString("message")); onChanged() } catch (e: Exception) { toast(ctx, e.message) }
                        busy = false
                    }
                })
            }
            if (on && !info.isNull("enough") && !info.optBoolean("enough")) {
                Text("Your wallet is below the plan price. Top up so it can renew.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

/** Staff: who is paused, the pause rules, and pausing or resuming a customer on their behalf. */
@Composable
fun PausesScreen(reload: Int) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var picked by remember { mutableStateOf<JSONObject?>(null) }
    var days by remember { mutableStateOf("7") }
    var reason by remember { mutableStateOf("") }
    var counted by remember { mutableStateOf(false) }
    var rules by remember { mutableStateOf<JSONObject?>(null) }
    var busy by remember { mutableStateOf(false) }
    Remote(listOf(reload, bump), load = { Api.call("pause_overview") }) { j ->
        val st = j.getJSONObject("settings")
        val rows = j.optJSONArray("paused").list()
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("Customers can pause " + st.optInt("yearly") + " time(s) a year, up to " + st.optInt("days") + " day(s) each.", fontWeight = FontWeight.SemiBold)
                    OutlinedButton(onClick = { rules = st }) { Text("Change rules") }
                }
            }
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Pause a customer", fontWeight = FontWeight.Bold)
                    val p = picked
                    if (p == null) CustomerPicker { picked = it }
                    else {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                            Text(p.optString("fullname").ifBlank { p.optString("username") } + " · " + p.optString("plan"), Modifier.weight(1f))
                            TextButton(onClick = { picked = null }) { Text("Change") }
                        }
                        OutlinedTextField(days, { days = it.filter { c -> c.isDigit() }.take(2) }, label = { Text("Days") }, singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                        OutlinedTextField(reason, { reason = it.take(200) }, label = { Text("Reason") }, modifier = Modifier.fillMaxWidth())
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Switch(checked = counted, onCheckedChange = { counted = it })
                            Spacer(Modifier.width(8.dp))
                            Text("Counts as one of their yearly pauses", style = MaterialTheme.typography.bodySmall)
                        }
                        Button(enabled = !busy && days.isNotBlank() && reason.trim().length >= 3, onClick = {
                            busy = true
                            scope.launch {
                                try {
                                    toast(ctx, Api.call("pause_staff_start", mapOf("customer_id" to p.optInt("id").toString(), "days" to days, "reason" to reason.trim(), "counted" to if (counted) "1" else "0")).optString("message"))
                                    picked = null; reason = ""; bump++
                                } catch (e: Exception) { toast(ctx, e.message) }
                                busy = false
                            }
                        }) { Text("Pause now") }
                    }
                }
            }
            Text("Paused now (" + rows.size + ")", style = MaterialTheme.typography.titleSmall)
            if (rows.isEmpty()) Text("Nobody is paused.", style = MaterialTheme.typography.bodySmall)
            rows.forEach { r ->
                Card(Modifier.fillMaxWidth(), colors = if (r.optBoolean("failed")) CardDefaults.cardColors(containerColor = Color(0xFFDC2626).copy(alpha = 0.15f)) else CardDefaults.cardColors()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(r.optString("name"), fontWeight = FontWeight.Bold)
                        Text("Since " + r.optString("started").take(10) + ", back on " + r.optString("ends").take(16) + " · " + r.optInt("days_kept") + " day(s) kept · by " + r.optString("by"), style = MaterialTheme.typography.bodySmall)
                        if (r.optString("reason").isNotBlank()) Text(r.optString("reason"), style = MaterialTheme.typography.bodySmall)
                        if (r.optBoolean("failed")) Text("The router could not be reached to switch them back on. Resume manually.", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                        ContactRow(r.optString("phone"), "Hello " + r.optString("name").substringBefore(' ') + ", this is Eazyradius support. ")
                        OutlinedButton(enabled = !busy, onClick = {
                            busy = true
                            scope.launch {
                                try { toast(ctx, Api.call("pause_staff_resume", mapOf("customer_id" to r.optInt("customer_id").toString())).optString("message")); bump++ } catch (e: Exception) { toast(ctx, e.message) }
                                busy = false
                            }
                        }) { Text("Resume now") }
                    }
                }
            }
        }
    }
    rules?.let { st ->
        var y by remember { mutableStateOf(st.optInt("yearly").toString()) }
        var d by remember { mutableStateOf(st.optInt("days").toString()) }
        AlertDialog(
            onDismissRequest = { rules = null },
            title = { Text("Pause rules") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(y, { y = it.filter { c -> c.isDigit() }.take(2) }, label = { Text("Pauses per year") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                    OutlinedTextField(d, { d = it.filter { c -> c.isDigit() }.take(2) }, label = { Text("Days per pause (max 90)") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                }
            },
            confirmButton = {
                Button(onClick = {
                    scope.launch {
                        try { toast(ctx, Api.call("pause_settings_save", mapOf("yearly" to y.ifBlank { "0" }, "days" to d.ifBlank { "1" })).optString("message")); rules = null; bump++ } catch (e: Exception) { toast(ctx, e.message) }
                    }
                }) { Text("Save") }
            },
            dismissButton = { TextButton(onClick = { rules = null }) { Text("Cancel") } }
        )
    }
}
