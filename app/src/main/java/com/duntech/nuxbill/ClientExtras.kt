package com.duntech.nuxbill

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

/** Outage and maintenance notices the operator published. Hidden when there are none. */
@Composable
fun NoticeBanner(reload: Int) {
    var list by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    LaunchedEffect(reload) {
        list = try { Api.call("client_notices").optJSONArray("notices").list() } catch (e: Exception) { emptyList() }
    }
    list.forEach { n ->
        val sev = n.optString("severity")
        val c = when (sev) { "outage" -> Color(0xFFDC2626); "warning" -> Color(0xFFD97706); else -> Color(0xFF2563EB) }
        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = c.copy(alpha = 0.15f))) {
            Column(Modifier.padding(12.dp)) {
                Text((if (sev == "outage") "Network outage: " else "") + n.optString("title"), fontWeight = FontWeight.Bold, color = c)
                if (n.optString("body").isNotBlank()) Text(n.optString("body"), style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

/** Sends an M-Pesa prompt to the customer's phone and watches for the answer. */
@Composable
fun StkDialog(phone: String, onClose: () -> Unit, forId: Int = 0) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var amount by remember { mutableStateOf("") }
    var ph by remember { mutableStateOf(phone) }
    var busy by remember { mutableStateOf(false) }
    var status by remember { mutableStateOf("") }
    var payId by remember { mutableStateOf(0) }
    var finished by remember { mutableStateOf(false) }

    LaunchedEffect(payId) {
        if (payId == 0) return@LaunchedEffect
        var tries = 0
        while (tries < 45 && !finished) {
            delay(4000)
            tries++
            try {
                val r = Api.call("client_stk_status", mapOf("id" to payId.toString()))
                status = r.optString("message")
                if (r.optString("status") != "pending") finished = true
            } catch (e: Exception) {
            }
        }
        if (!finished) status = "No answer yet. If you paid, your account updates automatically."
        busy = false
    }

    AlertDialog(
        onDismissRequest = { if (!busy || finished) onClose() },
        title = { Text("Top up with M-Pesa") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(amount, { amount = it.filter { c -> c.isDigit() } }, label = { Text("Amount (KES)") }, singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = KeyboardType.Number), enabled = payId == 0)
                OutlinedTextField(ph, { ph = it }, label = { Text("M-Pesa phone number") }, singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = KeyboardType.Phone), enabled = payId == 0)
                if (status.isNotBlank()) Text(status, color = MaterialTheme.colorScheme.primary)
                if (busy && !finished) LinearProgressIndicator(Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            if (payId == 0 || finished) {
                Button(enabled = !busy || finished, onClick = {
                    if (finished) { onClose(); return@Button }
                    busy = true
                    scope.launch {
                        try {
                            val r = Api.call("client_stk", mapOf("amount" to amount, "phone" to ph) + (if (forId > 0) mapOf("for" to forId.toString()) else emptyMap()))
                            status = r.optString("message")
                            payId = r.optInt("id")
                        } catch (e: Exception) {
                            toast(ctx, e.message)
                            busy = false
                        }
                    }
                }) { Text(if (finished) "Done" else "Send request") }
            }
        },
        dismissButton = { if (!busy || finished) TextButton(onClick = onClose) { Text("Close") } }
    )
}

@Composable
fun WifiDialog(onClose: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var info by remember { mutableStateOf<JSONObject?>(null) }
    var err by remember { mutableStateOf("") }
    var ssid by remember { mutableStateOf("") }
    var pass by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        try {
            val r = Api.call("client_wifi_get")
            info = r
            ssid = r.optString("ssid")
        } catch (e: Exception) { err = e.message ?: "Failed" }
    }
    val i = info
    val ready = i != null && i.optBoolean("linked") && i.optBoolean("reachable") && i.optBoolean("supported")
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("My Wi-Fi") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                when {
                    err.isNotBlank() -> Text(err, color = MaterialTheme.colorScheme.error)
                    i == null -> LinearProgressIndicator(Modifier.fillMaxWidth())
                    !i.optBoolean("linked") -> Text(i.optString("message", "Your router is not linked yet."))
                    !i.optBoolean("reachable") -> Text("We cannot reach your router right now. Try again later.")
                    !i.optBoolean("supported") -> Text("This router model cannot be changed from the app. Contact support.")
                    else -> {
                        OutlinedTextField(ssid, { ssid = it }, label = { Text("Wi-Fi name") }, singleLine = true)
                        OutlinedTextField(pass, { pass = it }, label = { Text("New password (8-63 characters)") }, singleLine = true,
                            visualTransformation = PasswordVisualTransformation(), supportingText = { Text("Leave empty to keep the current password") })
                        Text("Devices on your Wi-Fi will disconnect briefly and need the new details.", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        },
        confirmButton = {
            if (ready) Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        val p = HashMap<String, String>()
                        if (ssid.isNotBlank() && ssid != i?.optString("ssid")) p["ssid"] = ssid
                        if (pass.isNotEmpty()) p["password"] = pass
                        val r = Api.call("client_wifi_set", p)
                        toast(ctx, r.optString("message"))
                        onClose()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                        busy = false
                    }
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Close") } }
    )
}

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
/** Customer's own tickets, a form to raise one, and the conversation on each. */
@Composable
fun ClientSupport(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    var open by remember { mutableStateOf<JSONObject?>(null) }
    Column(Modifier.fillMaxSize()) {
        Button(onClick = { creating = true }, modifier = Modifier.fillMaxWidth().padding(12.dp)) { Text("Report a problem") }
        Remote(listOf(reload, bump), load = { Api.call("client_tickets") }) { j ->
            val list = j.optJSONArray("tickets").list()
            if (list.isEmpty()) {
                Text("No tickets yet. If something is wrong with your internet, tap Report a problem.", Modifier.padding(16.dp))
            }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(list) { t ->
                    Card(onClick = { open = t }, modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(t.optString("title"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                Text(t.optString("status"), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
                            }
                            Text(t.optString("created") + " · " + t.optInt("comments") + " message(s)", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
    }
    if (creating) NewTicketDialog(onClose = { creating = false }, onDone = { creating = false; bump++ })
    open?.let { t ->
        CommentsDialog(t.optInt("id"), t.optString("title"), staff = false, onClose = { open = null; bump++ })
    }
}

@Composable
private fun NewTicketDialog(onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Report a problem") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(title, { title = it }, label = { Text("What is wrong?") }, placeholder = { Text("e.g. No internet since morning") })
                OutlinedTextField(notes, { notes = it }, label = { Text("More details (optional)") }, minLines = 3)
            }
        },
        confirmButton = {
            Button(enabled = !busy && title.isNotBlank(), onClick = {
                busy = true
                scope.launch {
                    try {
                        val r = Api.call("client_ticket_create", mapOf("title" to title, "notes" to notes))
                        toast(ctx, r.optString("message"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                        busy = false
                    }
                }
            }) { Text("Send") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

/** The conversation on one ticket. Staff also see and can write internal notes that customers never see. */
@Composable
fun CommentsDialog(id: Int, title: String, staff: Boolean, onClose: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var text by remember { mutableStateOf("") }
    var internal by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(title, maxLines = 2) },
        text = {
            Column {
                Box(Modifier.heightIn(max = 300.dp)) {
                    Remote(bump, Modifier.fillMaxWidth().height(120.dp), load = {
                        Api.call(if (staff) "ticket_comments" else "client_ticket_comments", mapOf("id" to id.toString()))
                    }) { j ->
                        val list = j.optJSONArray("comments").list()
                        if (list.isEmpty()) Text("No messages yet.")
                        LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            items(list) { c ->
                                val note = c.optBoolean("internal")
                                val mine = if (staff) c.optString("type") == "staff" else c.optString("type") == "client"
                                Card(
                                    colors = CardDefaults.cardColors(
                                        containerColor = if (note) Color(0xFFD97706).copy(alpha = 0.18f)
                                        else if (mine) MaterialTheme.colorScheme.primary.copy(alpha = 0.14f) else MaterialTheme.colorScheme.surfaceVariant
                                    )
                                ) {
                                    Column(Modifier.padding(10.dp).fillMaxWidth()) {
                                        Text((if (note) "Internal note · " else "") + c.optString("by") + " · " + c.optString("time").takeLast(8).take(5),
                                            style = MaterialTheme.typography.labelSmall)
                                        Text(c.optString("text"))
                                    }
                                }
                            }
                        }
                    }
                }
                OutlinedTextField(text, { text = it }, label = { Text(if (staff) "Reply or note" else "Your message") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                if (staff) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Switch(checked = internal, onCheckedChange = { internal = it })
                        Spacer(Modifier.width(8.dp))
                        Text("Internal note (customer will not see it)", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy && text.isNotBlank(), onClick = {
                busy = true
                scope.launch {
                    try {
                        val p = mutableMapOf("id" to id.toString(), "body" to text)
                        if (staff && internal) p["internal"] = "1"
                        val r = Api.call(if (staff) "ticket_comment_add" else "client_ticket_comment_add", p)
                        if (r.optBoolean("queued")) toast(ctx, r.optString("message"))
                        text = ""
                        bump++
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    }
                    busy = false
                }
            }) { Text("Send") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Close") } }
    )
}
