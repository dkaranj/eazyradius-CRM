package com.duntech.nuxbill

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.json.JSONObject

/**
 * Everything about one customer on one screen: contact buttons, plan, wallet, ONU health, usage and FUP,
 * their tickets and payments. Find the customer by id, username or phone.
 */
@Composable
fun Customer360Dialog(find: Map<String, String>, onClose: () -> Unit, onRecharge: ((Int) -> Unit)? = null) {
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text("Customer") },
        text = {
            Remote(find, Modifier.fillMaxWidth().heightIn(min = 160.dp), load = { Api.call("customer_360", find) }) { j ->
                val c = j.getJSONObject("customer")
                val onus = j.optJSONArray("onus").list()
                val tickets = j.optJSONArray("tickets").list()
                val payments = j.optJSONArray("payments").list()
                val usage = j.optJSONObject("usage")
                val name = c.optString("fullname").ifBlank { c.optString("username") }
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    ContactRow(
                        c.optString("phone"),
                        "Hello " + name.substringBefore(' ') + ", this is Eazyradius support. "
                    )
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            KeyValue("Username", c.optString("username"))
                            KeyValue("Phone", c.optString("phone"))
                            KeyValue("Email", c.optString("email"))
                            KeyValue("Address", c.optString("address"))
                            KeyValue("Service", c.optString("service"))
                            KeyValue("Account", c.optString("status"))
                            KeyValue("Plan", c.optString("plan").ifBlank { "No active plan" })
                            KeyValue(if (c.optBoolean("plan_active")) "Expires" else "Expired", c.optString("expires"))
                            KeyValue("Wallet", c.optString("balance"))
                        }
                    }
                    if (onRecharge != null && j.optBoolean("can_recharge")) {
                        Button(onClick = { onRecharge(c.optInt("id")) }, modifier = Modifier.fillMaxWidth()) { Text("Recharge customer") }
                    }

                    Text("ONU", style = MaterialTheme.typography.titleSmall)
                    if (onus.isEmpty()) Text("No ONU is linked to this customer.", style = MaterialTheme.typography.bodySmall)
                    onus.forEach { o ->
                        Card(Modifier.fillMaxWidth()) {
                            Column(Modifier.padding(12.dp)) {
                                KeyValue("Model", o.optString("model").ifBlank { o.optString("id") })
                                KeyValue("Serial", o.optString("serial"))
                                KeyValue(
                                    "Status",
                                    if (o.isNull("online")) "Unknown" else if (o.optBoolean("online")) "Online" else "Offline"
                                )
                                KeyValue("Signal", if (o.isNull("rx")) "" else o.optDouble("rx").toString() + " dBm")
                                KeyValue("Last seen", o.optString("last_inform"))
                            }
                        }
                    }

                    if (usage != null) {
                        Text("Data usage", style = MaterialTheme.typography.titleSmall)
                        UsagePanel(usage)
                    }

                    Text("Tickets", style = MaterialTheme.typography.titleSmall)
                    if (tickets.isEmpty()) Text("No tickets for this customer.", style = MaterialTheme.typography.bodySmall)
                    tickets.forEach { t ->
                        Card(Modifier.fillMaxWidth()) {
                            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                                Column(Modifier.weight(1f)) {
                                    Text(t.optString("title"), fontWeight = FontWeight.SemiBold)
                                    Text(
                                        listOf(t.optString("created"), t.optString("assignee")).filter { it.isNotBlank() }.joinToString(" · "),
                                        style = MaterialTheme.typography.bodySmall
                                    )
                                }
                                Text(t.optString("status"), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
                            }
                        }
                    }

                    if (payments.isNotEmpty()) {
                        Text("Recent payments", style = MaterialTheme.typography.titleSmall)
                        payments.forEach { TxRow(it) }
                    }
                    Spacer(Modifier.height(4.dp))
                }
            }
        }
    )
}
