package com.duntech.nuxbill

import android.Manifest
import android.content.Intent
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import kotlinx.coroutines.launch
import org.json.JSONObject
import org.osmdroid.config.Configuration
import org.osmdroid.tileprovider.tilesource.TileSourceFactory
import org.osmdroid.util.GeoPoint
import org.osmdroid.views.MapView
import org.osmdroid.views.overlay.Marker
import org.osmdroid.views.overlay.Polyline
import java.io.File

private val Red = Color(0xFFDC2626)
private val Amber = Color(0xFFF59E0B)
private val Green = Color(0xFF16A34A)
private val Grey = Color(0xFF9CA3AF)

private fun healthColor(health: String, flag: Boolean = false): Color = when {
    flag || health == "dark" -> Red
    health == "partial" || health == "weak" -> Amber
    health == "ok" -> Green
    else -> Grey
}

private fun onuColor(o: JSONObject): Color = when {
    o.isNull("online") -> Grey
    !o.optBoolean("online") -> Red
    o.optString("rx_state") == "warn" || o.optString("rx_state") == "over" -> Amber
    else -> Green
}

private fun assetLabel(a: JSONObject) = a.optString("type").uppercase() + " " + a.optString("name")

private fun hasPos(o: JSONObject) = !o.isNull("lat") && !o.isNull("lng")

@Composable
private fun Dot(c: Color, size: Int = 12) {
    Box(Modifier.size(size.dp).background(c, CircleShape))
}

/** Fibre and splitter map: where every OLT, ODC, ODP, pole and ONU is, and which cable feeds what. */
@Composable
fun NetMapScreen(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var view by rememberSaveable { mutableStateOf("faults") }
    var open by remember { mutableStateOf<Int?>(null) }
    var onuOpen by remember { mutableStateOf<JSONObject?>(null) }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var placing by remember { mutableStateOf<Pair<Int, String?>?>(null) }
    var showPlaceAny by remember { mutableStateOf(false) }

    Remote(listOf(reload, bump), load = { Api.call("netmap_data") }) { j ->
        val assets = j.optJSONArray("assets").list()
        val onus = j.optJSONArray("onus").list()
        val faults = j.optJSONArray("faults").list()
        val st = j.getJSONObject("stats")
        val def = j.optJSONArray("default")
        Column(Modifier.fillMaxSize()) {
            if (!j.optBoolean("live")) Text("GenieACS is not reachable: ONU status is unknown right now.", Modifier.padding(12.dp), color = MaterialTheme.colorScheme.error)
            Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatCard("Points", st.optInt("assets").toString(), "info", Modifier.weight(1f))
                StatCard("ONUs on map", st.optInt("onus").toString(), "info", Modifier.weight(1f))
                StatCard("Offline", st.optInt("offline").toString(), if (st.optInt("offline") > 0) "bad" else "good", Modifier.weight(1f))
            }
            Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = view == "faults", onClick = { view = "faults" }, label = { Text("Faults (" + faults.size + ")") })
                FilterChip(selected = view == "map", onClick = { view = "map" }, label = { Text("Map") })
                FilterChip(selected = view == "list", onClick = { view = "list" }, label = { Text("Network") })
            }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                when (view) {
                    "faults" -> FaultList(faults, st.optInt("onus") == 0) { open = it }
                    "list" -> NetworkTree(assets) { open = it }
                    else -> NetMapView(assets, onus, def, { open = it }, { onuOpen = it })
                }
            }
            Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(onClick = { editing = JSONObject() }, modifier = Modifier.weight(1f)) { Text("Add point") }
                OutlinedButton(onClick = { showPlaceAny = true }, modifier = Modifier.weight(1f)) { Text("Place an ONU") }
            }
        }

        open?.let { id ->
            val a = assets.firstOrNull { it.optInt("id") == id }
            if (a != null) AssetDialog(a, assets, onus, onClose = { open = null },
                onEdit = { editing = a; open = null },
                onAddChild = { editing = JSONObject().put("parent_id", id); open = null },
                onPlace = { placing = Pair(id, null); open = null },
                onOnu = { onuOpen = it; open = null },
                onChanged = { open = null; bump++ })
        }
        onuOpen?.let { o -> OnuTraceDialog(o, onClose = { onuOpen = null }, onMove = { placing = Pair(o.optInt("asset_id"), o.optString("id")); onuOpen = null }, onChanged = { onuOpen = null; bump++ }) }
        editing?.let { a -> AssetEditDialog(a, assets, onClose = { editing = null }, onSaved = { editing = null; bump++ }) }
        placing?.let { (aid, dev) -> PlaceOnuDialog(aid, dev, assets, onClose = { placing = null }, onDone = { placing = null; bump++ }) }
        if (showPlaceAny) PlaceOnuDialog(0, null, assets, onClose = { showPlaceAny = false }, onDone = { showPlaceAny = false; bump++ })
    }
}

