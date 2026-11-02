package com.duntech.nuxbill

import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.json.JSONObject

private val Good = Color(0xFF16A34A)
private val Bad = Color(0xFFDC2626)

@Composable
fun NuxTheme(content: @Composable () -> Unit) {
    val scheme = if (isSystemInDarkTheme()) {
        darkColorScheme(primary = Color(0xFF5EEAD4), onPrimary = Color(0xFF042F2E))
    } else {
        lightColorScheme(primary = Color(0xFF0F766E))
    }
    MaterialTheme(colorScheme = scheme, content = content)
}

fun toast(ctx: android.content.Context, msg: String?) {
    Toast.makeText(ctx, msg ?: "Something went wrong", Toast.LENGTH_LONG).show()
}

/** Loads data, shows a spinner or an error with Retry, then hands the result to [content]. */
@Composable
fun <T> Remote(
    key: Any?,
    modifier: Modifier = Modifier.fillMaxSize(),
    load: suspend () -> T,
    content: @Composable (T) -> Unit
) {
    var state by remember(key) { mutableStateOf<Result<T>?>(null) }
    var tick by remember { mutableIntStateOf(0) }
    LaunchedEffect(key, tick) {
        state = null
        state = runCatching { load() }
    }
    val s = state
    if (s == null) {
        Box(modifier, contentAlignment = Alignment.Center) { CircularProgressIndicator() }
    } else if (s.isFailure) {
        Column(
            modifier.padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                s.exceptionOrNull()?.message ?: "Failed to load",
                color = MaterialTheme.colorScheme.error
            )
            Spacer(Modifier.height(12.dp))
            Button(onClick = { tick++ }) { Text("Retry") }
        }
    } else {
        content(s.getOrThrow())
    }
}

@Composable
fun StatCard(label: String, value: String, tone: String, modifier: Modifier = Modifier) {
    val c = when (tone) {
        "good" -> Good
        "bad" -> Bad
        else -> MaterialTheme.colorScheme.primary
    }
    Card(modifier, colors = CardDefaults.cardColors(containerColor = c.copy(alpha = 0.12f))) {
        Column(Modifier.padding(16.dp)) {
            Text(label, style = MaterialTheme.typography.labelMedium)
            Spacer(Modifier.height(6.dp))
            Text(
                value,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                color = c
            )
        }
    }
}

@Composable
fun TxRow(t: JSONObject) {
    Card(Modifier.fillMaxWidth()) {
        Row(
            Modifier.padding(12.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(t.optString("plan"), fontWeight = FontWeight.SemiBold)
                Text(
                    listOf(t.optString("user"), t.optString("date"), t.optString("method"))
                        .filter { it.isNotBlank() }.joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall
                )
            }
            Text(
                t.optString("price"),
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.primary
            )
        }
    }
}

@Composable
fun KeyValue(k: String, v: String) {
    if (v.isBlank()) return
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(k, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(v, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
    }
}

@Composable
fun currentContext() = LocalContext.current
