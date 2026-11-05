package com.duntech.nuxbill

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private val Green = Color(0xFF16A34A)
private val Red = Color(0xFFDC2626)
private val Amber = Color(0xFFD97706)

private fun rxColor(state: String): Color = when (state) {
    "ok" -> Green
    "warn" -> Amber
    "over" -> Red
    else -> Color(0xFF6B7280)
}

/* ------------------------------------------------------------------ */
/* Offline banner                                                      */
/* ------------------------------------------------------------------ */

@Composable
fun SyncBanner(onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var showFailed by remember { mutableStateOf(false) }
    val pending = Offline.pending
    val failed = Offline.failedCount
    if (!Offline.showingSaved && pending == 0 && failed == 0) return

    val text = buildString {
        if (Offline.showingSaved) append("Offline: showing the last saved data. ")
        if (pending > 0) append("$pending waiting to send. ")
        if (failed > 0) append("$failed could not be applied.")
    }.trim()

    Row(
        Modifier.fillMaxWidth().background(Amber.copy(alpha = 0.25f)).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(text, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
        if (pending > 0 || Offline.showingSaved) {
            TextButton(onClick = {
                scope.launch {
                    try { Offline.flush() } catch (e: Exception) { }
                    onDone()
                }
            }) { Text("Sync now") }
        }
        if (failed > 0) TextButton(onClick = { showFailed = true }) { Text("Review") }
    }

    if (showFailed) {
        AlertDialog(
            onDismissRequest = { showFailed = false },
            title = { Text("Not applied") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "These were saved while offline, but the server refused them when they were sent.",
                        style = MaterialTheme.typography.bodySmall
                    )
                    Offline.failed().forEach { f ->
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(10.dp)) {
                                Text(actionLabel(f.optString("action")), fontWeight = FontWeight.SemiBold)
                                Text(f.optString("error"), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { Offline.clearFailed(); showFailed = false }) { Text("Clear") } },
            dismissButton = { TextButton(onClick = { showFailed = false }) { Text("Close") } }
        )
    }
}

/* ------------------------------------------------------------------ */
/* ONU monitoring                                                      */
/* ------------------------------------------------------------------ */

@Composable
fun OnuScreen(reload: Int) {
    var q by remember { mutableStateOf("") }
    var filter by remember { mutableStateOf("") }
    var open by remember { mutableStateOf<String?>(null) }
    var bump by remember { mutableIntStateOf(0) }

    Column(Modifier.fillMaxSize()) {
        OutlinedTextField(
            q, { q = it }, singleLine = true,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp),
            label = { Text("Search serial, model or customer") }
        )
        LazyRow(contentPadding = PaddingValues(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            val opts = listOf("" to "All", "offline" to "Offline", "online" to "Online", "weak" to "Weak signal")
            items(opts.size) { i ->
                FilterChip(selected = filter == opts[i].first, onClick = { filter = opts[i].first }, label = { Text(opts[i].second) })
            }
        }
        Remote(listOf(reload, bump, q, filter), load = {
            Api.call("onu_list", mapOf("q" to q.trim(), "filter" to filter))
        }) { j ->
            val st = j.optJSONObject("stats")
            val rows = j.optJSONArray("rows").list()
            LazyVerticalGrid(
                GridCells.Adaptive(300.dp),
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                item(span = { GridItemSpan(maxLineSpan) }) {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (!j.optBoolean("live")) {
                            Text(
                                "GenieACS is not reachable, so online status is unknown. Showing the last signal readings the system recorded.",
                                style = MaterialTheme.typography.bodySmall, color = Amber
                            )
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            StatCard("Total", (st?.optInt("total") ?: 0).toString(), "info", Modifier.weight(1f))
                            StatCard("Online", (st?.optInt("online") ?: 0).toString(), "good", Modifier.weight(1f))
                            StatCard("Offline", (st?.optInt("offline") ?: 0).toString(), "bad", Modifier.weight(1f))
                            StatCard("Weak", (st?.optInt("weak") ?: 0).toString(), "bad", Modifier.weight(1f))
                        }
                    }
                }
                items(rows) { o ->
                    val online = if (o.isNull("online")) null else o.optBoolean("online")
                    Card(Modifier.fillMaxWidth().clickable { open = o.optString("id") }) {
                        Column(Modifier.padding(14.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Box(
                                    Modifier.width(10.dp).height(10.dp).background(
                                        when (online) { true -> Green; false -> Red; null -> Color(0xFF6B7280) },
                                        MaterialTheme.shapes.extraLarge
                                    )
                                )
                                Spacer(Modifier.width(8.dp))
                                Text(
                                    o.optString("model").ifBlank { o.optString("id") },
                                    fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1
                                )
                                if (!o.isNull("rx")) {
                                    Text(
                                        o.optDouble("rx").toString() + " dBm",
                                        color = rxColor(o.optString("rx_state")), fontWeight = FontWeight.SemiBold
                                    )
                                }
                            }
                            KeyValue("Serial", o.optString("serial"))
                            val cu = o.optJSONObject("customer")
                            KeyValue("Customer", cu?.optString("name") ?: "Not assigned")
                            KeyValue("Last seen", o.optString("last_inform"))
                        }
                    }
                }
            }
        }
    }

    open?.let { id -> OnuDetail(id, onClose = { open = null }, onChanged = { bump++ }) }
}

