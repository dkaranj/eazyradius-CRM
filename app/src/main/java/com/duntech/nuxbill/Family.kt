package com.duntech.nuxbill

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.launch
import org.json.JSONObject

/** Linked accounts: see and pay for another connection (a shop, a parent's home) from this one. */
@Composable
fun FamilyDialog(onClose: () -> Unit, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var username by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var member by remember { mutableStateOf<JSONObject?>(null) }
    var topUp by remember { mutableStateOf<JSONObject?>(null) }
    var me by remember { mutableStateOf("") }

    fun run(action: String, p: Map<String, String>, after: () -> Unit = {}) {
        busy = true
        scope.launch {
            try { toast(ctx, Api.call(action, p).optString("message")); bump++; onChanged(); after() } catch (e: Exception) { toast(ctx, e.message) }
            busy = false
        }
    }

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 8.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Linked accounts", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    TextButton(onClick = onClose) { Text("Close") }
                }
                Remote(bump, load = { Api.call("client_family") }) { j ->
                    val members = j.optJSONArray("members").list()
                    val managedBy = j.optJSONArray("managed_by").list()
                    val requests = j.optJSONArray("requests").list()
                    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        requests.forEach { r ->
                            Card(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Text(r.optString("name") + " wants to see and pay for your internet", fontWeight = FontWeight.Bold)
                                    Text("If you approve, they can renew your plan from their wallet and see your plan and expiry date. You can remove them any time.", style = MaterialTheme.typography.bodySmall)
                                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        Button(enabled = !busy, onClick = { run("client_family_respond", mapOf("id" to r.optInt("link_id").toString(), "accept" to "1")) }) { Text("Approve") }
                                        OutlinedButton(enabled = !busy, onClick = { run("client_family_respond", mapOf("id" to r.optInt("link_id").toString(), "accept" to "0")) }) { Text("Decline") }
                                    }
                                }
                            }
                        }
                        Text("Accounts I look after", style = MaterialTheme.typography.titleSmall)
                        if (members.isEmpty()) Text("None yet. Add one below.", style = MaterialTheme.typography.bodySmall)
                        members.forEach { m ->
                            Card(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text(m.optString("name") + " (" + m.optString("username") + ")", fontWeight = FontWeight.Bold)
                                    if (m.optString("status") == "pending") Text("Waiting for them to approve", style = MaterialTheme.typography.bodySmall)
                                    else {
                                        Text((if (m.optString("plan").isBlank()) "No plan" else m.optString("plan")) + (if (m.optBoolean("paused")) " · paused" else if (m.optBoolean("active")) " · active" else " · expired") +
                                            (if (m.optString("expires").isNotBlank()) " · " + m.optString("expires").take(16) else ""), style = MaterialTheme.typography.bodyMedium)
                                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                            Button(onClick = { member = m }) { Text("Renew") }
                                            OutlinedButton(onClick = { topUp = m }) { Text("Top up (M-Pesa)") }
                                        }
                                    }
                                    TextButton(enabled = !busy, onClick = { run("client_family_remove", mapOf("id" to m.optInt("link_id").toString())) }) { Text("Remove") }
                                }
                            }
                        }
                        if (managedBy.isNotEmpty()) {
                            Text("Accounts that look after mine", style = MaterialTheme.typography.titleSmall)
                            managedBy.forEach { m ->
                                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                    Text(m.optString("name"), Modifier.weight(1f))
                                    TextButton(enabled = !busy, onClick = { run("client_family_remove", mapOf("id" to m.optInt("link_id").toString())) }) { Text("Remove") }
                                }
                            }
                        }
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text("Add an account", fontWeight = FontWeight.Bold)
                                Text("Enter their username and the phone number on their account. They approve in their own app.", style = MaterialTheme.typography.bodySmall)
                                OutlinedTextField(username, { username = it.trim() }, label = { Text("Their username") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                                OutlinedTextField(phone, { phone = it }, label = { Text("Their phone number") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                                Button(enabled = !busy && username.isNotBlank() && phone.isNotBlank(), onClick = {
                                    run("client_family_add", mapOf("username" to username, "phone" to phone)) { username = ""; phone = "" }
                                }) { Text("Send request") }
                            }
                        }
                    }
                }
            }
        }
    }
    member?.let { m -> MemberRenewDialog(m.optInt("customer_id"), onClose = { member = null }, onDone = { member = null; bump++; onChanged() }) }
    topUp?.let { m -> StkDialog("", onClose = { topUp = null }, forId = m.optInt("customer_id")) }
}

@Composable
private fun MemberRenewDialog(memberId: Int, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var pick by remember { mutableStateOf<JSONObject?>(null) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text("Renew from my wallet") },
        text = {
            Remote(memberId, Modifier.fillMaxWidth().heightIn(min = 120.dp), load = { Api.call("client_family_member", mapOf("member_id" to memberId.toString())) }) { j ->
                val m = j.getJSONObject("member")
                val plans = j.optJSONArray("plans").list()
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(m.optString("name"), fontWeight = FontWeight.Bold)
                    Text((if (m.optString("plan").isBlank()) "No plan" else m.optString("plan")) + (if (m.optString("expires").isNotBlank()) " · ends " + m.optString("expires").take(16) else ""), style = MaterialTheme.typography.bodySmall)
                    Text("My wallet: " + j.optString("balance"), style = MaterialTheme.typography.bodyMedium)
                    plans.forEach { p ->
                        OutlinedButton(enabled = !busy, onClick = { pick = p }, modifier = Modifier.fillMaxWidth()) {
                            Text(p.optString("name") + " · " + p.optString("validity") + " · " + p.optString("price"), modifier = Modifier.fillMaxWidth())
                        }
                    }
                }
            }
        }
    )
    pick?.let { p ->
        AlertDialog(
            onDismissRequest = { pick = null },
            title = { Text("Pay " + p.optString("price") + "?") },
            text = { Text(p.optString("name") + " will be paid from your wallet and activated on the linked account.") },
            confirmButton = {
                Button(enabled = !busy, onClick = {
                    busy = true
                    scope.launch {
                        try { toast(ctx, Api.call("client_family_buy", mapOf("member_id" to memberId.toString(), "plan_id" to p.optInt("id").toString())).optString("message")); pick = null; onDone() }
                        catch (e: Exception) { toast(ctx, e.message); pick = null }
                        busy = false
                    }
                }) { Text("Pay") }
            },
            dismissButton = { TextButton(onClick = { pick = null }) { Text("Cancel") } }
        )
    }
}
