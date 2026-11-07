package com.duntech.nuxbill

import android.content.Intent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.launch
import org.json.JSONObject

private fun ledgerLine(r: JSONObject): String =
    (if (r.optInt("points") > 0) "+" else "") + r.optInt("points") + "  " + r.optString("note") + "  (" + r.optString("time").take(10) + ")"

private fun rulesText(rules: JSONObject): String =
    "Earn " + rules.optInt("rate") + " points for every KSh 100 you pay when you renew on time (up to " + rules.optInt("grace") +
        " day(s) after your plan ends).\n" +
        "Redeem from " + rules.optInt("min") + " points: 100 points = KSh " + rules.optInt("value") + " in your wallet.\n" +
        "Refer a friend: you get " + rules.optInt("ref_referrer") + " points when they make their first payment of KSh " + rules.optInt("ref_min") +
        " or more, and they get " + rules.optInt("ref_referee") + " points."

/** Customer side: points, redeem, my referral code, who I referred. */
@Composable
fun RewardsDialog(onClose: () -> Unit) {
    var bump by remember { mutableIntStateOf(0) }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf("") }
    var redeem by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 8.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    Text("Rewards and referrals", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    TextButton(onClick = onClose) { Text("Close") }
                }
                Remote(bump, load = { Api.call("client_loyalty") }) { j ->
                    val l = j.getJSONObject("loyalty")
                    val rules = l.getJSONObject("rules")
                    val refs = l.optJSONArray("referrals").list()
                    val ledger = l.optJSONArray("ledger").list()
                    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        if (!l.optBoolean("on")) Card(Modifier.fillMaxWidth()) { Text("Rewards are paused right now. Your points are safe.", Modifier.padding(14.dp)) }
                        StatCard("Your points", l.optInt("points").toString(), "good", Modifier.fillMaxWidth())
                        Text("Worth KSh " + l.optString("value") + " in your wallet", style = MaterialTheme.typography.bodyMedium)
                        if (!l.isNull("renew_by")) {
                            Text("Renew before " + l.optString("renew_by") + " to earn points.", style = MaterialTheme.typography.bodyMedium)
                        }
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text("Redeem points", fontWeight = FontWeight.Bold)
                                OutlinedTextField(redeem, { redeem = it.filter { c -> c.isDigit() } }, label = { Text("Points (minimum " + rules.optInt("min") + ")") },
                                    singleLine = true, modifier = Modifier.fillMaxWidth(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                                Button(enabled = !busy && redeem.isNotBlank(), onClick = {
                                    busy = true
                                    scope.launch {
                                        try {
                                            val r = Api.call("client_loyalty_redeem", mapOf("points" to redeem))
                                            toast(ctx, r.optString("message")); redeem = ""; bump++
                                        } catch (e: Exception) { toast(ctx, e.message) }
                                        busy = false
                                    }
                                }) { Text("Add to my wallet") }
                            }
                        }
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text("Refer a friend", fontWeight = FontWeight.Bold)
                                Text(l.optString("code"), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                                Button(onClick = {
                                    val send = Intent(Intent.ACTION_SEND).apply {
                                        type = "text/plain"
                                        putExtra(Intent.EXTRA_TEXT, "Join Eazyradius internet. Use my referral code " + l.optString("code") + " when you sign up and we both earn points.")
                                    }
                                    val chooser = Intent.createChooser(send, "Share your code").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                    ctx.startActivity(chooser)
                                }) { Text("Share my code") }
                                if (refs.isEmpty()) Text("Nobody has used your code yet.", style = MaterialTheme.typography.bodySmall)
                                refs.forEach { Text(it.optString("name") + " - " + it.optString("status"), style = MaterialTheme.typography.bodyMedium) }
                            }
                        }
                        if (!l.isNull("referred_by")) {
                            Text("You were referred by " + l.optString("referred_by") + ".", style = MaterialTheme.typography.bodyMedium)
                        } else if (l.optBoolean("can_apply")) {
                            Card(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                    Text("Did a friend refer you?", fontWeight = FontWeight.Bold)
                                    OutlinedTextField(code, { code = it.uppercase().take(12) }, label = { Text("Their referral code") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                                    Button(enabled = !busy && code.isNotBlank(), onClick = {
                                        busy = true
                                        scope.launch {
                                            try {
                                                val r = Api.call("client_loyalty_apply", mapOf("code" to code))
                                                toast(ctx, r.optString("message")); code = ""; bump++
                                            } catch (e: Exception) { toast(ctx, e.message) }
                                            busy = false
                                        }
                                    }) { Text("Apply code") }
                                }
                            }
                        }
                        Text("How it works", style = MaterialTheme.typography.titleSmall)
                        Text(rulesText(rules), style = MaterialTheme.typography.bodyMedium)
                        if (ledger.isNotEmpty()) {
                            Text("History", style = MaterialTheme.typography.titleSmall)
                            ledger.forEach { Text(ledgerLine(it), style = MaterialTheme.typography.bodySmall) }
                        }
                    }
                }
            }
        }
    }
}

