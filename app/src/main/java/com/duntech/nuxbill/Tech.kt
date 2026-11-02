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
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
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
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TechJobs(reload: Int) {
    var filter by rememberSaveable { mutableStateOf("") }
    var bump by remember { mutableIntStateOf(0) }

    Remote(listOf(reload, bump), load = { Api.call("tech_jobs") }) { j ->
        val all = j.optJSONArray("jobs").list()
        val statuses = j.optJSONArray("statuses").strings()
        val chips = all.map { it.optString("status") }.filter { it.isNotBlank() }.distinct()
        val shown = if (filter.isEmpty()) all else all.filter { it.optString("status") == filter }

        Column(Modifier.fillMaxSize()) {
            if (j.has("note")) {
                Text(j.optString("note"), Modifier.padding(12.dp), color = MaterialTheme.colorScheme.error)
            }
            LazyRow(
                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                item { FilterChip(selected = filter.isEmpty(), onClick = { filter = "" }, label = { Text("All (${all.size})") }) }
                items(chips) { s ->
                    FilterChip(selected = filter == s, onClick = { filter = s }, label = { Text(s) })
                }
            }
            Box(Modifier.weight(1f)) {
                if (shown.isEmpty()) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text("No jobs") }
                } else {
                    LazyVerticalGrid(
                        GridCells.Adaptive(300.dp),
                        Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(12.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        items(shown) { job -> JobCard(job, statuses) { bump++ } }
                    }
                }
            }
        }
    }
}

@Composable
private fun JobCard(job: JSONObject, statuses: List<String>, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val phone = job.optString("phone")
    val address = job.optString("address")
    val current = job.optString("status")

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(
                    job.optString("title").ifBlank { "Job #" + job.optInt("id") },
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f)
                )
                Text(current, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
            }
            KeyValue("Details", job.optString("subtitle"))
            KeyValue("Phone", phone)
            KeyValue("Address", address)
            KeyValue("Created", job.optString("created"))
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
            }
            val options = statuses.filter { it != current }
            if (options.isNotEmpty()) {
                Spacer(Modifier.height(6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    options.forEach { s ->
                        OutlinedButton(onClick = {
                            scope.launch {
                                try {
                                    Api.call("tech_update", mapOf("id" to job.optInt("id").toString(), "status" to s))
                                    onChanged()
                                } catch (e: Exception) {
                                    toast(ctx, e.message)
                                }
                            }
                        }) { Text("Mark " + s.replace('_', ' ')) }
                    }
                }
            }
        }
    }
}
