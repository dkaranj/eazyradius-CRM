package com.duntech.nuxbill

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private val StoreRed = Color(0xFFDC2626)
private val StoreGreen = Color(0xFF16A34A)
private val StoreAmber = Color(0xFFD97706)

@Composable
fun StoreScreen(reload: Int) {
    var tab by remember { mutableStateOf("stock") }
    var bump by remember { mutableIntStateOf(0) }
    var requesting by remember { mutableStateOf(false) }
    var receiving by remember { mutableStateOf<JSONObject?>(null) }
    var using by remember { mutableStateOf<JSONObject?>(null) }

    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = tab == "stock", onClick = { tab = "stock" }, label = { Text("Stock") })
            FilterChip(selected = tab == "requests", onClick = { tab = "requests" }, label = { Text("Requests") })
            Spacer(Modifier.weight(1f))
            Button(onClick = { requesting = true }) { Text("New request") }
        }
        if (tab == "stock") {
            Remote(listOf(reload, bump), load = { Api.call("store_overview") }) { j ->
                val manager = j.optBoolean("manager")
                val rows = j.optJSONArray("items").list()
                val st = j.optJSONObject("stats")
                LazyVerticalGrid(
                    GridCells.Adaptive(300.dp),
                    Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(12.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    item(span = { GridItemSpan(maxLineSpan) }) {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            StatCard("Items", (st?.optInt("items") ?: 0).toString(), "info", Modifier.weight(1f))
                            StatCard("Low stock", (st?.optInt("low") ?: 0).toString(), if ((st?.optInt("low") ?: 0) > 0) "bad" else "good", Modifier.weight(1f))
                            StatCard("Requests waiting", (st?.optInt("pending") ?: 0).toString(), "info", Modifier.weight(1f))
                        }
                    }
                    if (rows.isEmpty()) {
                        item(span = { GridItemSpan(maxLineSpan) }) { Text("No items in the store yet.") }
                    }
                    items(rows) { i ->
                        val low = i.optBoolean("low")
                        Card(
                            Modifier.fillMaxWidth(),
                            colors = if (low) CardDefaults.cardColors(containerColor = StoreRed.copy(alpha = 0.12f)) else CardDefaults.cardColors()
                        ) {
                            Column(Modifier.padding(14.dp)) {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    Text(i.optString("name"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                    if (low) Text("LOW", color = StoreRed, fontWeight = FontWeight.Bold)
                                }
                                KeyValue("Category", i.optString("category"))
                                KeyValue("In warehouse", i.optString("warehouse") + " " + i.optString("unit") + "  (min " + i.optString("min") + ")")
                                KeyValue("You hold", i.optString("mine") + " " + i.optString("unit"))
                                if (manager) {
                                    val held = i.optJSONArray("held").list()
                                    if (held.isNotEmpty()) {
                                        KeyValue("With technicians", held.joinToString(", ") { it.optString("name") + " " + it.optString("qty") })
                                    }
                                }
                                Spacer(Modifier.height(6.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    if (i.optString("mine").toDoubleOrNull()?.let { it > 0 } == true) {
                                        OutlinedButton(onClick = { using = i }) { Text("Used on a job") }
                                    }
                                    if (manager) OutlinedButton(onClick = { receiving = i }) { Text("Receive stock") }
                                }
                            }
                        }
                    }
                }
            }
        } else {
            RequestsList(reload, bump) { bump++ }
        }
    }

    if (requesting) RequestDialog(onClose = { requesting = false }) { requesting = false; bump++; tab = "requests" }
    receiving?.let { i -> ReceiveDialog(i, onClose = { receiving = null }) { receiving = null; bump++ } }
    using?.let { i -> UseDialog(i, onClose = { using = null }) { using = null; bump++ } }
}