@Composable
private fun FaultList(faults: List<JSONObject>, noOnus: Boolean, onOpen: (Int) -> Unit) {
    if (faults.isEmpty()) {
        Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Green.copy(alpha = 0.15f))) {
                Text("No faults found on the map right now.", Modifier.padding(16.dp), fontWeight = FontWeight.SemiBold)
            }
            if (noOnus) Text("No ONUs are placed yet. Use Place an ONU to attach each ONU to the splitter (ODP) it hangs on; faults are worked out from which ONUs on a branch go offline together.", style = MaterialTheme.typography.bodyMedium)
        }
        return
    }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items(faults) { f ->
            val c = when (f.optString("severity")) { "down" -> Red; "weak" -> Amber; else -> Grey }
            Card(Modifier.fillMaxWidth().clickable { onOpen(f.optInt("asset_id")) }, colors = CardDefaults.cardColors(containerColor = c.copy(alpha = 0.14f))) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(
                        (when (f.optString("kind")) { "cable" -> "CABLE OR SPLITTER: "; "drop" -> "DROP / ONU: "; "node" -> "NODE: "; "signal" -> "WEAK SIGNAL: "; else -> "FLAGGED: " }) + f.optString("title"),
                        fontWeight = FontWeight.Bold, color = c
                    )
                    Text(f.optString("text"), style = MaterialTheme.typography.bodyMedium)
                    val cust = f.optJSONArray("customers").strings()
                    if (cust.isNotEmpty()) Text("Affected: " + cust.joinToString(", "), style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

@Composable
private fun NetworkTree(assets: List<JSONObject>, onOpen: (Int) -> Unit) {
    val kids = remember(assets) { assets.groupBy { it.optInt("parent_id") } }
    val expanded = remember(assets) { mutableStateListOf<Int>().apply { if (assets.size <= 40) addAll(assets.filter { kids.containsKey(it.optInt("id")) }.map { it.optInt("id") }) } }
    val flat = ArrayList<Pair<JSONObject, Int>>()
    fun walk(a: JSONObject, depth: Int, seen: Set<Int>) {
        flat.add(Pair(a, depth))
        val id = a.optInt("id")
        if (id in seen) return
        if (id in expanded) kids[id]?.forEach { walk(it, depth + 1, seen + id) }
    }
    val roots = assets.filter { it.optInt("parent_id") == 0 }
    roots.forEach { walk(it, 0, emptySet()) }
    if (assets.isEmpty()) {
        Text("No points yet. Tap Add point to start with your OLT.", Modifier.padding(16.dp))
        return
    }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 8.dp, vertical = 4.dp)) {
        items(flat) { (a, depth) ->
            val id = a.optInt("id")
            val hasKids = kids.containsKey(id)
            Row(Modifier.fillMaxWidth().clickable { onOpen(id) }.padding(start = (depth * 18).dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                if (hasKids) {
                    TextButton(onClick = { if (id in expanded) expanded.remove(id) else expanded.add(id) }, contentPadding = PaddingValues(0.dp), modifier = Modifier.width(32.dp)) {
                        Text(if (id in expanded) "v" else ">")
                    }
                } else Spacer(Modifier.width(32.dp))
                Dot(healthColor(a.optString("health"), a.optBoolean("flag")))
                Spacer(Modifier.width(8.dp))
                Column(Modifier.weight(1f)) {
                    Text(assetLabel(a), fontWeight = FontWeight.SemiBold)
                    val bits = listOf(a.optString("cable"), if (a.optString("port").isNotBlank()) "port " + a.optString("port") else "", a.optString("split")).filter { it.isNotBlank() }
                    if (bits.isNotEmpty()) Text(bits.joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                }
                if (a.optInt("total") > 0) Text(a.optInt("off").toString() + "/" + a.optInt("total") + " off", style = MaterialTheme.typography.labelMedium,
                    color = if (a.optInt("off") > 0) Red else MaterialTheme.colorScheme.onSurface)
            }
            HorizontalDivider()
        }
    }
}

private fun dotDrawable(color: Color, px: Int): GradientDrawable = GradientDrawable().apply {
    shape = GradientDrawable.OVAL
    setColor(color.toArgb())
    setStroke(3, android.graphics.Color.WHITE)
    setSize(px, px)
}

@Composable
private fun NetMapView(assets: List<JSONObject>, onus: List<JSONObject>, def: org.json.JSONArray?, onAsset: (Int) -> Unit, onOnu: (JSONObject) -> Unit) {
    val ctx = LocalContext.current
    val mv = remember {
        Configuration.getInstance().apply {
            userAgentValue = ctx.packageName
            osmdroidBasePath = File(ctx.cacheDir, "osmdroid")
            osmdroidTileCache = File(ctx.cacheDir, "osmdroid/tiles")
        }
        MapView(ctx).apply {
            setTileSource(TileSourceFactory.MAPNIK)
            setMultiTouchControls(true)
            minZoomLevel = 4.0
            controller.setZoom(15.0)
        }
    }
    DisposableEffect(mv) {
        mv.onResume()
        onDispose { mv.onPause(); mv.onDetach() }
    }
    var centered by remember { mutableStateOf(false) }
    val density = ctx.resources.displayMetrics.density
    AndroidView(modifier = Modifier.fillMaxSize(), factory = { mv }, update = { m ->
        m.overlays.clear()
        val byId = assets.associateBy { it.optInt("id") }
        assets.forEach { a ->
            val p = byId[a.optInt("parent_id")]
            if (p != null && hasPos(a) && hasPos(p)) {
                m.overlays.add(Polyline(m).apply {
                    setPoints(listOf(GeoPoint(p.optDouble("lat"), p.optDouble("lng")), GeoPoint(a.optDouble("lat"), a.optDouble("lng"))))
                    outlinePaint.color = healthColor(a.optString("health"), a.optBoolean("flag")).toArgb()
                    outlinePaint.strokeWidth = if (a.optString("health") == "dark") 9f else 5f
                })
            }
        }
        onus.take(500).forEach { o ->
            val at = byId[o.optInt("asset_id")]
            val la = if (hasPos(o)) o.optDouble("lat") else at?.takeIf { hasPos(it) }?.optDouble("lat")
            val ln = if (hasPos(o)) o.optDouble("lng") else at?.takeIf { hasPos(it) }?.optDouble("lng")
            if (la != null && ln != null) {
                if (hasPos(o) && at != null && hasPos(at)) {
                    m.overlays.add(Polyline(m).apply {
                        setPoints(listOf(GeoPoint(at.optDouble("lat"), at.optDouble("lng")), GeoPoint(la, ln)))
                        outlinePaint.color = onuColor(o).copy(alpha = 0.6f).toArgb()
                        outlinePaint.strokeWidth = 3f
                    })
                }
                m.overlays.add(Marker(m).apply {
                    position = GeoPoint(la, ln)
                    icon = dotDrawable(onuColor(o), (12 * density).toInt())
                    setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_CENTER)
                    title = o.optString("name")
                    setOnMarkerClickListener { _, _ -> onOnu(o); true }
                })
            }
        }
        assets.filter { hasPos(it) }.forEach { a ->
            m.overlays.add(Marker(m).apply {
                position = GeoPoint(a.optDouble("lat"), a.optDouble("lng"))
                icon = dotDrawable(healthColor(a.optString("health"), a.optBoolean("flag")), ((if (a.optString("type") == "olt") 28 else 20) * density).toInt())
                setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_CENTER)
                title = assetLabel(a)
                setOnMarkerClickListener { _, _ -> onAsset(a.optInt("id")); true }
            })
        }
        if (!centered) {
            centered = true
            val first = assets.firstOrNull { hasPos(it) }
            if (first != null) m.controller.setCenter(GeoPoint(first.optDouble("lat"), first.optDouble("lng")))
            else if (def != null && def.length() == 2) m.controller.setCenter(GeoPoint(def.optDouble(0), def.optDouble(1)))
        }
        m.invalidate()
    })
}

