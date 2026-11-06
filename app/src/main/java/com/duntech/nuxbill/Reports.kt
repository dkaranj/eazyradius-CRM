package com.duntech.nuxbill

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
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
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.Card
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import androidx.core.content.FileProvider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File

private fun money(v: Double): String = String.format(java.util.Locale.US, "%,.0f", v)

private fun hoursText(v: Double?): String = if (v == null) "-" else if (v < 0) "-" else String.format(java.util.Locale.US, "%.1f h", v)

/** Writes the CSV the server built to the phone and opens the share sheet (Sheets, Excel, WhatsApp, email...). */
private suspend fun shareCsv(ctx: Context, filename: String, csv: String) {
    val f = withContext(Dispatchers.IO) {
        val dir = File(ctx.cacheDir, "reports").apply { mkdirs() }
        File(dir, filename.replace(Regex("[^A-Za-z0-9._-]"), "_")).apply { writeText(csv) }
    }
    val uri = FileProvider.getUriForFile(ctx, ctx.packageName + ".files", f)
    val send = Intent(Intent.ACTION_SEND).apply {
        type = "text/csv"
        putExtra(Intent.EXTRA_STREAM, uri)
        putExtra(Intent.EXTRA_SUBJECT, filename)
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }
    ctx.startActivity(Intent.createChooser(send, "Share report").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}

@Composable
fun ReportsScreen(reload: Int) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var period by remember { mutableStateOf("week") }
    var busy by remember { mutableStateOf(false) }

    fun export(kind: String) {
        if (busy) return
        busy = true
        scope.launch {
            try {
                val r = Api.call("report_export", mapOf("period" to period, "kind" to kind))
                if (r.optInt("rows") == 0) toast(ctx, "Nothing to export for this period")
                else shareCsv(ctx, r.getString("filename"), r.getString("csv"))
            } catch (e: Exception) {
                toast(ctx, e.message)
            } finally {
                busy = false
            }
        }
    }

    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("today" to "Today", "week" to "7 days", "month" to "This month", "last_month" to "Last month").forEach { (k, l) ->
                FilterChip(selected = period == k, onClick = { period = k }, label = { Text(l) })
            }
        }
        Remote(listOf(reload, period), load = { Api.call("report", mapOf("period" to period)) }) { j -> ReportBody(j, busy, ::export) }
    }
}

