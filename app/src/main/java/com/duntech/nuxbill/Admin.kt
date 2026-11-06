package com.duntech.nuxbill

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

@Composable
fun AdminDashboard(reload: Int) {
    Remote(reload, load = { Api.call("admin_dashboard") }) { j ->
        OverviewGrid(j, j.optJSONArray("recent").list())
    }
}

@Composable
fun AdminCustomers(reload: Int) {
    var q by rememberSaveable { mutableStateOf("") }
    var applied by rememberSaveable { mutableStateOf("") }
    var selected by remember { mutableStateOf<Int?>(null) }
    var recharging by remember { mutableStateOf<Int?>(null) }

    Column(Modifier.fillMaxSize()) {
        OutlinedTextField(
            value = q,
            onValueChange = { q = it },
            modifier = Modifier.fillMaxWidth().padding(12.dp),
            singleLine = true,
            label = { Text("Search name, username or phone") },
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { applied = q })
        )
        Box(Modifier.weight(1f)) {
            Remote(listOf(applied, reload), load = { Api.call("admin_customers", mapOf("q" to applied)) }) { j ->
                val list = j.optJSONArray("customers").list()
                if (list.isEmpty()) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("No customers found") }
                } else {
                    LazyVerticalGrid(
                        GridCells.Adaptive(300.dp),
                        Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(12.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        items(list) { c -> CustomerCard(c) { selected = c.optInt("id") } }
                    }
                }
            }
        }
    }
    selected?.let { id ->
        Customer360Dialog(mapOf("id" to id.toString()), onClose = { selected = null }, onRecharge = { cid -> selected = null; recharging = cid })
    }
    recharging?.let { id -> CustomerDialog(id) { recharging = null } }
}

@Composable
private fun CustomerCard(c: JSONObject, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Column(Modifier.padding(14.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(
                    c.optString("fullname").ifBlank { c.optString("username") },
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f)
                )
                Text(
                    if (c.optBoolean("online")) "Active" else "Expired",
                    color = if (c.optBoolean("online")) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.labelMedium
                )
            }
            Text(c.optString("username") + "  " + c.optString("phone"), style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(6.dp))
            KeyValue("Plan", c.optString("plan"))
            KeyValue("Expires", c.optString("expires"))
            KeyValue("Balance", c.optString("balance"))
        }
    }
}

@Composable
private fun CustomerDialog(id: Int, onClose: () -> Unit) {
    var picking by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current

    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        dismissButton = {
            if (picking) TextButton(onClick = { picking = false }) { Text("Back") }
        },
        title = { Text(if (picking) "Choose a plan" else "Customer") },
        text = {
            Remote(id, Modifier.fillMaxWidth().heightIn(min = 120.dp), load = {
                Api.call("admin_customer", mapOf("id" to id.toString()))
            }) { j ->
                val c = j.getJSONObject("customer")
                val hist = j.optJSONArray("history").list()
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (!picking) {
                        Text(
                            c.optString("fullname").ifBlank { c.optString("username") },
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold
                        )
                        Spacer(Modifier.height(8.dp))
                        KeyValue("Username", c.optString("username"))
                        KeyValue("Phone", c.optString("phone"))
                        KeyValue("Email", c.optString("email"))
                        KeyValue("Address", c.optString("address"))
                        KeyValue("Service", c.optString("service"))
                        KeyValue("Balance", c.optString("balance"))
                        KeyValue("Plan", c.optString("plan"))
                        KeyValue("Expires", c.optString("expires"))
                        Spacer(Modifier.height(10.dp))
                        Button(onClick = { picking = true }, modifier = Modifier.fillMaxWidth()) {
                            Text("Recharge customer")
                        }
                        if (hist.isNotEmpty()) {
                            Spacer(Modifier.height(12.dp))
                            Text("Recent payments", style = MaterialTheme.typography.titleSmall)
                            hist.forEach {
                                Spacer(Modifier.height(6.dp))
                                TxRow(it)
                            }
                        }
                    } else {
                        Remote(Unit, Modifier.fillMaxWidth().heightIn(min = 80.dp), load = { Api.call("admin_plans") }) { pj ->
                            pj.optJSONArray("plans").list().forEach { p ->
                                OutlinedButton(
                                    onClick = {
                                        scope.launch {
                                            try {
                                                val r = Api.call(
                                                    "admin_recharge",
                                                    mapOf("customer_id" to id.toString(), "plan_id" to p.optInt("id").toString())
                                                )
                                                toast(ctx, r.optString("message", "Recharged"))
                                                onClose()
                                            } catch (e: Exception) {
                                                toast(ctx, e.message)
                                            }
                                        }
                                    },
                                    modifier = Modifier.fillMaxWidth().padding(vertical = 3.dp)
                                ) {
                                    Text(p.optString("name") + " · " + p.optString("price") + " · " + p.optString("validity"))
                                }
                            }
                        }
                    }
                }
            }
        }
    )
}

@Composable
fun AdminPayments(reload: Int) {
    Remote(reload, load = { Api.call("admin_transactions") }) { j ->
        val list = j.optJSONArray("transactions").list()
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(list) { t -> TxRow(t) }
        }
    }
}