@Composable
private fun AssetDialog(
    a: JSONObject, assets: List<JSONObject>, onus: List<JSONObject>, onClose: () -> Unit, onEdit: () -> Unit,
    onAddChild: () -> Unit, onPlace: () -> Unit, onOnu: (JSONObject) -> Unit, onChanged: () -> Unit
) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var confirmDelete by remember { mutableStateOf(false) }
    val own = onus.filter { it.optInt("asset_id") == a.optInt("id") }
    val children = assets.filter { it.optInt("parent_id") == a.optInt("id") }
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text(assetLabel(a)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Dot(healthColor(a.optString("health"), a.optBoolean("flag")))
                    Text(
                        when {
                            a.optBoolean("flag") -> "Marked as a fault"
                            a.optString("health") == "dark" -> "All ONUs below are offline"
                            a.optString("health") == "partial" -> a.optInt("off").toString() + " of " + a.optInt("total") + " ONUs offline"
                            a.optString("health") == "weak" -> "Weak signal on some ONUs"
                            a.optString("health") == "ok" -> "All " + a.optInt("total") + " ONUs fine"
                            a.optString("health") == "unknown" -> "Status unknown"
                            else -> "No ONUs below yet"
                        }
                    )
                }
                Remote(a.optInt("id"), Modifier.fillMaxWidth().heightIn(min = 40.dp), load = { Api.call("netmap_trace", mapOf("asset_id" to a.optInt("id").toString())) }) { t ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Fed by", style = MaterialTheme.typography.titleSmall)
                        t.optJSONArray("path").list().forEachIndexed { i, p ->
                            Row(Modifier.padding(start = (i * 10).dp), verticalAlignment = Alignment.CenterVertically) {
                                Dot(healthColor(p.optString("health")), 9)
                                Spacer(Modifier.width(6.dp))
                                Text(assetLabel(p) + (if (p.optString("cable").isNotBlank()) "  via " + p.optString("cable") else "") + (if (p.optString("port").isNotBlank()) "  port " + p.optString("port") else ""),
                                    style = MaterialTheme.typography.bodySmall)
                            }
                        }
                        Text(t.optString("verdict"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    }
                }
                KeyValue("Cable in", a.optString("cable"))
                KeyValue("Cores", a.optString("cores"))
                KeyValue("Length", if (a.isNull("length_m")) "" else a.optInt("length_m").toString() + " m")
                KeyValue("Splitter", a.optString("split"))
                if (!a.isNull("capacity")) KeyValue("Ports", (a.optInt("capacity") - a.optInt("own")).coerceAtLeast(0).toString() + " free of " + a.optInt("capacity"))
                if (a.optString("note").isNotBlank()) KeyValue("Note", a.optString("note"))
                if (children.isNotEmpty()) {
                    Text("Feeds", style = MaterialTheme.typography.titleSmall)
                    children.forEach { c -> Text(assetLabel(c) + (if (c.optInt("total") > 0) "  (" + c.optInt("off") + "/" + c.optInt("total") + " off)" else ""), style = MaterialTheme.typography.bodySmall) }
                }
                if (own.isNotEmpty()) {
                    Text("ONUs here", style = MaterialTheme.typography.titleSmall)
                    own.forEach { o ->
                        Row(Modifier.fillMaxWidth().clickable { onOnu(o) }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Dot(onuColor(o), 10)
                            Spacer(Modifier.width(8.dp))
                            Text(o.optString("name") + (if (o.optString("port").isNotBlank()) "  port " + o.optString("port") else ""), Modifier.weight(1f))
                            Text(if (o.isNull("rx")) "" else o.optDouble("rx").toString() + " dBm", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                if (hasPos(a)) OutlinedButton(onClick = {
                    val uri = Uri.parse("geo:" + a.optDouble("lat") + "," + a.optDouble("lng") + "?q=" + a.optDouble("lat") + "," + a.optDouble("lng") + "(" + Uri.encode(a.optString("name")) + ")")
                    try { ctx.startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } catch (e: Exception) { toast(ctx, "No maps app found") }
                }, modifier = Modifier.fillMaxWidth()) { Text("Navigate here") }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = onEdit, modifier = Modifier.weight(1f)) { Text("Edit") }
                    OutlinedButton(onClick = onAddChild, modifier = Modifier.weight(1f)) { Text("Add fed point") }
                }
                OutlinedButton(onClick = onPlace, modifier = Modifier.fillMaxWidth()) { Text("Place an ONU on this point") }
                TextButton(onClick = { confirmDelete = true }) { Text("Delete this point", color = MaterialTheme.colorScheme.error) }
            }
        }
    )
    if (confirmDelete) AlertDialog(
        onDismissRequest = { confirmDelete = false },
        title = { Text("Delete " + assetLabel(a) + "?") },
        text = { Text("Only possible when nothing is fed from it and no ONU is placed on it.") },
        confirmButton = {
            Button(onClick = {
                scope.launch {
                    try { toast(ctx, Api.call("netmap_asset_delete", mapOf("id" to a.optInt("id").toString())).optString("message")); onChanged() } catch (e: Exception) { toast(ctx, e.message); confirmDelete = false }
                }
            }) { Text("Delete") }
        },
        dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Cancel") } }
    )
}