@Composable
private fun RequestsList(reload: Int, bump: Int, onChanged: () -> Unit) {
    var deciding by remember { mutableStateOf<JSONObject?>(null) }
    Remote(listOf(reload, bump), load = { Api.call("store_requests") }) { j ->
        val manager = j.optBoolean("manager")
        val list = j.optJSONArray("requests").list()
        LazyVerticalGrid(
            GridCells.Adaptive(300.dp),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            if (list.isEmpty()) item(span = { GridItemSpan(maxLineSpan) }) { Text("No requests yet.") }
            items(list) { r ->
                val pending = r.optBoolean("pending")
                val tone = when (r.optString("status")) { "approved" -> StoreGreen; "rejected" -> StoreRed; else -> StoreAmber }
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text(r.optString("req_no"), fontWeight = FontWeight.Bold)
                            Text(r.optString("status").uppercase(), color = tone, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium)
                        }
                        Text(r.optString("tech") + " · " + r.optString("created"), style = MaterialTheme.typography.bodySmall)
                        Spacer(Modifier.height(6.dp))
                        r.optJSONArray("items").list().forEach { l ->
                            val ok = l.optString("qty_ok")
                            val shown = if (pending) l.optString("qty_req") else if (r.optString("status") == "approved") ok + " of " + l.optString("qty_req") else l.optString("qty_req")
                            KeyValue(l.optString("name"), shown + " " + l.optString("unit"))
                        }
                        KeyValue("Note", r.optString("note"))
                        KeyValue("Manager note", r.optString("admin_note"))
                        if (pending && manager) {
                            Spacer(Modifier.height(6.dp))
                            Button(onClick = { deciding = r }) { Text("Review") }
                        }
                    }
                }
            }
        }
    }
    deciding?.let { r -> DecideDialog(r, onClose = { deciding = null }) { deciding = null; onChanged() } }
}

@Composable
private fun DecideDialog(r: JSONObject, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val lines = r.optJSONArray("items").list()
    val qty = remember { mutableStateMapOf<Int, String>().also { m -> lines.forEach { m[it.optInt("item_id")] = it.optString("qty_req") } } }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    fun send(decision: String) {
        busy = true
        scope.launch {
            try {
                val p = mutableMapOf("id" to r.optInt("id").toString(), "decision" to decision, "note" to note.trim())
                if (decision == "approve") p["qty"] = qty.entries.joinToString(",") { it.key.toString() + ":" + it.value.ifBlank { "0" } }
                val res = Api.call("store_request_decide", p)
                toast(ctx, res.optString("message", "Done"))
                onDone()
            } catch (e: Exception) {
                toast(ctx, e.message)
            } finally {
                busy = false
            }
        }
    }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(r.optString("req_no") + " · " + r.optString("tech")) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Change a quantity to approve less than was asked. Set 0 to skip an item.", style = MaterialTheme.typography.bodySmall)
                lines.forEach { l ->
                    val id = l.optInt("item_id")
                    OutlinedTextField(
                        qty[id] ?: "", { qty[id] = it }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                        label = { Text(l.optString("name") + " (asked " + l.optString("qty_req") + " " + l.optString("unit") + ")") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                    )
                }
                OutlinedTextField(note, { note = it }, modifier = Modifier.fillMaxWidth(), label = { Text("Note to the technician") })
            }
        },
        confirmButton = { Button(enabled = !busy, onClick = { send("approve") }) { Text("Approve and issue") } },
        dismissButton = {
            Row {
                TextButton(enabled = !busy, onClick = { send("reject") }) { Text("Reject", color = StoreRed) }
                TextButton(onClick = onClose) { Text("Cancel") }
            }
        }
    )
}

