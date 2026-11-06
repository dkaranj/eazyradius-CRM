package com.duntech.nuxbill

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private val PayGreen = Color(0xFF16A34A)
private val PayRed = Color(0xFFDC2626)
private val PayAmber = Color(0xFFD97706)

private fun payTone(status: String): Color = when (status) {
    "paid" -> PayGreen
    "waived" -> Color(0xFF6B7280)
    "awaiting" -> PayAmber
    else -> PayRed
}

private fun payLabel(status: String): String = when (status) {
    "paid" -> "PAID"
    "waived" -> "SKIPPED"
    "awaiting" -> "AWAITING PAYMENT"
    else -> "NOT REQUESTED"
}

@Composable
fun InstallPayScreen(reload: Int) {
    var filter by remember { mutableStateOf("unpaid") }
    var bump by remember { mutableIntStateOf(0) }
    var open by remember { mutableStateOf<Int?>(null) }

    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("unpaid" to "Unpaid", "paid" to "Paid", "" to "All").forEach { (k, label) ->
                FilterChip(selected = filter == k, onClick = { filter = k }, label = { Text(label) })
            }
        }
        Remote(listOf(reload, bump, filter), load = { Api.call("inst_pay_list", mapOf("filter" to filter)) }) { j ->
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
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        StatCard("Waiting for payment", (st?.optInt("waiting") ?: 0).toString(), if ((st?.optInt("waiting") ?: 0) > 0) "bad" else "good", Modifier.weight(1f))
                        StatCard("Still owed", st?.optString("owed") ?: "0", "info", Modifier.weight(1f))
                        StatCard("Collected today", st?.optString("today") ?: "0", "good", Modifier.weight(1f))
                    }
                }
                if (rows.isEmpty()) item(span = { GridItemSpan(maxLineSpan) }) { Text("Nothing here.") }
                items(rows) { r ->
                    val tone = payTone(r.optString("pay_status"))
                    Card(Modifier.fillMaxWidth().clickable { open = r.optInt("id") }) {
                        Column(Modifier.padding(14.dp)) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(r.optString("job_no") + " · " + r.optString("customer"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            }
                            Text(payLabel(r.optString("pay_status")), color = tone, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                            Spacer(Modifier.height(4.dp))
                            KeyValue("Plan", r.optString("plan"))
                            KeyValue("Amount due", r.optString("due"))
                            KeyValue("Paid", r.optString("paid"))
                            KeyValue("Balance", r.optString("balance"))
                            KeyValue("Job status", r.optString("status").replace('_', ' '))
                            KeyValue("Technician", r.optString("technician"))
                        }
                    }
                }
            }
        }
    }
    open?.let { id -> PayDetailDialog(id, onClose = { open = null }) { bump++ } }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PayDetailDialog(id: Int, onClose: () -> Unit, onChanged: () -> Unit) {
    var bump by remember { mutableIntStateOf(0) }
    var mode by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Installation payment") },
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        text = {
            Remote(listOf(id, bump), Modifier.fillMaxWidth().heightIn(min = 200.dp), load = { Api.call("inst_pay_detail", mapOf("id" to id.toString())) }) { j ->
                val job = j.getJSONObject("job")
                val manager = j.optBoolean("manager")
                val hist = j.optJSONArray("history").list()
                val suggested = j.optJSONArray("suggested").list()
                val ps = job.optString("pay_status")
                val open = ps != "paid" && ps != "waived" && job.optString("status") != "completed" && job.optString("status") != "cancelled"
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(job.optString("job_no") + " · " + job.optString("customer"), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    ContactRow(job.optString("phone"), "Hello, about your installation " + job.optString("job_no") + ". ")
                    Text(payLabel(ps), color = payTone(ps), fontWeight = FontWeight.Bold)
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            KeyValue("Plan", job.optString("plan"))
                            KeyValue("Amount due", job.optString("due"))
                            KeyValue("Paid", job.optString("paid"))
                            KeyValue("Balance", job.optString("balance"))
                            KeyValue("Pay to (account no.)", job.optString("job_no"))
                            KeyValue("Job status", job.optString("status").replace('_', ' '))
                            KeyValue("Technician", job.optString("technician"))
                            KeyValue("Note", job.optString("note"))
                        }
                    }
                    if (ps == "paid" || ps == "waived") {
                        Text(
                            "Payment is settled. Finish the installation from the Installations plugin on the website so the package is activated.",
                            style = MaterialTheme.typography.bodySmall
                        )
                    }
                    if (manager && open) {
                        if (suggested.isNotEmpty()) {
                            Text("M-Pesa payments sent to " + job.optString("job_no"), style = MaterialTheme.typography.titleSmall)
                            suggested.forEach { t ->
                                AssistChip(onClick = { mode = "mpesa:" + t.optString("code") }, label = { Text(t.optString("code") + " · " + t.optString("amount") + " · " + t.optString("name")) })
                            }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = { mode = "mpesa:" }) { Text("M-Pesa code") }
                            OutlinedButton(onClick = { mode = "cash" }) { Text("Cash") }
                            OutlinedButton(onClick = { mode = "waive" }) { Text("Skip payment", color = PayRed) }
                        }
                    } else if (!manager && open) {
                        Text("A manager records cash or M-Pesa payments and can skip payment.", style = MaterialTheme.typography.bodySmall)
                    }
                    Text("Payments (${hist.size})", style = MaterialTheme.typography.titleSmall)
                    if (hist.isEmpty()) Text("No payments recorded.", style = MaterialTheme.typography.bodySmall)
                    hist.forEach { h ->
                        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors()) {
                            Column(Modifier.padding(10.dp)) {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    Text(h.optString("method").uppercase() + " · " + h.optString("amount"), fontWeight = FontWeight.SemiBold)
                                    Text(h.optString("time"), style = MaterialTheme.typography.labelSmall)
                                }
                                Text(listOf(h.optString("ref"), h.optString("payer"), h.optString("by")).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                                if (h.optString("note").isNotBlank()) Text(h.optString("note"), style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
        }
    )

    if (mode.isNotEmpty()) {
        RecordDialog(id, mode, onClose = { mode = "" }) { mode = ""; bump++; onChanged() }
    }
}

@Composable
private fun RecordDialog(id: Int, mode: String, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val kind = mode.substringBefore(':')
    var code by remember { mutableStateOf(mode.substringAfter(':', "")) }
    var amount by remember { mutableStateOf("") }
    var payer by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    val title = when (kind) { "mpesa" -> "Claim M-Pesa payment"; "cash" -> "Record cash received"; else -> "Skip payment" }
    val ready = when (kind) {
        "mpesa" -> code.trim().length >= 8
        "cash" -> (amount.toDoubleOrNull() ?: 0.0) > 0
        else -> note.trim().length >= 3
    }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                when (kind) {
                    "mpesa" -> {
                        OutlinedTextField(code, { code = it.uppercase() }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("M-Pesa transaction code") })
                        Text("The amount comes from the payment Safaricom sent to your paybill.", style = MaterialTheme.typography.bodySmall)
                    }
                    "cash" -> {
                        OutlinedTextField(
                            amount, { amount = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Amount received") },
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                        )
                        OutlinedTextField(payer, { payer = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Paid by (optional)") })
                    }
                    else -> Text("The job can then be completed without a payment. This is written to the audit log with your name.", style = MaterialTheme.typography.bodySmall)
                }
                OutlinedTextField(
                    note, { note = it }, modifier = Modifier.fillMaxWidth(),
                    label = { Text(if (kind == "waive") "Reason (required)" else "Note (optional)") }
                )
            }
        },
        confirmButton = {
            Button(enabled = !busy && ready, onClick = {
                busy = true
                scope.launch {
                    try {
                        val r = when (kind) {
                            "mpesa" -> Api.call("inst_pay_record", mapOf("id" to id.toString(), "method" to "mpesa", "ref" to code.trim(), "note" to note.trim()))
                            "cash" -> Api.call("inst_pay_record", mapOf("id" to id.toString(), "method" to "cash", "amount" to amount, "payer" to payer.trim(), "note" to note.trim()))
                            else -> Api.call("inst_pay_waive", mapOf("id" to id.toString(), "note" to note.trim()))
                        }
                        toast(ctx, r.optString("message", "Saved"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text(if (kind == "waive") "Skip payment" else "Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