@Composable
private fun OnuTraceDialog(o: JSONObject, onClose: () -> Unit, onMove: () -> Unit, onChanged: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text(o.optString("name")) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Dot(onuColor(o))
                    Text(if (o.isNull("online")) "Status unknown" else if (o.optBoolean("online")) "Online" else "Offline")
                    if (!o.isNull("rx")) Text(o.optDouble("rx").toString() + " dBm")
                }
                if (o.optString("phone").isNotBlank()) ContactRow(o.optString("phone"), "Hello " + o.optString("name").substringBefore(' ') + ", this is Eazyradius support. ")
                Remote(o.optString("id"), Modifier.fillMaxWidth().heightIn(min = 40.dp), load = { Api.call("netmap_trace", mapOf("device_id" to o.optString("id"))) }) { t ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Fed by", style = MaterialTheme.typography.titleSmall)
                        t.optJSONArray("path").list().forEachIndexed { i, p ->
                            Row(Modifier.padding(start = (i * 10).dp), verticalAlignment = Alignment.CenterVertically) {
                                Dot(healthColor(p.optString("health")), 9)
                                Spacer(Modifier.width(6.dp))
                                Text(assetLabel(p) + (if (p.optString("cable").isNotBlank()) "  via " + p.optString("cable") else "") + (if (p.optString("port").isNotBlank()) "  port " + p.optString("port") else ""),
                                    style = MaterialTheme.typography.bodySmall)
                            }
                        }
                        Text(t.optString("verdict"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = onMove, modifier = Modifier.weight(1f)) { Text("Move") }
                    OutlinedButton(onClick = {
                        scope.launch {
                            try { toast(ctx, Api.call("netmap_onu_place", mapOf("device_id" to o.optString("id"), "asset_id" to "0")).optString("message")); onChanged() } catch (e: Exception) { toast(ctx, e.message) }
                        }
                    }, modifier = Modifier.weight(1f)) { Text("Remove") }
                }
            }
        }
    )
}

