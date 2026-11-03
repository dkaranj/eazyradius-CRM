package com.duntech.nuxbill

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
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
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private val ENTITIES = listOf("lead" to "Leads", "ticket" to "Support", "install" to "Installs")

/* ---------------- dashboards ---------------- */

@Composable
fun OverviewGrid(j: JSONObject, payments: List<JSONObject>) {
    val cards = j.optJSONArray("cards").list()
    val work = j.optJSONObject("work")
    LazyVerticalGrid(
        GridCells.Adaptive(160.dp),
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        items(cards) { c -> StatCard(c.optString("label"), c.optString("value"), c.optString("tone")) }
        listOf("lead", "ticket", "install").forEach { e ->
            val w = work?.optJSONObject(e)
            val label = w?.optString("label") ?: ""
            val rows = w?.optJSONArray("items").list()
            if (rows.isNotEmpty()) {
                item(span = { GridItemSpan(maxLineSpan) }) { SectionTitle(label) }
                items(rows, span = { GridItemSpan(maxLineSpan) }) { r -> MiniRow(r) }
            }
        }
        if (payments.isNotEmpty()) {
            item(span = { GridItemSpan(maxLineSpan) }) { SectionTitle("Recent payments") }
            items(payments, span = { GridItemSpan(maxLineSpan) }) { t -> TxRow(t) }
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 8.dp))
}

@Composable
private fun MiniRow(r: JSONObject) {
    Card(Modifier.fillMaxWidth()) {
        Row(
            Modifier.padding(12.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(r.optString("title"), fontWeight = FontWeight.SemiBold)
                val sub = listOf(r.optString("subtitle"), r.optString("address")).filter { it.isNotBlank() }.joinToString(" · ")
                if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.bodySmall, maxLines = 1)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(r.optString("status"), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
                val who = r.optString("assignee")
                Text(
                    if (who.isBlank()) "Unassigned" else who,
                    color = if (who.isBlank()) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }
    }
}

@Composable
fun TechHome(reload: Int) {
    Remote(reload, load = { Api.call("tech_dashboard") }) { j -> OverviewGrid(j, emptyList()) }
}

/* ---------------- Work tab ---------------- */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OpsWork(reload: Int) {
    var entity by rememberSaveable { mutableStateOf("lead") }
    Column(Modifier.fillMaxSize()) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            ENTITIES.forEach { (k, label) ->
                FilterChip(selected = entity == k, onClick = { entity = k }, label = { Text(label) })
            }
        }
        Box(Modifier.weight(1f)) { OpsList(entity, reload) }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OpsList(entity: String, reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var statusFilter by rememberSaveable(entity) { mutableStateOf("") }
    var techFilter by rememberSaveable(entity) { mutableStateOf("") }
    var creating by remember(entity) { mutableStateOf(false) }

    Remote(listOf(entity, reload, bump), load = { Api.call("ops_list", mapOf("entity" to entity)) }) { j ->
        val all = j.optJSONArray("items").list()
        val statuses = j.optJSONArray("statuses").strings()
        val techs = j.optJSONArray("techs").list()
        val canCreate = j.optBoolean("can_create")
        val canAssign = j.optBoolean("can_assign")
        val chips = all.map { it.optString("status") }.filter { it.isNotBlank() }.distinct()

        val shown = all.filter { it ->
            val okStatus = statusFilter.isEmpty() || it.optString("status") == statusFilter
            val okTech = when (techFilter) {
                "" -> true
                "none" -> it.optInt("assignee_id") == 0 && it.optString("assignee").isBlank()
                else -> it.optString("assignee_id") == techFilter
            }
            okStatus && okTech
        }

        Column(Modifier.fillMaxSize()) {
            val note = j.optString("note")
            if (note.isNotBlank()) {
                Text(note, Modifier.padding(horizontal = 12.dp, vertical = 4.dp), color = MaterialTheme.colorScheme.error)
            }
            if (canCreate) {
                Button(
                    onClick = { creating = true },
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp)
                ) { Text(if (entity == "lead") "Add lead" else "New ticket") }
            }
            LazyRow(
                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 6.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                item { FilterChip(selected = statusFilter.isEmpty(), onClick = { statusFilter = "" }, label = { Text("All (${all.size})") }) }
                items(chips) { s -> FilterChip(selected = statusFilter == s, onClick = { statusFilter = s }, label = { Text(s) }) }
            }
            if (canAssign && techs.isNotEmpty()) {
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    item { FilterChip(selected = techFilter.isEmpty(), onClick = { techFilter = "" }, label = { Text("Everyone") }) }
                    item { FilterChip(selected = techFilter == "none", onClick = { techFilter = "none" }, label = { Text("Unassigned") }) }
                    items(techs) { t ->
                        val id = t.optInt("id").toString()
                        FilterChip(selected = techFilter == id, onClick = { techFilter = id }, label = { Text(t.optString("name")) })
                    }
                }
            }
            Box(Modifier.weight(1f)) {
                if (shown.isEmpty()) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("Nothing here") }
                } else {
                    LazyVerticalGrid(
                        GridCells.Adaptive(300.dp),
                        Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(12.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        items(shown) { item -> OpsCard(entity, item, statuses, techs, canAssign) { bump++ } }
                    }
                }
            }
        }

        if (creating) {
            CreateDialog(entity, if (canAssign) techs else emptyList(), onClose = { creating = false }) {
                creating = false
                bump++
            }
        }
    }
}

