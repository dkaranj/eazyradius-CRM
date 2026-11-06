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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

@Composable
fun ClientHome(reload: Int) {
    var homeBump by remember { mutableIntStateOf(0) }
    var showStk by remember { mutableStateOf(false) }
    var showWifi by remember { mutableStateOf(false) }
    var phoneNo by remember { mutableStateOf("") }
    Remote(listOf(reload, homeBump), load = { Api.call("client_home") }) { j ->
        val c = j.getJSONObject("client")
        val plan = c.optJSONObject("plan")
        phoneNo = c.optString("phone")
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("Hello, " + c.optString("name"), style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            NoticeBanner(reload)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { showStk = true }, modifier = Modifier.weight(1f)) { Text("Top up (M-Pesa)") }
                OutlinedButton(onClick = { showWifi = true }, modifier = Modifier.weight(1f)) { Text("My Wi-Fi") }
            }
            StatCard("Wallet balance", c.optString("balance"), "info", Modifier.fillMaxWidth())
            if (plan != null) {
                val active = plan.optBoolean("active")
                StatCard(
                    if (active) "Active plan" else "Plan expired",
                    plan.optString("name"),
                    if (active) "good" else "bad",
                    Modifier.fillMaxWidth()
                )
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        KeyValue("Type", plan.optString("type"))
                        KeyValue(if (active) "Expires" else "Expired", plan.optString("expires"))
                        KeyValue("Username", c.optString("username"))
                        KeyValue("Phone", c.optString("phone"))
                    }
                }
            } else {
                Card(Modifier.fillMaxWidth()) {
                    Text("You have no plan yet. Open the Plans tab to buy one.", Modifier.padding(16.dp))
                }
            }
        }
    }
    if (showStk) StkDialog(phoneNo) { showStk = false; homeBump++ }
    if (showWifi) WifiDialog { showWifi = false }
}

@Composable
fun ClientPlans(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var pending by remember { mutableStateOf<JSONObject?>(null) }
    val scope = rememberCoroutineScope()
    val ctx = LocalContext.current

    Remote(listOf(reload, bump), load = { Api.call("client_plans") }) { j ->
        val plans = j.optJSONArray("plans").list()
        LazyVerticalGrid(
            GridCells.Adaptive(160.dp),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            items(plans) { p ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(p.optString("name"), fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(4.dp))
                        Text(p.optString("price"), style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                        Text(p.optString("validity"), style = MaterialTheme.typography.bodySmall)
                        Spacer(Modifier.height(8.dp))
                        Button(onClick = { pending = p }, modifier = Modifier.fillMaxWidth()) { Text("Buy") }
                    }
                }
            }
        }
    }

    pending?.let { p ->
        AlertDialog(
            onDismissRequest = { pending = null },
            title = { Text("Buy " + p.optString("name") + "?") },
            text = { Text(p.optString("price") + " will be taken from your wallet balance.") },
            confirmButton = {
                TextButton(onClick = {
                    pending = null
                    scope.launch {
                        try {
                            val r = Api.call("client_buy", mapOf("plan_id" to p.optInt("id").toString()))
                            toast(ctx, r.optString("message", "Done"))
                            bump++
                        } catch (e: Exception) {
                            toast(ctx, e.message)
                        }
                    }
                }) { Text("Confirm") }
            },
            dismissButton = { TextButton(onClick = { pending = null }) { Text("Cancel") } }
        )
    }
}

@Composable
fun ClientHistory(reload: Int) {
    Remote(reload, load = { Api.call("client_history") }) { j ->
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