/** Attach an ONU to the splitter it hangs on. */
@Composable
private fun PlaceOnuDialog(assetId: Int, device: String?, assets: List<JSONObject>, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var dev by remember { mutableStateOf(device) }
    var target by remember { mutableIntStateOf(assetId) }
    var port by remember { mutableStateOf("") }
    var q by remember { mutableStateOf("") }
    var applied by remember { mutableStateOf("") }
    var menu by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val chosen = assets.firstOrNull { it.optInt("id") == target }
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Place an ONU") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (dev == null) {
                    OutlinedTextField(q, { q = it }, label = { Text("Search serial, customer or phone") }, singleLine = true, modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(imeAction = androidx.compose.ui.text.input.ImeAction.Search),
                        keyboardActions = androidx.compose.foundation.text.KeyboardActions(onSearch = { applied = q.trim() }))
                    Remote(applied, Modifier.fillMaxWidth().heightIn(min = 60.dp), load = { Api.call("netmap_unplaced", mapOf("q" to applied)) }) { r ->
                        val rows = r.optJSONArray("onus").list()
                        if (rows.isEmpty()) Text("No unplaced ONUs found.")
                        rows.forEach { o ->
                            TextButton(onClick = { dev = o.optString("id") }, modifier = Modifier.fillMaxWidth()) {
                                Text((o.optString("customer").ifBlank { o.optString("serial") }) + "  " + o.optString("model"), modifier = Modifier.fillMaxWidth())
                            }
                        }
                    }
                } else {
                    Text("ONU: " + dev, style = MaterialTheme.typography.bodySmall)
                    Box {
                        OutlinedButton(onClick = { menu = true }, modifier = Modifier.fillMaxWidth()) { Text(if (chosen == null) "Choose the point it hangs on" else assetLabel(chosen)) }
                        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                            assets.forEach { a -> DropdownMenuItem(text = { Text(assetLabel(a)) }, onClick = { target = a.optInt("id"); menu = false }) }
                        }
                    }
                    OutlinedTextField(port, { port = it.take(20) }, label = { Text("Port on the splitter (optional)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy && dev != null && target > 0, onClick = {
                busy = true
                scope.launch {
                    try { toast(ctx, Api.call("netmap_onu_place", mapOf("device_id" to (dev ?: ""), "asset_id" to target.toString(), "port" to port)).optString("message")); onDone() }
                    catch (e: Exception) { toast(ctx, e.message); busy = false }
                }
            }) { Text("Place") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

/** Create or edit an OLT, ODC, ODP, pole or router point and say which point (and cable) feeds it. */
@Composable
private fun AssetEditDialog(a: JSONObject, assets: List<JSONObject>, onClose: () -> Unit, onSaved: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val id = a.optInt("id")
    var type by remember { mutableStateOf(a.optString("type").ifBlank { "odp" }) }
    var name by remember { mutableStateOf(a.optString("name")) }
    var parent by remember { mutableIntStateOf(a.optInt("parent_id")) }
    var cable by remember { mutableStateOf(a.optString("cable")) }
    var cores by remember { mutableStateOf(a.optString("cores")) }
    var len by remember { mutableStateOf(if (a.has("length_m") && !a.isNull("length_m")) a.optInt("length_m").toString() else "") }
    var split by remember { mutableStateOf(a.optString("split")) }
    var port by remember { mutableStateOf(a.optString("port")) }
    var lat by remember { mutableStateOf(if (hasPos(a)) a.optDouble("lat").toString() else "") }
    var lng by remember { mutableStateOf(if (hasPos(a)) a.optDouble("lng").toString() else "") }
    var note by remember { mutableStateOf(a.optString("note")) }
    var flag by remember { mutableStateOf(a.optBoolean("flag")) }
    var menu by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val par = assets.firstOrNull { it.optInt("id") == parent }

    fun locate() {
        scope.launch {
            val loc = currentLocation(ctx)
            if (loc == null) toast(ctx, "Could not get your location. Switch on GPS and try again.")
            else { lat = "%.6f".format(loc.latitude); lng = "%.6f".format(loc.longitude) }
        }
    }
    val perm = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { res ->
        if (res.values.any { it }) locate() else toast(ctx, "Location permission is needed to use your position")
    }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(if (id == 0) "Add a point" else "Edit " + assetLabel(a)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("olt", "odc", "odp", "pole", "router").forEach { t -> FilterChip(selected = type == t, onClick = { type = t }, label = { Text(t.uppercase()) }) }
                }
                OutlinedTextField(name, { name = it }, label = { Text("Name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Box {
                    OutlinedButton(onClick = { menu = true }, modifier = Modifier.fillMaxWidth()) { Text(if (par == null) "Fed by: nothing (this is a start point)" else "Fed by: " + assetLabel(par)) }
                    DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                        DropdownMenuItem(text = { Text("Nothing (start point)") }, onClick = { parent = 0; menu = false })
                        assets.filter { it.optInt("id") != id }.forEach { p -> DropdownMenuItem(text = { Text(assetLabel(p)) }, onClick = { parent = p.optInt("id"); menu = false }) }
                    }
                }
                OutlinedTextField(cable, { cable = it }, label = { Text("Cable that feeds it (name or label)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(cores, { cores = it }, label = { Text("Cores") }, singleLine = true, modifier = Modifier.weight(1f))
                    OutlinedTextField(len, { len = it.filter { c -> c.isDigit() } }, label = { Text("Length (m)") }, singleLine = true, modifier = Modifier.weight(1f),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(split, { split = it }, label = { Text("Splitter (1:8)") }, singleLine = true, modifier = Modifier.weight(1f))
                    OutlinedTextField(port, { port = it }, label = { Text("Port on feeder") }, singleLine = true, modifier = Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(lat, { lat = it }, label = { Text("Latitude") }, singleLine = true, modifier = Modifier.weight(1f))
                    OutlinedTextField(lng, { lng = it }, label = { Text("Longitude") }, singleLine = true, modifier = Modifier.weight(1f))
                }
                OutlinedButton(onClick = {
                    if (hasLocationPermission(ctx)) locate() else perm.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                }, modifier = Modifier.fillMaxWidth()) { Text("Use my location") }
                OutlinedTextField(note, { note = it }, label = { Text("Note") }, modifier = Modifier.fillMaxWidth())
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Switch(checked = flag, onCheckedChange = { flag = it })
                    Spacer(Modifier.width(8.dp))
                    Text("Mark as a fault")
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy && name.isNotBlank(), onClick = {
                busy = true
                scope.launch {
                    try {
                        val p = mutableMapOf("type" to type, "name" to name, "parent_id" to parent.toString(), "cable" to cable, "cores" to cores, "length_m" to len,
                            "split" to split, "port" to port, "lat" to lat, "lng" to lng, "note" to note, "flag" to if (flag) "1" else "0")
                        if (id > 0) p["id"] = id.toString()
                        toast(ctx, Api.call("netmap_asset_save", p).optString("message"))
                        onSaved()
                    } catch (e: Exception) { toast(ctx, e.message); busy = false }
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