@Composable
private fun RequestDialog(onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val qty = remember { mutableStateMapOf<Int, String>() }
    var note by remember { mutableStateOf("") }
    var techId by remember { mutableStateOf(0) }
    var busy by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Request stock") },
        text = {
            Remote(Unit, Modifier.fillMaxWidth().height(380.dp), load = { Api.call("store_meta") }) { j ->
                val catalog = j.optJSONArray("items").list()
                val kits = j.optJSONArray("kits").list()
                val techs = j.optJSONArray("techs").list()
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (kits.isNotEmpty()) {
                        Text("Quick fill from a kit", style = MaterialTheme.typography.labelLarge)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(kits) { k ->
                                FilterChip(selected = false, onClick = {
                                    k.optJSONArray("lines").list().forEach { l -> qty[l.optInt("item_id")] = l.optString("qty") }
                                }, label = { Text(k.optString("name")) })
                            }
                        }
                    }
                    if (techs.isNotEmpty()) {
                        Text("For", style = MaterialTheme.typography.labelLarge)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            item { FilterChip(selected = techId == 0, onClick = { techId = 0 }, label = { Text("Me") }) }
                            items(techs) { t ->
                                val id = t.optInt("id")
                                FilterChip(selected = techId == id, onClick = { techId = id }, label = { Text(t.optString("name")) })
                            }
                        }
                    }
                    catalog.forEach { i ->
                        val id = i.optInt("id")
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(i.optString("name") + " (" + i.optString("unit") + ")", Modifier.weight(1f))
                            Spacer(Modifier.width(8.dp))
                            OutlinedTextField(
                                qty[id] ?: "", { qty[id] = it }, singleLine = true, modifier = Modifier.width(96.dp),
                                placeholder = { Text("0") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                            )
                        }
                    }
                    OutlinedTextField(note, { note = it }, modifier = Modifier.fillMaxWidth(), label = { Text("Note (optional)") })
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                val lines = qty.entries.filter { (it.value.toDoubleOrNull() ?: 0.0) > 0 }.joinToString(",") { it.key.toString() + ":" + it.value }
                if (lines.isBlank()) {
                    toast(ctx, "Type a quantity for at least one item")
                    return@Button
                }
                busy = true
                scope.launch {
                    try {
                        val p = mutableMapOf("items" to lines, "note" to note.trim())
                        if (techId > 0) p["tech_id"] = techId.toString()
                        val r = Api.call("store_request_create", p)
                        toast(ctx, r.optString("message", "Sent"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text("Send request") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

@Composable
private fun ReceiveDialog(item: JSONObject, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var qty by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Receive " + item.optString("name")) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    qty, { qty = it }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    label = { Text("Quantity (" + item.optString("unit") + ")") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                )
                OutlinedTextField(note, { note = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Note, e.g. supplier") })
            }
        },
        confirmButton = {
            Button(enabled = !busy && (qty.toDoubleOrNull() ?: 0.0) > 0, onClick = {
                busy = true
                scope.launch {
                    try {
                        val r = Api.call("store_receive", mapOf("item_id" to item.optInt("id").toString(), "qty" to qty, "note" to note.trim()))
                        toast(ctx, r.optString("message", "Saved"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text("Receive") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

@Composable
private fun UseDialog(item: JSONObject, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var qty by remember { mutableStateOf("1") }
    var serial by remember { mutableStateOf("") }
    var job by remember { mutableStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Used: " + item.optString("name")) },
        text = {
            Remote(Unit, Modifier.fillMaxWidth().height(260.dp), load = { Api.call("store_meta") }) { j ->
                val jobs = j.optJSONArray("jobs").list()
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("You hold " + item.optString("mine") + " " + item.optString("unit"), style = MaterialTheme.typography.bodySmall)
                    OutlinedTextField(
                        qty, { qty = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Quantity used") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                    )
                    OutlinedTextField(serial, { serial = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Serial number (optional)") })
                    if (jobs.isNotEmpty()) {
                        Text("Installation", style = MaterialTheme.typography.labelLarge)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            item { FilterChip(selected = job == 0, onClick = { job = 0 }, label = { Text("None") }) }
                            items(jobs) { x ->
                                val id = x.optInt("id")
                                FilterChip(selected = job == id, onClick = { job = id }, label = { Text(x.optString("title")) })
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy && (qty.toDoubleOrNull() ?: 0.0) > 0, onClick = {
                busy = true
                scope.launch {
                    try {
                        val p = mutableMapOf("item_id" to item.optInt("id").toString(), "qty" to qty, "serial" to serial.trim())
                        if (job > 0) p["job_id"] = job.toString()
                        val r = Api.call("store_use", p)
                        toast(ctx, r.optString("message", "Saved"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