@Composable
private fun ReportBody(j: JSONObject, busy: Boolean, export: (String) -> Unit) {
    val rev = j.getJSONObject("revenue")
    val cu = j.getJSONObject("customers")
    val days = rev.optJSONArray("days").list()
    val methods = rev.optJSONArray("methods").list()
    val plans = rev.optJSONArray("plans").list()
    val expiring = cu.optJSONArray("expiring").list()
    val team = j.optJSONArray("team").list()
    val range = j.getJSONObject("range")

    LazyVerticalGrid(
        GridCells.Adaptive(300.dp),
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        item(span = { GridItemSpan(maxLineSpan) }) {
            Text(range.optString("label") + "  (" + range.optString("from") + " to " + range.optString("to") + ")", style = MaterialTheme.typography.labelLarge)
        }
        item(span = { GridItemSpan(maxLineSpan) }) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatCard("Revenue", money(rev.optDouble("total")), "good", Modifier.weight(1f))
                    StatCard("Payments", rev.optInt("count").toString(), "info", Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatCard("Install fees collected", money(rev.optDouble("install_collected")), "good", Modifier.weight(1f))
                    StatCard("New customers", cu.optInt("new").toString(), "info", Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatCard("Active now", cu.optInt("active").toString(), "good", Modifier.weight(1f))
                    StatCard("Lapsed in period", cu.optInt("lapsed").toString(), if (cu.optInt("lapsed") > 0) "bad" else "good", Modifier.weight(1f))
                }
            }
        }
        item(span = { GridItemSpan(maxLineSpan) }) {
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text("Revenue per day", fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    val max = (days.maxOfOrNull { it.optDouble("total") } ?: 0.0).coerceAtLeast(1.0)
                    Row(Modifier.fillMaxWidth().height(90.dp), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.Bottom) {
                        days.forEach { d ->
                            val f = (d.optDouble("total") / max).toFloat().coerceIn(0.02f, 1f)
                            Box(Modifier.weight(1f).fillMaxHeight(f).background(MaterialTheme.colorScheme.primary))
                        }
                    }
                    if (days.isNotEmpty()) {
                        Spacer(Modifier.height(4.dp))
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text(days.first().optString("day").takeLast(5), style = MaterialTheme.typography.labelSmall)
                            Text(days.last().optString("day").takeLast(5), style = MaterialTheme.typography.labelSmall)
                        }
                    }
                }
            }
        }
        if (methods.isNotEmpty()) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text("By payment method", fontWeight = FontWeight.Bold)
                        methods.forEach { m -> KeyValue(m.optString("method") + " (" + m.optInt("count") + ")", money(m.optDouble("total"))) }
                    }
                }
            }
        }
        if (plans.isNotEmpty()) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text("Top plans", fontWeight = FontWeight.Bold)
                        plans.forEach { p -> KeyValue(p.optString("plan") + " (" + p.optInt("count") + ")", money(p.optDouble("total"))) }
                    }
                }
            }
        }
        item(span = { GridItemSpan(maxLineSpan) }) {
            Text("Expiring in the next 7 days (${expiring.size})", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 6.dp))
        }
        if (expiring.isEmpty()) item(span = { GridItemSpan(maxLineSpan) }) { Text("Nobody is about to expire.", style = MaterialTheme.typography.bodySmall) }
        items(expiring) { e ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    Text(e.optString("name"), fontWeight = FontWeight.SemiBold)
                    KeyValue("Plan", e.optString("plan"))
                    KeyValue("Expires", e.optString("expires"))
                    val phone = e.optString("phone").takeIf { it != "null" } ?: ""
                    if (phone.isNotBlank()) {
                        Spacer(Modifier.height(4.dp))
                        ContactRow(phone, "Hello " + e.optString("name").substringBefore(' ') + ", your Eazyradius plan (" + e.optString("plan") + ") expires on " + e.optString("expires").take(10) + ". Renew to stay connected.")
                    }
                }
            }
        }
        item(span = { GridItemSpan(maxLineSpan) }) { Text("Team performance", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 6.dp)) }
        if (team.isEmpty()) item(span = { GridItemSpan(maxLineSpan) }) { Text("No assigned work in this period.", style = MaterialTheme.typography.bodySmall) }
        items(team) { t ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp)) {
                    Text(t.optString("name"), fontWeight = FontWeight.Bold)
                    KeyValue("Installations done", t.optInt("installs_done").toString() + "  (avg " + hoursText(if (t.isNull("install_hours")) null else t.optDouble("install_hours")) + ")")
                    KeyValue("Tickets resolved", t.optInt("tickets_done").toString() + " of " + t.optInt("tickets_new") + " new  (avg " + hoursText(if (t.isNull("ticket_hours")) null else t.optDouble("ticket_hours")) + ")")
                    val od = t.optInt("overdue_now")
                    KeyValue("Past SLA now", od.toString())
                    if (od > 0) Text("$od item(s) past SLA", color = Color(0xFFDC2626), fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                }
            }
        }
        item(span = { GridItemSpan(maxLineSpan) }) {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(top = 6.dp)) {
                Text("Export (opens the share sheet: Sheets, Excel, WhatsApp, email)", style = MaterialTheme.typography.titleSmall)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(enabled = !busy, onClick = { export("revenue") }) { Text("Revenue") }
                    OutlinedButton(enabled = !busy, onClick = { export("expiring") }) { Text("Expiring") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(enabled = !busy, onClick = { export("installs") }) { Text("Installations") }
                    OutlinedButton(enabled = !busy, onClick = { export("tickets") }) { Text("Tickets") }
                }
            }
        }
    }
}
