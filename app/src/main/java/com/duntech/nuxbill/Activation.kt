package com.duntech.nuxbill

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

/**
 * During a system outage: give one customer, or everyone whose plan ran out in the last few hours,
 * extra days at a speed you choose. Free of charge, always with a reason, always in the audit log.
 */
@Composable
fun ActivationScreen(reload: Int) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var mode by remember { mutableStateOf("expired") }
    var picked by remember { mutableStateOf<JSONObject?>(null) }
    var hours by remember { mutableStateOf("6") }
    var days by remember { mutableStateOf("2") }
    var planId by remember { mutableIntStateOf(0) }
    var reason by remember { mutableStateOf("") }
    var plans by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var menu by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var preview by remember { mutableStateOf<JSONObject?>(null) }

    LaunchedEffect(reload) {
        plans = try { Api.call("activation_plans").optJSONArray("plans").list() } catch (e: Exception) { emptyList() }
    }

    fun params(): Map<String, String> {
        val m = mutableMapOf("mode" to mode, "days" to days, "reason" to reason.trim())
        if (mode == "customer") m["customer_id"] = (picked?.optInt("id") ?: 0).toString() else m["hours"] = hours
        if (planId > 0) m["plan_id"] = planId.toString()
        return m
    }

    val chosen = plans.firstOrNull { it.optInt("id") == planId }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Card(Modifier.fillMaxWidth()) {
            Text("Use this when the system was down and customers lost service they paid for. Days are added free of charge, from now or from the end of their current plan, whichever is later.",
                Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = mode == "expired", onClick = { mode = "expired"; preview = null }, label = { Text("Everyone who expired recently") })
            FilterChip(selected = mode == "customer", onClick = { mode = "customer"; preview = null }, label = { Text("One customer") })
        }
        if (mode == "customer") {
            val p = picked
            if (p != null) {
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(12.dp).fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(Modifier.weight(1f)) {
                            Text(p.optString("fullname").ifBlank { p.optString("username") }, fontWeight = FontWeight.Bold)
                            Text((p.optString("plan").ifBlank { "No plan" }) + " · expires " + p.optString("expires"), style = MaterialTheme.typography.bodySmall)
                        }
                        TextButton(onClick = { picked = null }) { Text("Change") }
                    }
                }
            } else CustomerPicker { picked = it }
        } else {
            Text("Plan ended in the last (hours)", style = MaterialTheme.typography.titleSmall)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("3", "6", "12", "24", "48").forEach { h -> FilterChip(selected = hours == h, onClick = { hours = h }, label = { Text(h) }) }
            }
            OutlinedTextField(hours, { hours = it.filter { c -> c.isDigit() }.take(3) }, label = { Text("Hours (1 to 168)") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
        }
        Text("Days to add", style = MaterialTheme.typography.titleSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf("1", "2", "3", "5", "7").forEach { d -> FilterChip(selected = days == d, onClick = { days = d }, label = { Text(d) }) }
        }
        OutlinedTextField(days, { days = it.filter { c -> c.isDigit() }.take(2) }, label = { Text("Days (1 to 30)") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
        Text("Speed", style = MaterialTheme.typography.titleSmall)
        Box {
            OutlinedButton(onClick = { menu = true }, modifier = Modifier.fillMaxWidth()) {
                Text(if (chosen == null) "Keep each customer's own plan and speed" else chosen.optString("speed") + " · " + chosen.optString("name"))
            }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text("Keep each customer's own plan and speed") }, onClick = { planId = 0; menu = false; preview = null })
                plans.forEach { p ->
                    DropdownMenuItem(
                        text = { Text(p.optString("speed").ifBlank { "?" } + " · " + p.optString("name") + " (" + p.optString("type") + ")") },
                        onClick = { planId = p.optInt("id"); menu = false; preview = null }
                    )
                }
            }
        }
        OutlinedTextField(reason, { reason = it.take(200) }, label = { Text("Reason") }, placeholder = { Text("System outage on 7 Nov") }, modifier = Modifier.fillMaxWidth())
        Button(
            enabled = !busy && reason.trim().length >= 3 && days.isNotBlank() && (mode == "expired" || picked != null),
            modifier = Modifier.fillMaxWidth(),
            onClick = {
                busy = true
                scope.launch {
                    try { preview = Api.call("activation_preview", params()) } catch (e: Exception) { toast(ctx, e.message) }
                    busy = false
                }
            }
        ) { Text("Review before activating") }

        Text("Recent activations", style = MaterialTheme.typography.titleSmall)
        Remote(listOf(reload, bump), Modifier.fillMaxWidth().heightIn(min = 60.dp), load = { Api.call("activation_history") }) { j ->
            val rows = j.optJSONArray("history").list()
            if (rows.isEmpty()) Text("Nothing yet.", style = MaterialTheme.typography.bodySmall)
            rows.forEach { h ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp)) {
                        Text(h.optInt("count").toString() + " customer(s) · +" + h.optInt("days") + " day(s)" + (if (h.optString("who").isNotBlank()) " · " + h.optString("who") else ""), fontWeight = FontWeight.SemiBold)
                        Text(h.optString("reason"), style = MaterialTheme.typography.bodyMedium)
                        Text(h.optString("time") + " · " + h.optString("by") + " · " + h.optString("plan"), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }

    preview?.let { pv ->
        val count = pv.optInt("count")
        AlertDialog(
            onDismissRequest = { preview = null },
            title = { Text(if (count == 0) "Nobody to activate" else "Activate $count customer(s)?") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (pv.optBoolean("too_many")) Text("That is more than 500 customers. Choose a shorter period.", color = MaterialTheme.colorScheme.error)
                    else if (count == 0) Text("No customer matches. For a customer with no plan, choose a speed first.")
                    else {
                        Text("Each gets +" + days + " day(s) free" + (if (chosen != null) " on " + chosen.optString("speed") + " (" + chosen.optString("name") + ")" else " on their own plan") + ".")
                        pv.optJSONArray("sample").list().forEach { s -> Text(s.optString("name") + " · " + s.optString("plan") + " · ended " + s.optString("expires"), style = MaterialTheme.typography.bodySmall) }
                        if (count > 8) Text("and " + (count - 8) + " more", style = MaterialTheme.typography.bodySmall)
                    }
                }
            },
            confirmButton = {
                Button(enabled = !busy && count > 0 && !pv.optBoolean("too_many"), onClick = {
                    busy = true
                    scope.launch {
                        try {
                            val p = params().toMutableMap()
                            p["expect"] = count.toString()
                            val r = Api.call("activation_apply", p)
                            toast(ctx, r.optString("message"))
                            preview = null; reason = ""; bump++
                        } catch (e: Exception) { toast(ctx, e.message) }
                        busy = false
                    }
                }) { Text("Activate now") }
            },
            dismissButton = { TextButton(onClick = { preview = null }) { Text("Cancel") } }
        )
    }
}
