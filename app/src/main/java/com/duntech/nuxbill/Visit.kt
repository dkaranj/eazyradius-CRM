package com.duntech.nuxbill

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.Looper
import android.util.Base64
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyRow
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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.exifinterface.media.ExifInterface
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import kotlin.coroutines.resume

/* ---------------- location ---------------- */

fun hasLocationPermission(ctx: Context): Boolean =
    ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

/** A fresh GPS/network fix (up to 12 s), falling back to the newest last-known position. Null if there is none. */
@SuppressLint("MissingPermission")
suspend fun currentLocation(ctx: Context, waitMs: Long = 12000): Location? {
    if (!hasLocationPermission(ctx)) return null
    val lm = ctx.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    val provider = when {
        lm.isProviderEnabled(LocationManager.GPS_PROVIDER) -> LocationManager.GPS_PROVIDER
        lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER) -> LocationManager.NETWORK_PROVIDER
        else -> null
    }
    var fresh: Location? = null
    if (provider != null) {
        fresh = withTimeoutOrNull(waitMs) {
            suspendCancellableCoroutine<Location?> { cont ->
                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        lm.removeUpdates(this)
                        if (cont.isActive) cont.resume(location)
                    }

                    @Deprecated("Deprecated in Java")
                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {
                    }

                    override fun onProviderEnabled(provider: String) {}
                    override fun onProviderDisabled(provider: String) {}
                }
                lm.requestLocationUpdates(provider, 0L, 0f, listener, Looper.getMainLooper())
                cont.invokeOnCancellation { lm.removeUpdates(listener) }
            }
        }
    }
    if (fresh != null) return fresh
    return lm.getProviders(true).mapNotNull { lm.getLastKnownLocation(it) }
        .filter { System.currentTimeMillis() - it.time < 10 * 60 * 1000 }
        .maxByOrNull { it.time }
}

/* ---------------- photo preparation ---------------- */

/** Shrinks a camera photo to at most 1280 px, fixes its rotation and returns it as base64 JPEG. */
fun prepareJpeg(file: File): String? {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeFile(file.path, bounds)
    if (bounds.outWidth <= 0) return null
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / sample > 2000) sample *= 2
    val bm = BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null
    val rotation = when (ExifInterface(file.path).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
        ExifInterface.ORIENTATION_ROTATE_90 -> 90f
        ExifInterface.ORIENTATION_ROTATE_180 -> 180f
        ExifInterface.ORIENTATION_ROTATE_270 -> 270f
        else -> 0f
    }
    val scale = minOf(1f, 1280f / maxOf(bm.width, bm.height))
    val m = Matrix().apply { postRotate(rotation); postScale(scale, scale) }
    val out = Bitmap.createBitmap(bm, 0, 0, bm.width, bm.height, m, true)
    val bos = ByteArrayOutputStream()
    out.compress(Bitmap.CompressFormat.JPEG, 72, bos)
    return Base64.encodeToString(bos.toByteArray(), Base64.NO_WRAP)
}

private fun decodeB64(b64: String, sample: Int): Bitmap? {
    val bytes = Base64.decode(b64, Base64.DEFAULT)
    return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
}

/* ---------------- screens ---------------- */

@Composable
private fun Thumb(photo: JSONObject, entity: String, itemId: Int, onOpen: () -> Unit) {
    var bm by remember(photo.optInt("id")) { mutableStateOf<Bitmap?>(null) }
    var failed by remember(photo.optInt("id")) { mutableStateOf(false) }
    LaunchedEffect(photo.optInt("id")) {
        try {
            val r = Api.call("photo_get", mapOf("photo_id" to photo.optInt("id").toString(), "thumb" to "1", "entity" to entity, "id" to itemId.toString()))
            bm = withContext(Dispatchers.Default) { decodeB64(r.getString("image"), 1) }
        } catch (e: Exception) {
            failed = true
        }
    }
    Box(Modifier.size(110.dp).clickable(onClick = onOpen), contentAlignment = Alignment.Center) {
        val b = bm
        if (b != null) Image(b.asImageBitmap(), null, Modifier.size(110.dp), contentScale = ContentScale.Crop)
        else Text(if (failed) "!" else "…")
    }
}

@Composable
private fun PhotoViewer(photo: JSONObject, onClose: () -> Unit) {
    AlertDialog(
        onDismissRequest = onClose,
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        title = { Text(photo.optString("caption").ifBlank { "Photo" }) },
        text = {
            Remote(photo.optInt("id"), Modifier.fillMaxWidth().heightIn(min = 120.dp), load = {
                val j = Api.call("photo_get", mapOf("photo_id" to photo.optInt("id").toString()))
                withContext(Dispatchers.Default) { decodeB64(j.getString("image"), 1) }
            }) { b ->
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (b != null) Image(b.asImageBitmap(), null, Modifier.fillMaxWidth(), contentScale = ContentScale.FillWidth)
                    KeyValue("Taken by", photo.optString("by"))
                    KeyValue("When", photo.optString("time"))
                }
            }
        }
    )
}

