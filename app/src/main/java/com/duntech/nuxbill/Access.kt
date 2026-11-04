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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private fun kindLabel(kind: String): String = when (kind) {
    "role" -> "Staff role"
    "tech" -> "Technicians"
    else -> "User type"
}

/** SuperAdmin screen: decide which app sections each role can see. */
@Composable
fun AdminAccess(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<JSONObject?>(null) }

    Remote(listOf(reload, bump), load = { Api.call("access_get") }) { j ->
        val catalog = j.optJSONArray("catalog").list()
        val roles = j.optJSONArray("roles").list()
        val labels = HashMap<String, String>()
        catalog.forEach { labels[it.optString("key")] = it.optString("label") }

        LazyVerticalGrid(
            GridCells.Adaptive(300.dp),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            item(span = { GridItemSpan(maxLineSpan) }) {
                Text(
                    "Choose what each role can see in the app. A person with several roles gets everything any of them allows. " +
                        "User types (Sales, Agent, Report) only apply to people with no technician flag and no staff role. " +
                        "Each card shows who it applies to right now. The SuperAdmin always sees everything.",
                    style = MaterialTheme.typography.bodyMedium
                )
            }
            items(roles) { r ->
                Card(Modifier.fillMaxWidth().clickable { editing = r }) {
                    Column(Modifier.padding(14.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text(r.optString("label"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Text(
                                if (r.optBoolean("custom")) "Customised" else "Default",
                                style = MaterialTheme.typography.labelMedium,
                                color = if (r.optBoolean("custom")) MaterialTheme.colorScheme.primary
                                else MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        Text(kindLabel(r.optString("kind")), style = MaterialTheme.typography.bodySmall)
                        Spacer(Modifier.height(8.dp))
                        val mods = r.optJSONArray("modules").strings()
                        Text(
                            if (mods.isEmpty()) "Nothing" else mods.joinToString(", ") { labels[it] ?: it },
                            style = MaterialTheme.typography.bodyMedium
                        )
                        Spacer(Modifier.height(8.dp))
                        val who = r.optJSONArray("members").strings()
                        KeyValue("Applies to", if (who.isEmpty()) "Nobody right now" else who.joinToString(", "))
                        KeyValue("Sees", if (r.optString("scope") == "all") "All items" else "Only items assigned to them")
                        KeyValue("Can assign work", if (r.optBoolean("assign")) "Yes" else "No")
                        KeyValue("Can add leads and tickets", if (r.optBoolean("create")) "Yes" else "No")
                    }
                }
            }
        }

        editing?.let { r ->
            AccessDialog(r, catalog, onClose = { editing = null }) {
                editing = null
                bump++
            }
        }
    }
}

@Composable
private fun AccessDialog(role: JSONObject, catalog: List<JSONObject>, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var selected by remember { mutableStateOf(role.optJSONArray("modules").strings().toSet()) }
    var scopeAll by remember { mutableStateOf(role.optString("scope") == "all") }
    var canAssign by remember { mutableStateOf(role.optBoolean("assign")) }
    var canCreate by remember { mutableStateOf(role.optBoolean("create")) }
    var busy by remember { mutableStateOf(false) }
    val groups = catalog.groupBy { it.optString("group") }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(role.optString("label")) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                groups.forEach { (group, mods) ->
                    Text(
                        group,
                        style = MaterialTheme.typography.titleSmall,
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.padding(top = 8.dp)
                    )
                    mods.forEach { m ->
                        val key = m.optString("key")
                        Row(
                            Modifier.fillMaxWidth().clickable {
                                selected = if (key in selected) selected - key else selected + key
                            },
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Checkbox(
                                checked = key in selected,
                                onCheckedChange = { on -> selected = if (on) selected + key else selected - key }
                            )
                            Text(m.optString("label"), style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                }
                Spacer(Modifier.height(12.dp))
                Text("Options", style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.primary)
                SwitchRow("See all leads, tickets and installs", scopeAll) { scopeAll = it }
                SwitchRow("Can assign work to technicians", canAssign) { canAssign = it }
                SwitchRow("Can add leads and tickets", canCreate) { canCreate = it }
                if (role.optBoolean("custom")) {
                    TextButton(onClick = {
                        scope.launch {
                            try {
                                Api.call("access_reset", mapOf("role_key" to role.optString("key")))
                                toast(ctx, "Back to the default")
                                onDone()
                            } catch (e: Exception) {
                                toast(ctx, e.message)
                            }
                        }
                    }) { Text("Reset to default") }
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        val ordered = catalog.map { it.optString("key") }.filter { it in selected }
                        Api.call(
                            "access_save",
                            mapOf(
                                "role_key" to role.optString("key"),
                                "modules" to ordered.joinToString(","),
                                "scope" to if (scopeAll) "all" else "mine",
                                "assign" to if (canAssign) "1" else "0",
                                "create" to if (canCreate) "1" else "0"
                            )
                        )
                        toast(ctx, "Access saved")
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

@Composable
private fun SwitchRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onChange)
    }
}