@Composable
private fun OpsCard(
    entity: String,
    item: JSONObject,
    statuses: List<String>,
    techs: List<JSONObject>,
    canAssign: Boolean,
    onChanged: () -> Unit
) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var assigning by remember { mutableStateOf(false) }
    val id = item.optInt("id").toString()
    val phone = item.optString("phone")
    val address = item.optString("address")
    val current = item.optString("status")
    val who = item.optString("assignee")

    fun update(params: Map<String, String>) {
        scope.launch {
            try {
                Api.call("ops_update", mapOf("entity" to entity, "id" to id) + params)
                onChanged()
            } catch (e: Exception) {
                toast(ctx, e.message)
            }
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(item.optString("title"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Text(current, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
            }
            KeyValue("Details", item.optString("subtitle"))
            KeyValue("Notes", item.optString("notes"))
            KeyValue("Priority", item.optString("priority"))
            KeyValue("Phone", phone)
            KeyValue("Address", address)
            KeyValue("Created", item.optString("created"))
            KeyValue("Assigned to", if (who.isBlank()) "Unassigned" else who)
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (phone.isNotBlank()) {
                    OutlinedButton(onClick = {
                        ctx.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + phone)))
                    }) { Text("Call") }
                }
                if (address.isNotBlank()) {
                    OutlinedButton(onClick = {
                        val i = Intent(Intent.ACTION_VIEW, Uri.parse("geo:0,0?q=" + Uri.encode(address)))
                        try { ctx.startActivity(i) } catch (e: Exception) { toast(ctx, "No maps app found") }
                    }) { Text("Navigate") }
                }
                if (canAssign) {
                    OutlinedButton(onClick = { assigning = true }) { Text("Assign") }
                }
            }
            val options = statuses.filter { it != current }
            if (options.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(options) { s ->
                        TextButton(onClick = { update(mapOf("status" to s)) }) { Text("→ " + s.replace('_', ' ')) }
                    }
                }
            }
        }
    }

    if (assigning) {
        AlertDialog(
            onDismissRequest = { assigning = false },
            title = { Text("Assign to") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (techs.isEmpty()) Text("No technicians yet. Add users with the Technician role in the Users tab.")
                    techs.forEach { t ->
                        OutlinedButton(
                            onClick = {
                                assigning = false
                                update(mapOf("assignee_id" to t.optInt("id").toString()))
                            },
                            modifier = Modifier.fillMaxWidth().padding(vertical = 3.dp)
                        ) { Text(t.optString("name")) }
                    }
                    if (who.isNotBlank()) {
                        TextButton(onClick = {
                            assigning = false
                            update(mapOf("unassign" to "1"))
                        }) { Text("Unassign", color = MaterialTheme.colorScheme.error) }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { assigning = false }) { Text("Close") } }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CreateDialog(entity: String, techs: List<JSONObject>, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var priority by remember { mutableStateOf("normal") }
    var assignee by remember { mutableStateOf(0) }
    var busy by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(if (entity == "lead") "Add lead" else "New support ticket") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    title, { title = it }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    label = { Text(if (entity == "lead") "Lead name" else "Subject") }
                )
                OutlinedTextField(
                    phone, { phone = it }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                    label = { Text("Phone") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone)
                )
                OutlinedTextField(address, { address = it }, singleLine = true, modifier = Modifier.fillMaxWidth(), label = { Text("Address / location") })
                OutlinedTextField(notes, { notes = it }, modifier = Modifier.fillMaxWidth(), label = { Text(if (entity == "lead") "Notes" else "Describe the issue") })
                if (entity == "ticket") {
                    Text("Priority", style = MaterialTheme.typography.labelLarge)
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("low", "normal", "high").forEach { p ->
                            FilterChip(selected = priority == p, onClick = { priority = p }, label = { Text(p) })
                        }
                    }
                }
                if (techs.isNotEmpty()) {
                    Text("Assign to", style = MaterialTheme.typography.labelLarge)
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        item { FilterChip(selected = assignee == 0, onClick = { assignee = 0 }, label = { Text("Nobody") }) }
                        items(techs) { t ->
                            val id = t.optInt("id")
                            FilterChip(selected = assignee == id, onClick = { assignee = id }, label = { Text(t.optString("name")) })
                        }
                    }
                }
            }
        },
        confirmButton = {
            Button(
                enabled = !busy,
                onClick = {
                    busy = true
                    scope.launch {
                        try {
                            val r = Api.call(
                                "ops_create",
                                mapOf(
                                    "entity" to entity, "title" to title.trim(), "phone" to phone.trim(),
                                    "address" to address.trim(), "notes" to notes.trim(), "priority" to priority,
                                    "assignee_id" to assignee.toString()
                                )
                            )
                            toast(ctx, r.optString("message", "Saved"))
                            onDone()
                        } catch (e: Exception) {
                            toast(ctx, e.message)
                        } finally {
                            busy = false
                        }
                    }
                }
            ) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