/** Site visit for a ticket, lead or installation: GPS arrival / finish stamps and photos. */
@Composable
fun VisitDialog(entity: String, id: Int, title: String, onClose: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var bump by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<JSONObject?>(null) }
    var camFile by remember { mutableStateOf<File?>(null) }
    var pendingKind by remember { mutableStateOf<String?>(null) }

    fun stamp(kind: String) {
        busy = true
        scope.launch {
            try {
                val loc = currentLocation(ctx)
                if (loc == null) {
                    toast(ctx, "Could not get your location. Turn on GPS and try again.")
                } else {
                    val r = Api.call(
                        "checkin",
                        mapOf(
                            "entity" to entity, "id" to id.toString(), "kind" to kind,
                            "lat" to loc.latitude.toString(), "lng" to loc.longitude.toString(),
                            "accuracy" to loc.accuracy.toInt().toString()
                        )
                    )
                    toast(ctx, r.optString("message", "Saved"))
                    bump++
                }
            } catch (e: Exception) {
                toast(ctx, e.message)
            } finally {
                busy = false
            }
        }
    }

    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { res ->
        val kind = pendingKind
        pendingKind = null
        if (kind != null) {
            if (res.values.any { it }) stamp(kind) else toast(ctx, "Location permission is needed to check in")
        }
    }

    fun checkIn(kind: String) {
        if (hasLocationPermission(ctx)) stamp(kind)
        else {
            pendingKind = kind
            permLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }
    }

    val camLauncher = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        val f = camFile
        if (ok && f != null) {
            busy = true
            scope.launch {
                try {
                    val b64 = withContext(Dispatchers.Default) { prepareJpeg(f) }
                    if (b64 == null) {
                        toast(ctx, "Could not read the photo")
                    } else {
                        val loc = currentLocation(ctx, 4000)
                        val p = mutableMapOf("entity" to entity, "id" to id.toString(), "image" to b64)
                        if (loc != null) {
                            p["lat"] = loc.latitude.toString()
                            p["lng"] = loc.longitude.toString()
                        }
                        val r = Api.call("photo_add", p)
                        toast(ctx, r.optString("message", "Photo saved"))
                        bump++
                    }
                } catch (e: Exception) {
                    toast(ctx, e.message)
                } finally {
                    f.delete()
                    busy = false
                }
            }
        }
    }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(title, maxLines = 2) },
        confirmButton = { TextButton(onClick = onClose) { Text("Close") } },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(enabled = !busy, onClick = { checkIn("arrive") }, modifier = Modifier.weight(1f)) { Text("I've arrived") }
                    OutlinedButton(enabled = !busy, onClick = { checkIn("done") }, modifier = Modifier.weight(1f)) { Text("Job finished") }
                }
                OutlinedButton(enabled = !busy, modifier = Modifier.fillMaxWidth(), onClick = {
                    val f = File(ctx.cacheDir, "visit/" + System.currentTimeMillis() + ".jpg")
                    f.parentFile?.mkdirs()
                    camFile = f
                    try {
                        camLauncher.launch(FileProvider.getUriForFile(ctx, ctx.packageName + ".files", f))
                    } catch (e: Exception) {
                        toast(ctx, "No camera app found")
                    }
                }) { Text("Take a photo") }
                if (busy) Text("Working…", style = MaterialTheme.typography.bodySmall)

                Remote(listOf(id, bump), Modifier.fillMaxWidth().heightIn(min = 60.dp), load = {
                    Api.call("visit_info", mapOf("entity" to entity, "id" to id.toString()))
                }) { j ->
                    val photos = j.optJSONArray("photos").list()
                    val checks = j.optJSONArray("checkins").list()
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Photos (${photos.size})", style = MaterialTheme.typography.titleSmall)
                        if (photos.isEmpty()) Text("No photos yet", style = MaterialTheme.typography.bodySmall)
                        else LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            items(photos) { p -> Thumb(p, entity, id) { viewing = p } }
                        }
                        Spacer(Modifier.height(4.dp))
                        Text("Check-ins", style = MaterialTheme.typography.titleSmall)
                        if (checks.isEmpty()) Text("Nobody has checked in yet", style = MaterialTheme.typography.bodySmall)
                        checks.forEach { c ->
                            Card(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(10.dp)) {
                                    Text(
                                        (if (c.optString("kind") == "arrive") "Arrived" else "Finished") + " · " + c.optString("by"),
                                        fontWeight = FontWeight.SemiBold
                                    )
                                    Text(c.optString("time"), style = MaterialTheme.typography.bodySmall)
                                    if (!c.isNull("distance_m")) {
                                        Text(c.optInt("distance_m").toString() + " m from the customer's location", style = MaterialTheme.typography.bodySmall)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    )
    viewing?.let { PhotoViewer(it) { viewing = null } }
}
