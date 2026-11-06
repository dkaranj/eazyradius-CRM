package com.duntech.nuxbill

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

/** Publish outage / maintenance notices that every customer sees on their home screen. */
@Composable
fun NoticesScreen(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    Column(Modifier.fillMaxSize()) {
        Button(onClick = { creating = true }, modifier = Modifier.fillMaxWidth().padding(12.dp)) { Text("New notice to customers") }
        Remote(listOf(reload, bump), load = { Api.call("notice_list") }) { j ->
            val list = j.optJSONArray("notices").list()
            if (list.isEmpty()) Text("No notices yet.", Modifier.padding(16.dp))
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(list) { n ->
                    val live = n.optBoolean("live")
                    val c = when (n.optString("severity")) { "outage" -> Color(0xFFDC2626); "warning" -> Color(0xFFD97706); else -> Color(0xFF2563EB) }
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(n.optString("title"), fontWeight = FontWeight.Bold, color = c, modifier = Modifier.weight(1f))
                                Text(if (live) "LIVE" else "ended", style = MaterialTheme.typography.labelMedium)
                            }
                            if (n.optString("body").isNotBlank()) Text(n.optString("body"))
                            Text(n.optString("created") + " · " + n.optString("by") + (if (n.optString("ends_at").isNotBlank() && n.optString("ends_at") != "null") " · until " + n.optString("ends_at") else ""),
                                style = MaterialTheme.typography.bodySmall)
                            if (live) TextButton(onClick = {
                                scope.launch {
                                    try { Api.call("notice_end", mapOf("id" to n.optInt("id").toString())); bump++ } catch (e: Exception) { toast(ctx, e.message) }
                                }
                            }) { Text("End notice") }
                        }
                    }
                }
            }
        }
    }
    if (creating) {
        var title by remember { mutableStateOf("") }
        var body by remember { mutableStateOf("") }
        var sev by remember { mutableStateOf("outage") }
        var hours by remember { mutableStateOf("4") }
        var busy by remember { mutableStateOf(false) }
        AlertDialog(
            onDismissRequest = { creating = false },
            title = { Text("Notice to customers") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(title, { title = it }, label = { Text("Title") }, placeholder = { Text("Fibre cut in Kasarani") })
                    OutlinedTextField(body, { body = it }, label = { Text("Details (optional)") }, minLines = 2)
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf("outage", "warning", "info").forEach { s ->
                            FilterChip(selected = sev == s, onClick = { sev = s }, label = { Text(s) })
                        }
                    }
                    OutlinedTextField(hours, { hours = it.filter { c -> c.isDigit() } }, label = { Text("Hide after (hours, 0 = until I end it)") },
                        singleLine = true, keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = KeyboardType.Number))
                }
            },
            confirmButton = {
                Button(enabled = !busy && title.isNotBlank(), onClick = {
                    busy = true
                    scope.launch {
                        try {
                            val r = Api.call("notice_save", mapOf("title" to title, "body" to body, "severity" to sev, "hours" to hours.ifBlank { "0" }))
                            toast(ctx, r.optString("message"))
                            creating = false
                            bump++
                        } catch (e: Exception) { toast(ctx, e.message); busy = false }
                    }
                }) { Text("Publish") }
            },
            dismissButton = { TextButton(onClick = { creating = false }) { Text("Cancel") } }
        )
    }
}