/** Search a customer by name, username or phone and pick one. */
@Composable
fun CustomerPicker(onPick: (JSONObject) -> Unit) {
    var q by remember { mutableStateOf("") }
    var applied by remember { mutableStateOf("") }
    Column(Modifier.fillMaxWidth()) {
        OutlinedTextField(q, { q = it }, label = { Text("Search name, username or phone") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search), keyboardActions = KeyboardActions(onSearch = { applied = q.trim() }))
        if (applied.isNotBlank()) {
            Remote(applied, Modifier.fillMaxWidth().heightIn(min = 60.dp), load = { Api.call("admin_customers", mapOf("q" to applied)) }) { j ->
                val rows = j.optJSONArray("customers").list().take(8)
                if (rows.isEmpty()) Text("No customers found", Modifier.padding(8.dp))
                Column {
                    rows.forEach { c ->
                        TextButton(onClick = { onPick(c) }, modifier = Modifier.fillMaxWidth()) {
                            Text(c.optString("fullname").ifBlank { c.optString("username") } + "  " + c.optString("phone"), modifier = Modifier.fillMaxWidth())
                        }
                    }
                }
            }
        }
    }
}

/** Staff: totals, rules, top customers, and a customer lookup. */
@Composable
fun LoyaltyScreen(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var picked by remember { mutableStateOf<Int?>(null) }
    Remote(listOf(reload, bump), load = { Api.call("loyalty_overview") }) { j ->
        val t = j.getJSONObject("totals")
        val s = j.getJSONObject("settings")
        val top = j.optJSONArray("top").list()
        val recent = j.optJSONArray("recent").list()
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCard("Points out there", t.optInt("outstanding").toString(), "info", Modifier.weight(1f))
                StatCard("Worth (KSh)", t.optString("value_outstanding"), "warn", Modifier.weight(1f))
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCard("Points given", t.optInt("issued").toString(), "good", Modifier.weight(1f))
                StatCard("Referrals paid", t.optInt("referrals_rewarded").toString(), "good", Modifier.weight(1f))
            }
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("Rules " + if (s.optInt("on") == 1) "(running)" else "(PAUSED)", fontWeight = FontWeight.Bold)
                    Text(rulesText(JSONObject().put("rate", s.optInt("rate")).put("grace", s.optInt("grace")).put("min", s.optInt("min")).put("value", s.optInt("value"))
                        .put("ref_referrer", s.optInt("ref_referrer")).put("ref_referee", s.optInt("ref_referee")).put("ref_min", s.optInt("ref_min"))), style = MaterialTheme.typography.bodySmall)
                    OutlinedButton(onClick = { editing = s }) { Text("Change rules") }
                }
            }
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Find a customer", fontWeight = FontWeight.Bold)
                    CustomerPicker { picked = it.optInt("id") }
                }
            }
            if (top.isNotEmpty()) {
                Text("Top customers", style = MaterialTheme.typography.titleSmall)
                top.forEach { r ->
                    TextButton(onClick = { picked = r.optInt("id") }, modifier = Modifier.fillMaxWidth()) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text(r.optString("name")); Text(r.optInt("points").toString() + " pts") }
                    }
                }
            }
            if (recent.isNotEmpty()) {
                Text("Latest activity", style = MaterialTheme.typography.titleSmall)
                recent.forEach { Text(it.optString("name") + ": " + ledgerLine(it), style = MaterialTheme.typography.bodySmall) }
            }
        }
    }
    editing?.let { s -> RulesDialog(s, onClose = { editing = null }, onSaved = { editing = null; bump++ }) }
    picked?.let { id -> LoyaltyCustomerDialog(id) { picked = null; bump++ } }
}