@Composable
private fun OnuDetail(id: String, onClose: () -> Unit, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var confirm by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("ONU") },
        text = {
            Remote(id, modifier = Modifier.fillMaxWidth().height(320.dp), load = { Api.call("onu_detail", mapOf("id" to id)) }) { j ->
                val o = j.getJSONObject("onu")
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    KeyValue("Model", o.optString("model"))
                    KeyValue("Serial", o.optString("serial"))
                    KeyValue(
                        "Status",
                        if (o.isNull("online")) "Unknown" else if (o.optBoolean("online")) "Online" else "Offline"
                    )
                    KeyValue("Last seen", o.optString("last_inform"))
                    KeyValue("Signal", if (o.isNull("rx")) "" else o.optDouble("rx").toString() + " dBm")
                    KeyValue("Wi-Fi", o.optString("ssid"))
                    KeyValue("Software", o.optString("software"))
                    val cu = o.optJSONObject("customer")
                    if (cu != null) {
                        KeyValue("Customer", cu.optString("name"))
                        KeyValue("Phone", cu.optString("phone"))
                    }
                    val hist = j.optJSONArray("history").list()
                    if (hist.isNotEmpty()) {
                        Spacer(Modifier.height(6.dp))
                        Text("Signal history", style = MaterialTheme.typography.titleSmall)
                        hist.take(8).forEach { h ->
                            KeyValue(h.optString("time"), if (h.isNull("rx")) "no reading" else h.optDouble("rx").toString() + " dBm")
                        }
                    }
                    val alerts = j.optJSONArray("alerts").list()
                    if (alerts.isNotEmpty()) {
                        Spacer(Modifier.height(6.dp))
                        Text("Recent alerts", style = MaterialTheme.typography.titleSmall)
                        alerts.take(5).forEach { a -> KeyValue(a.optString("time"), a.optString("message")) }
                    }
                    val tasks = j.optJSONArray("tasks").list()
                    if (tasks.isNotEmpty()) {
                        Spacer(Modifier.height(6.dp))
                        Text("Recent actions", style = MaterialTheme.typography.titleSmall)
                        tasks.take(5).forEach { t -> KeyValue(t.optString("time"), t.optString("task") + " · " + t.optString("status")) }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = { confirm = true }) { Text("Reboot ONU") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Close") } }
    )

    if (confirm) {
        AlertDialog(
            onDismissRequest = { confirm = false },
            title = { Text("Reboot this ONU?") },
            text = { Text("The customer will lose their connection for a minute or two.") },
            confirmButton = {
                Button(onClick = {
                    confirm = false
                    busy = true
                    scope.launch {
                        try {
                            val r = Api.call("onu_reboot", mapOf("id" to id))
                            toast(ctx, r.optString("message", "Reboot sent"))
                            onChanged()
                        } catch (e: Exception) {
                            toast(ctx, e.message)
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Reboot") }
            },
            dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } }
        )
    }
}

/* ------------------------------------------------------------------ */
/* Data usage and FUP                                                  */
/* ------------------------------------------------------------------ */

@Composable
fun UsagePanel(u: JSONObject) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatCard("Downloaded this month", u.optString("month_down"), "info", Modifier.weight(1f))
            StatCard("Uploaded this month", u.optString("month_up"), "info", Modifier.weight(1f))
        }
        val fup = u.optJSONObject("fup")
        if (fup != null) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Fair usage limit", fontWeight = FontWeight.Bold)
                    val pct = if (u.isNull("percent")) 0 else u.optInt("percent")
                    LinearProgressIndicator(progress = { pct / 100f }, modifier = Modifier.fillMaxWidth())
                    KeyValue("Used", u.optString("used") + " of " + fup.optString("quota") + " (" + fup.optString("period") + ")")
                    KeyValue("When reached", if (fup.optString("action") == "throttle") "Slowed to " + fup.optString("throttle_rate") else fup.optString("action"))
                    if (u.optBoolean("throttled")) {
                        Text(
                            "Speed is limited right now" + (u.optString("throttled_at").takeIf { it.isNotBlank() && it != "null" }?.let { " since $it" } ?: ""),
                            color = Red, fontWeight = FontWeight.SemiBold
                        )
                    }
                }
            }
        }
        val days = u.optJSONArray("days").list()
        if (days.isNotEmpty()) {
            val max = days.maxOf { it.optDouble("down") + it.optDouble("up") }.coerceAtLeast(1.0)
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text("Last 14 days", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    Row(Modifier.fillMaxWidth().height(90.dp), horizontalArrangement = Arrangement.spacedBy(3.dp), verticalAlignment = Alignment.Bottom) {
                        days.forEach { d ->
                            val f = ((d.optDouble("down") + d.optDouble("up")) / max).toFloat().coerceIn(0.03f, 1f)
                            Box(Modifier.weight(1f).fillMaxHeight(f).background(MaterialTheme.colorScheme.primary))
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                    days.takeLast(5).reversed().forEach { d ->
                        KeyValue(d.optString("day"), "↓ " + d.optString("down_text") + "   ↑ " + d.optString("up_text"))
                    }
                }
            }
        } else {
            Text("No daily usage recorded yet.", style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
fun ClientUsage(reload: Int) {
    Remote(reload, load = { Api.call("client_usage") }) { j ->
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Text("My data usage", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(10.dp))
            UsagePanel(j.getJSONObject("usage"))
        }
    }
}

@Composable
fun UsageScreen(reload: Int) {
    var who by remember { mutableStateOf<String?>(null) }
    var lookup by remember { mutableStateOf("") }

    Remote(reload, load = { Api.call("usage_overview") }) { j ->
        val top = j.optJSONArray("top").list()
        val thr = j.optJSONArray("throttled").list()
        val rules = j.optJSONArray("rules").list()
        LazyVerticalGrid(
            GridCells.Adaptive(300.dp),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            item(span = { GridItemSpan(maxLineSpan) }) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    val t = j.optJSONObject("today")
                    val m = j.optJSONObject("month")
                    StatCard("Today ↓", t?.optString("down") ?: "", "info", Modifier.weight(1f))
                    StatCard("This month ↓", m?.optString("down") ?: "", "info", Modifier.weight(1f))
                    StatCard("Throttled", thr.size.toString(), if (thr.isEmpty()) "good" else "bad", Modifier.weight(1f))
                }
            }
            item(span = { GridItemSpan(maxLineSpan) }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(
                        lookup, { lookup = it }, singleLine = true, modifier = Modifier.weight(1f),
                        label = { Text("Look up a username") }
                    )
                    TextButton(enabled = lookup.isNotBlank(), onClick = { who = lookup.trim() }) { Text("Open") }
                }
            }
            if (thr.isNotEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) { Text("Currently throttled", style = MaterialTheme.typography.titleMedium, color = Red) }
                items(thr) { t ->
                    Card(
                        Modifier.fillMaxWidth().clickable { who = t.optString("username") },
                        colors = CardDefaults.cardColors(containerColor = Red.copy(alpha = 0.12f))
                    ) {
                        Column(Modifier.padding(14.dp)) {
                            Text(t.optString("username"), fontWeight = FontWeight.Bold)
                            KeyValue("Type", t.optString("type"))
                            KeyValue("Profile", t.optString("profile"))
                            KeyValue("Since", t.optString("since"))
                        }
                    }
                }
            }
            item(span = { GridItemSpan(maxLineSpan) }) { Text("Top users this month", style = MaterialTheme.typography.titleMedium) }
            if (top.isEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) { Text("No usage recorded this month yet.", style = MaterialTheme.typography.bodySmall) }
            }
            items(top) { t ->
                Card(Modifier.fillMaxWidth().clickable { who = t.optString("username") }) {
                    Column(Modifier.padding(14.dp)) {
                        Text(t.optString("username"), fontWeight = FontWeight.Bold)
                        KeyValue("Downloaded", t.optString("down"))
                        KeyValue("Uploaded", t.optString("up"))
                        KeyValue("Total", t.optString("total"))
                    }
                }
            }
            if (rules.isNotEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) { Text("FUP rules", style = MaterialTheme.typography.titleMedium) }
                items(rules) { r ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Text(r.optString("plan"), fontWeight = FontWeight.Bold)
                            KeyValue("Quota", r.optString("quota") + " per " + r.optString("period"))
                            KeyValue("Then", if (r.optString("action") == "throttle") "Slow to " + r.optString("rate") else r.optString("action"))
                        }
                    }
                }
            }
        }
    }

    who?.let { name ->
        AlertDialog(
            onDismissRequest = { who = null },
            title = { Text(name) },
            text = {
                Remote(name, modifier = Modifier.fillMaxWidth().height(380.dp), load = { Api.call("usage_user", mapOf("username" to name)) }) { j ->
                    Column(Modifier.verticalScroll(rememberScrollState())) { UsagePanel(j.getJSONObject("usage")) }
                }
            },
            confirmButton = { TextButton(onClick = { who = null }) { Text("Close") } }
        )
    }
}

