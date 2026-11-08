package com.duntech.nuxbill

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

private fun hhmm(at: String) = if (at.length >= 16) at.substring(11, 16) else ""

/** One speech bubble. Own messages sit on the right, the other side on the left, notes from the system in the middle. */
@Composable
private fun ChatBubbleRow(m: JSONObject, mine: String) {
    val sender = m.optString("sender")
    if (sender == "system") {
        Text(m.optString("body"), Modifier.fillMaxWidth().padding(vertical = 4.dp), style = MaterialTheme.typography.labelSmall, textAlign = TextAlign.Center,
            color = MaterialTheme.colorScheme.onSurfaceVariant)
        return
    }
    val isMine = sender == mine
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (isMine) Arrangement.End else Arrangement.Start) {
        Column(
            Modifier.widthIn(max = 280.dp).background(
                if (isMine) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant, RoundedCornerShape(14.dp)
            ).padding(horizontal = 12.dp, vertical = 8.dp)
        ) {
            if (!isMine && m.optString("name").isNotBlank()) Text(m.optString("name"), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
            Text(m.optString("body"), style = MaterialTheme.typography.bodyMedium)
            Text(hhmm(m.optString("at")), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun MessageBox(sending: Boolean, canned: List<String>, onSend: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    var menu by remember { mutableStateOf(false) }
    Column {
        Row(Modifier.fillMaxWidth().padding(8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            if (canned.isNotEmpty()) {
                Box {
                    TextButton(onClick = { menu = true }) { Text("Quick") }
                    DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                        canned.forEach { c -> DropdownMenuItem(text = { Text(c, maxLines = 2) }, onClick = { text = c; menu = false }) }
                    }
                }
            }
            OutlinedTextField(text, { if (it.length <= 1000) text = it }, Modifier.weight(1f), placeholder = { Text("Type a message") }, maxLines = 4)
            Button(enabled = !sending && text.isNotBlank(), onClick = { onSend(text.trim()); text = "" }) { Text("Send") }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Customer side: a bubble in the corner that opens a small chat window */
/* ------------------------------------------------------------------ */

@Composable
fun BoxScope.ClientChatPopup() {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var open by rememberSaveable { mutableStateOf(false) }
    var enabled by remember { mutableStateOf(false) }
    var msgs by remember { mutableStateOf(listOf<JSONObject>()) }
    var chatId by remember { mutableIntStateOf(0) }
    var dismissed by remember { mutableIntStateOf(0) }
    var status by remember { mutableStateOf("") }
    var rated by remember { mutableStateOf(false) }
    var agent by remember { mutableStateOf("") }
    var welcome by remember { mutableStateOf("") }
    var hours by remember { mutableStateOf("") }
    var unread by remember { mutableIntStateOf(0) }
    var sending by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf("") }
    val listState = rememberLazyListState()

    suspend fun refresh() {
        var j = Api.call("client_chat", mapOf("after" to (if (chatId == 0) 0 else (msgs.lastOrNull()?.optInt("id") ?: 0)).toString()))
        var ch = j.optJSONObject("chat")
        if (ch != null && ch.optInt("id") == dismissed) ch = null
        val newId = ch?.optInt("id") ?: 0
        if (newId != 0 && newId != chatId && chatId != 0) {
            j = Api.call("client_chat", mapOf("after" to "0"))
            ch = j.optJSONObject("chat")
        }
        enabled = j.optBoolean("enabled", false); welcome = j.optString("welcome"); hours = j.optString("hours")
        val incoming = j.optJSONArray("messages").list()
        if (newId == 0) { chatId = 0; msgs = emptyList(); status = ""; agent = ""; rated = false; failed = ""; return }
        if (newId != chatId) { chatId = newId; msgs = incoming } else msgs = msgs + incoming.filter { n -> msgs.none { it.optInt("id") == n.optInt("id") } }
        status = ch?.optString("status") ?: ""; agent = ch?.optString("agent") ?: ""; rated = ch?.optBoolean("rated") ?: false
        failed = ""
        if (!open) unread += j.optInt("unread")
    }

    // The bubble checks quietly now and then; with the window open it keeps up with the conversation.
    LaunchedEffect(open) {
        if (open) unread = 0
        while (true) {
            try { refresh(); if (open) unread = 0 } catch (e: Exception) { if (open) failed = e.message ?: "No connection" }
            delay(if (open) 3000L else 30000L)
        }
    }
    LaunchedEffect(msgs.size, open) { if (open && msgs.isNotEmpty()) listState.animateScrollToItem(msgs.size - 1) }

    if (!enabled) return

    fun send(t: String) {
        sending = true
        scope.launch {
            try {
                val j = Api.call("client_chat_send", mapOf("body" to t, "after" to (if (chatId == 0) 0 else (msgs.lastOrNull()?.optInt("id") ?: 0)).toString()))
                val id = j.optInt("chat_id")
                val incoming = j.optJSONArray("messages").list()
                if (id != chatId) { chatId = id; dismissed = 0; msgs = incoming; status = "open"; rated = false } else msgs = msgs + incoming.filter { n -> msgs.none { it.optInt("id") == n.optInt("id") } }
            } catch (e: Exception) { toast(ctx, e.message) }
            sending = false
        }
    }

    if (!open) {
        Box(Modifier.align(Alignment.BottomEnd).padding(16.dp)) {
            ExtendedFloatingActionButton(onClick = { open = true }) { Text("Chat with us") }
            if (unread > 0) Badge(Modifier.align(Alignment.TopEnd)) { Text(unread.toString()) }
        }
        return
    }

    Card(
        Modifier.align(Alignment.BottomEnd).padding(12.dp).widthIn(max = 380.dp).fillMaxWidth().fillMaxHeight(0.78f).imePadding(),
        elevation = CardDefaults.cardElevation(defaultElevation = 12.dp)
    ) {
        Column(Modifier.fillMaxSize()) {
            Row(Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.primary).padding(start = 14.dp, end = 4.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Customer care", color = MaterialTheme.colorScheme.onPrimary, fontWeight = FontWeight.Bold)
                    val sub = if (agent.isNotBlank()) "You are talking to $agent" else if (hours.isNotBlank()) "Hours: $hours" else "We usually reply within minutes"
                    Text(sub, color = MaterialTheme.colorScheme.onPrimary, style = MaterialTheme.typography.labelSmall)
                }
                if (status == "open") TextButton(onClick = { scope.launch { try { Api.call("client_chat_close"); refresh() } catch (e: Exception) { toast(ctx, e.message) } } }) {
                    Text("End", color = MaterialTheme.colorScheme.onPrimary)
                }
                TextButton(onClick = { open = false }) { Text("Hide", color = MaterialTheme.colorScheme.onPrimary) }
            }
            if (failed.isNotBlank()) Text(failed, Modifier.padding(8.dp), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelSmall)
            LazyColumn(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 10.dp), state = listState, verticalArrangement = Arrangement.spacedBy(6.dp), contentPadding = PaddingValues(vertical = 8.dp)) {
                if (msgs.isEmpty()) item { Text(welcome, Modifier.fillMaxWidth().padding(vertical = 12.dp), textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                items(msgs) { ChatBubbleRow(it, "client") }
            }
            if (status == "closed") {
                Column(Modifier.fillMaxWidth().padding(10.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (!rated) {
                        Text("How was our service?", style = MaterialTheme.typography.labelMedium)
                        Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            (1..5).forEach { n ->
                                OutlinedButton(onClick = { scope.launch { try { Api.call("client_chat_close", mapOf("rating" to n.toString())); rated = true } catch (e: Exception) { toast(ctx, e.message) } } },
                                    contentPadding = PaddingValues(0.dp), modifier = Modifier.size(width = 44.dp, height = 36.dp)) { Text(n.toString()) }
                            }
                        }
                    } else Text("Thank you for your feedback.", style = MaterialTheme.typography.labelMedium)
                    Button(onClick = { dismissed = chatId; chatId = 0; msgs = emptyList(); status = ""; rated = false; agent = "" }) { Text("Start a new chat") }
                }
            } else MessageBox(sending, emptyList()) { send(it) }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Staff side: inbox of conversations                                   */
/* ------------------------------------------------------------------ */

@Composable
fun ChatInboxScreen(reload: Int) {
    val ctx = LocalContext.current
    var filter by rememberSaveable { mutableStateOf("open") }
    var bump by remember { mutableIntStateOf(0) }
    var openId by remember { mutableIntStateOf(0) }
    var showSettings by remember { mutableStateOf(false) }
    var canEdit by remember { mutableStateOf(false) }
    var cfg by remember { mutableStateOf<JSONObject?>(null) }
    var tick by remember { mutableIntStateOf(0) }
    // refresh the list every few seconds while this tab is showing
    LaunchedEffect(Unit) { while (true) { delay(8000); tick++ } }
    Column(Modifier.fillMaxSize()) {
        Remote(listOf(reload, bump, filter, tick), Modifier.fillMaxSize(), load = { Api.call("chat_list", mapOf("filter" to filter)) }) { j ->
            val counts = j.optJSONObject("counts")
            canEdit = j.optBoolean("can_edit_settings"); cfg = j.optJSONObject("settings")
            Column(Modifier.fillMaxSize()) {
                Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    listOf("open" to "Open ${counts?.optInt("open") ?: 0}", "mine" to "Mine ${counts?.optInt("mine") ?: 0}", "unassigned" to "New ${counts?.optInt("unassigned") ?: 0}", "closed" to "Closed")
                        .forEach { (k, l) -> FilterChip(selected = filter == k, onClick = { filter = k }, label = { Text(l) }) }
                    Spacer(Modifier.weight(1f))
                    if (canEdit) TextButton(onClick = { showSettings = true }) { Text("Settings") }
                }
                val chats = j.optJSONArray("chats").list()
                if (chats.isEmpty()) Text("No conversations here.", Modifier.padding(24.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
                LazyColumn(Modifier.fillMaxSize().padding(horizontal = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp), contentPadding = PaddingValues(bottom = 16.dp)) {
                    items(chats) { c ->
                        Card(Modifier.fillMaxWidth().clickable { openId = c.optInt("id") }) {
                            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                                    Text(c.optString("name"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                    if (c.optInt("unread") > 0) Badge { Text(c.optInt("unread").toString()) }
                                }
                                Text(c.optString("preview"), maxLines = 2, style = MaterialTheme.typography.bodyMedium)
                                val who = if (c.optInt("assigned_to") == 0) "Unassigned" else if (c.optBoolean("mine")) "You" else c.optString("agent")
                                Text(who + " · " + (if (c.optString("source") == "web") "website" else "app") + " · " + c.optString("at").take(16) +
                                    (if (c.isNull("rating")) "" else " · rated " + c.optInt("rating") + "/5"), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }
    }
    if (openId != 0) ChatThreadDialog(openId, cfg?.optString("canned").orEmpty().lines().map { it.trim() }.filter { it.isNotEmpty() }) { openId = 0; bump++ }
    if (showSettings && cfg != null) ChatSettingsDialog(cfg!!, onClose = { showSettings = false }, onSaved = { showSettings = false; bump++ })
}

@Composable
private fun ChatThreadDialog(id: Int, canned: List<String>, onClose: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var chat by remember { mutableStateOf<JSONObject?>(null) }
    var msgs by remember { mutableStateOf(listOf<JSONObject>()) }
    var sending by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf("") }
    val listState = rememberLazyListState()

    suspend fun refresh() {
        val j = Api.call("chat_thread", mapOf("id" to id.toString(), "after" to (msgs.lastOrNull()?.optInt("id") ?: 0).toString()))
        chat = j.optJSONObject("chat")
        val incoming = j.optJSONArray("messages").list()
        msgs = msgs + incoming.filter { n -> msgs.none { it.optInt("id") == n.optInt("id") } }
        err = ""
    }
    LaunchedEffect(id) { while (true) { try { refresh() } catch (e: Exception) { err = e.message ?: "No connection" }; delay(3000) } }
    LaunchedEffect(msgs.size) { if (msgs.isNotEmpty()) listState.animateScrollToItem(msgs.size - 1) }

    fun act(action: String, p: Map<String, String> = emptyMap()) {
        scope.launch { try { Api.call(action, mapOf("id" to id.toString()) + p); refresh() } catch (e: Exception) { toast(ctx, e.message) } }
    }

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().imePadding()) {
                val c = chat
                Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 8.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(c?.optString("name") ?: "Chat", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (c != null) Text(listOf(c.optString("username"), c.optString("phone")).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.labelSmall)
                    }
                    TextButton(onClick = onClose) { Text("Back") }
                }
                if (c != null) Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (c.optString("status") == "open") {
                        if (c.optBoolean("mine")) OutlinedButton(onClick = { act("chat_assign", mapOf("to" to "none")) }) { Text("Release") }
                        else OutlinedButton(onClick = { act("chat_assign", mapOf("to" to "me")) }) { Text("Take it") }
                        OutlinedButton(onClick = { act("chat_close") }) { Text("Close chat") }
                    } else Text("Closed" + (if (c.isNull("rating")) "" else " · rated " + c.optInt("rating") + "/5"), style = MaterialTheme.typography.labelMedium)
                }
                if (err.isNotBlank()) Text(err, Modifier.padding(8.dp), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelSmall)
                LazyColumn(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 12.dp), state = listState, verticalArrangement = Arrangement.spacedBy(6.dp), contentPadding = PaddingValues(vertical = 8.dp)) {
                    items(msgs) { ChatBubbleRow(it, "staff") }
                }
                if (c?.optString("status") == "open") MessageBox(sending, canned) { t ->
                    sending = true
                    scope.launch {
                        try {
                            val j = Api.call("chat_send", mapOf("id" to id.toString(), "body" to t, "after" to (msgs.lastOrNull()?.optInt("id") ?: 0).toString()))
                            msgs = msgs + j.optJSONArray("messages").list().filter { n -> msgs.none { it.optInt("id") == n.optInt("id") } }
                        } catch (e: Exception) { toast(ctx, e.message) }
                        sending = false
                    }
                }
            }
        }
    }
}

@Composable
private fun ChatSettingsDialog(cfg: JSONObject, onClose: () -> Unit, onSaved: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var on by remember { mutableStateOf(cfg.optBoolean("on", true)) }
    var welcome by remember { mutableStateOf(cfg.optString("welcome")) }
    var hours by remember { mutableStateOf(cfg.optString("hours")) }
    var offline by remember { mutableStateOf(cfg.optString("offline")) }
    var canned by remember { mutableStateOf(cfg.optString("canned")) }
    var closeH by remember { mutableStateOf(cfg.optInt("close_hours", 24).toString()) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Live chat settings") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Chat switched on", Modifier.weight(1f)); Switch(on, { on = it }) }
                OutlinedTextField(welcome, { welcome = it }, label = { Text("Welcome message") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(hours, { hours = it }, label = { Text("Support hours (shown to customers)") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(offline, { offline = it }, label = { Text("Message when nobody is around") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(canned, { canned = it }, label = { Text("Quick replies (one per line)") }, modifier = Modifier.fillMaxWidth(), minLines = 3)
                OutlinedTextField(closeH, { closeH = it.filter(Char::isDigit).take(3) }, label = { Text("Close idle chats after (hours)") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        Api.call("chat_settings_save", mapOf("on" to (if (on) "1" else "0"), "welcome" to welcome, "hours" to hours, "offline" to offline, "canned" to canned, "close_hours" to closeH.ifBlank { "24" }))
                        toast(ctx, "Saved"); onSaved()
                    } catch (e: Exception) { toast(ctx, e.message) }
                    busy = false
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
