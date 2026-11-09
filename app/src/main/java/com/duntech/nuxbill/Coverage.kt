package com.duntech.nuxbill

import android.Manifest
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private fun levelColor(level: String): Color = when (level) {
    "covered" -> Color(0xFF16A34A)
    "extension", "full" -> Color(0xFFF59E0B)
    else -> Color(0xFFDC2626)
}

/** Shared search box: an address (found on the map) or the phone's own position. [call] runs the check for a place. */
@Composable
private fun PlaceSearch(searchAction: String?, onCheck: suspend (Double, Double, String) -> Unit, busyState: MutableState<Boolean>) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var q by remember { mutableStateOf("") }
    var places by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    fun here() {
        busyState.value = true
        scope.launch {
            val loc = currentLocation(ctx)
            if (loc == null) toast(ctx, "Could not get your location. Switch on GPS and try again.")
            else try { onCheck(loc.latitude, loc.longitude, "") } catch (e: Exception) { toast(ctx, e.message) }
            busyState.value = false
        }
    }
    val perm = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { res ->
        if (res.values.any { it }) here() else toast(ctx, "Location permission is needed to use your position")
    }
    OutlinedTextField(q, { q = it }, label = { Text("Estate, street or landmark") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(enabled = !busyState.value && q.trim().length >= 3 && searchAction != null, onClick = {
            busyState.value = true
            scope.launch {
                try {
                    places = Api.call(searchAction ?: "", mapOf("q" to q.trim())).optJSONArray("places").list()
                    if (places.isEmpty()) toast(ctx, "No place found. Try another spelling or a nearby landmark.")
                } catch (e: Exception) { toast(ctx, e.message) }
                busyState.value = false
            }
        }) { Text("Search address") }
        OutlinedButton(enabled = !busyState.value, onClick = {
            if (hasLocationPermission(ctx)) here() else perm.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }) { Text("Use my location") }
    }
    places.forEach { p ->
        TextButton(enabled = !busyState.value, onClick = {
            busyState.value = true
            scope.launch {
                try { onCheck(p.optDouble("lat"), p.optDouble("lng"), p.optString("name")); places = emptyList() } catch (e: Exception) { toast(ctx, e.message) }
                busyState.value = false
            }
        }, modifier = Modifier.fillMaxWidth()) { Text(p.optString("name"), modifier = Modifier.fillMaxWidth()) }
    }
}

/** Staff: is there fibre at this address, which splitters are near, and do they have free ports. */
@Composable
fun CoverageScreen(reload: Int) {
    val ctx = LocalContext.current
    val busy = remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<JSONObject?>(null) }
    var where by remember { mutableStateOf("") }
    var coords by remember { mutableStateOf<Pair<Double, Double>?>(null) }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Check whether a customer's location can be connected.", style = MaterialTheme.typography.bodyMedium)
        PlaceSearch("coverage_geocode", { lat, lng, name ->
            result = Api.call("coverage_check", mapOf("lat" to lat.toString(), "lng" to lng.toString()))
            where = name
            coords = Pair(lat, lng)
        }, busy)
        if (busy.value) LinearProgressIndicator(Modifier.fillMaxWidth())
        result?.let { r ->
            val c = levelColor(r.optString("level"))
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = c.copy(alpha = 0.15f))) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(
                        when (r.optString("level")) { "covered" -> "COVERED"; "full" -> "SPLITTER FULL"; "extension" -> "NEEDS EXTENSION"; else -> "NOT COVERED" },
                        fontWeight = FontWeight.Bold, color = c, style = MaterialTheme.typography.titleMedium
                    )
                    Text(r.optString("message"))
                    if (where.isNotBlank()) Text(where, style = MaterialTheme.typography.bodySmall)
                }
            }
            val near = r.optJSONArray("near").list()
            if (near.isNotEmpty()) Text("Nearest splitters", style = MaterialTheme.typography.titleSmall)
            near.forEach { o ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(Modifier.weight(1f)) {
                            Text("ODP " + o.optString("name"), fontWeight = FontWeight.SemiBold)
                            Text(
                                if (o.isNull("capacity")) "Splitter size not set" else o.optInt("free").toString() + " of " + o.optInt("capacity") + " ports free",
                                style = MaterialTheme.typography.bodySmall
                            )
                        }
                        Text(o.optInt("distance_m").toString() + " m", fontWeight = FontWeight.Bold)
                    }
                }
            }
            coords?.let { (la, ln) ->
                OutlinedButton(onClick = {
                    try { ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:$la,$ln?q=$la,$ln")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } catch (e: Exception) { toast(ctx, "No maps app found") }
                }) { Text("Open this spot in Maps") }
            }
        }
    }
}

/** For people without an account: open from the sign-in screen with the server address filled in. */
@Composable
fun PublicCoverageDialog(base: String, onClose: () -> Unit) {
    val busy = remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<JSONObject?>(null) }
    var q by remember { mutableStateOf("") }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    androidx.compose.ui.window.Dialog(onDismissRequest = onClose, properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 8.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Do you cover my area?", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    TextButton(onClick = onClose) { Text("Close") }
                }
                Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Tell us where you are and we will check whether our fibre reaches you.", style = MaterialTheme.typography.bodyMedium)
                    PlaceSearch(null, { lat, lng, _ -> result = Api.call("coverage_public", mapOf("lat" to lat.toString(), "lng" to lng.toString())) }, busy)
                    OutlinedTextField(q, { q = it }, label = { Text("Or type your estate or street") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    Button(enabled = !busy.value && q.trim().length >= 3, onClick = {
                        busy.value = true
                        scope.launch {
                            try { result = Api.call("coverage_public", mapOf("q" to q.trim())) } catch (e: Exception) { toast(ctx, e.message) }
                            busy.value = false
                        }
                    }) { Text("Check") }
                    if (busy.value) LinearProgressIndicator(Modifier.fillMaxWidth())
                    result?.let { r ->
                        val c = levelColor(r.optString("level"))
                        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = c.copy(alpha = 0.15f))) {
                            Column(Modifier.padding(12.dp)) {
                                Text(r.optString("message"), fontWeight = FontWeight.SemiBold)
                                if (r.optString("place").isNotBlank()) Text(r.optString("place"), style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
            }
        }
    }
}