/* ------------------------------------------------------------------ */
/* Audit log                                                           */
/* ------------------------------------------------------------------ */

@Composable
fun AuditScreen(reload: Int) {
    var q by remember { mutableStateOf("") }
    var action by remember { mutableStateOf("") }

    Column(Modifier.fillMaxSize()) {
        OutlinedTextField(
            q, { q = it }, singleLine = true,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp),
            label = { Text("Search person or detail") }
        )
        Remote(listOf(reload, q, action), load = {
            Api.call("audit_list", mapOf("q" to q.trim(), "action" to action, "include_hr" to "1"))
        }) { j ->
            val kinds = j.optJSONArray("actions").strings()
            val rows = j.optJSONArray("rows").list()
            Column(Modifier.fillMaxSize()) {
                LazyRow(contentPadding = PaddingValues(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item { FilterChip(selected = action == "", onClick = { action = "" }, label = { Text("All") }) }
                    items(kinds) { k -> FilterChip(selected = action == k, onClick = { action = k }, label = { Text(k) }) }
                }
                LazyVerticalGrid(
                    GridCells.Adaptive(320.dp),
                    Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(12.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    if (rows.isEmpty()) {
                        item(span = { GridItemSpan(maxLineSpan) }) { Text("Nothing recorded yet.", style = MaterialTheme.typography.bodyMedium) }
                    }
                    items(rows) { r ->
                        val denied = r.optString("action") == "denied" || r.optString("action") == "login_failed" || r.optString("details").startsWith("FAILED")
                        Card(
                            Modifier.fillMaxWidth(),
                            colors = if (denied) CardDefaults.cardColors(containerColor = Red.copy(alpha = 0.12f)) else CardDefaults.cardColors()
                        ) {
                            Column(Modifier.padding(12.dp)) {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    Text(r.optString("action"), fontWeight = FontWeight.Bold)
                                    Text(r.optString("time"), style = MaterialTheme.typography.labelSmall)
                                }
                                Text(
                                    listOf(r.optString("user"), r.optString("role")).filter { it.isNotBlank() && it != "null" }.joinToString(" · "),
                                    style = MaterialTheme.typography.bodyMedium
                                )
                                val ent = listOf(r.optString("entity"), r.optString("entity_id")).filter { it.isNotBlank() && it != "null" }.joinToString(" #")
                                if (ent.isNotBlank()) Text(ent, style = MaterialTheme.typography.bodySmall)
                                if (r.optString("details").isNotBlank() && r.optString("details") != "null") {
                                    Text(r.optString("details"), style = MaterialTheme.typography.bodySmall)
                                }
                                val qt = r.optString("queued_time")
                                if (qt.isNotBlank() && qt != "null") {
                                    Text("Done offline at $qt", style = MaterialTheme.typography.labelSmall, color = Amber)
                                }
                                if (r.optString("source") == "hr") Text("From the website HR module", style = MaterialTheme.typography.labelSmall)
                            }
                        }
                    }
                }
            }
        }
    }
}