@Composable
private fun RulesDialog(s: JSONObject, onClose: () -> Unit, onSaved: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val labels = listOf("on" to "Running (1 = yes, 0 = pause)", "rate" to "Points per KSh 100 paid", "grace" to "Days late still counts as on time",
        "value" to "KSh per 100 points when redeemed", "min" to "Minimum points to redeem", "ref_referrer" to "Referral reward for the referrer (points)",
        "ref_referee" to "Welcome bonus for the new customer (points)", "ref_min" to "First payment needed (KSh)")
    val vals = remember { mutableStateMapOf<String, String>().apply { labels.forEach { (k, _) -> put(k, s.optInt(k).toString()) } } }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Rewards rules") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                labels.forEach { (k, label) ->
                    OutlinedTextField(vals[k] ?: "", { vals[k] = it.filter { c -> c.isDigit() } }, label = { Text(label) }, singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        val r = Api.call("loyalty_settings_save", labels.associate { (k, _) -> k to (vals[k] ?: "").ifBlank { "0" } })
                        toast(ctx, r.optString("message")); onSaved()
                    } catch (e: Exception) { toast(ctx, e.message); busy = false }
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

/** Staff: one customer's points, who referred them, add or remove points. */
@Composable
fun LoyaltyCustomerDialog(id: Int, onClose: () -> Unit) {
    var bump by remember { mutableIntStateOf(0) }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var pts by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text("Customer rewards") },
        text = {
            Remote(bump, Modifier.fillMaxWidth().heightIn(min = 160.dp), load = { Api.call("loyalty_customer", mapOf("customer_id" to id.toString())) }) { j ->
                val c = j.getJSONObject("customer")
                val l = j.getJSONObject("loyalty")
                val refs = l.optJSONArray("referrals").list()
                val ledger = l.optJSONArray("ledger").list()
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(c.optString("name"), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    KeyValue("Points", l.optInt("points").toString())
                    KeyValue("Worth (KSh)", l.optString("value"))
                    KeyValue("Referral code", l.optString("code"))
                    KeyValue("Referred by", if (l.isNull("referred_by")) "Nobody" else l.optString("referred_by"))
                    KeyValue("Referred", refs.size.toString() + " customer(s)")
                    Text("Add or remove points", style = MaterialTheme.typography.titleSmall)
                    OutlinedTextField(pts, { pts = it.filter { ch -> ch.isDigit() || ch == '-' } }, label = { Text("Points (use - to remove)") }, singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(note, { note = it }, label = { Text("Reason") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    Button(enabled = pts.isNotBlank() && note.isNotBlank(), onClick = {
                        scope.launch {
                            try {
                                val r = Api.call("loyalty_adjust", mapOf("customer_id" to id.toString(), "points" to pts, "note" to note))
                                toast(ctx, r.optString("message")); pts = ""; note = ""; bump++
                            } catch (e: Exception) { toast(ctx, e.message) }
                        }
                    }) { Text("Apply") }
                    if (l.isNull("referred_by")) {
                        Text("Set who referred this customer", style = MaterialTheme.typography.titleSmall)
                        OutlinedTextField(code, { code = it.uppercase().take(12) }, label = { Text("Referrer's code") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                        OutlinedButton(enabled = code.isNotBlank(), onClick = {
                            scope.launch {
                                try {
                                    val r = Api.call("loyalty_referrer_set", mapOf("customer_id" to id.toString(), "code" to code))
                                    toast(ctx, r.optString("message")); code = ""; bump++
                                } catch (e: Exception) { toast(ctx, e.message) }
                            }
                        }) { Text("Save referrer") }
                    }
                    if (ledger.isNotEmpty()) {
                        Text("History", style = MaterialTheme.typography.titleSmall)
                        ledger.forEach { Text(ledgerLine(it), style = MaterialTheme.typography.bodySmall) }
                    }
                }
            }
        }
    )
}
