package com.duntech.nuxbill

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Slider
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties

private val ColorRows = listOf(
    "accent" to "Accent (buttons, highlights)",
    "header" to "Header",
    "headerText" to "Header text",
    "footer" to "Footer",
    "footerText" to "Footer text",
    "background" to "Screen background",
    "card" to "Grid cards",
    "text" to "Text"
)

private val QuickColors = listOf(
    0xFF0F766E, 0xFF1D4ED8, 0xFF15803D, 0xFFC2410C, 0xFF6D28D9, 0xFFBE185D,
    0xFF334155, 0xFFA16207, 0xFFDC2626, 0xFF0891B2, 0xFF000000, 0xFFFFFFFF
)

private fun resolvedColor(r: ResolvedTheme, key: String): Color = when (key) {
    "accent" -> r.accent
    "header" -> r.header
    "headerText" -> r.headerText
    "footer" -> r.footer
    "footerText" -> r.footerText
    "background" -> r.background
    "card" -> r.card
    else -> r.text
}

/** Full-screen theme editor. Every change applies to the whole app straight away and is saved on this phone. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ThemeDialog(onClose: () -> Unit) {
    val cfg = ThemeState.config
    val r = resolveTheme(cfg, isSystemInDarkTheme())
    var picking by remember { mutableStateOf<String?>(null) }

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize()) {
                Row(
                    Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 8.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text("Theme", style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
                    TextButton(onClick = onClose) { Text("Done") }
                }
                Column(
                    Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp)
                ) {
                    ThemePreview(r)

                    SectionTitle("Mode")
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("system" to "Follow phone", "light" to "Light", "dark" to "Dark").forEach { (m, label) ->
                            FilterChip(
                                selected = cfg.mode == m,
                                onClick = { ThemeState.edit { it.copy(mode = m) } },
                                label = { Text(label) }
                            )
                        }
                    }

                    SectionTitle("Presets")
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(ThemePresets) { p ->
                            FilterChip(
                                selected = cfg.preset == p.id,
                                onClick = { ThemeState.edit { it.withPreset(p.id) } },
                                leadingIcon = {
                                    Box(
                                        Modifier.size(16.dp).clip(CircleShape)
                                            .background(Color(if (r.dark) p.accentDark else p.accentLight))
                                    )
                                },
                                label = { Text(p.name) }
                            )
                        }
                    }
                    Text(
                        "Picking a preset starts a fresh look. Then change any part below.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    SectionTitle("Colours")
                    ColorRows.forEach { (key, label) ->
                        ColorRow(label, resolvedColor(r, key), cfg.colorOf(key) == null) { picking = key }
                    }

                    SectionTitle("Shape and size")
                    Text("Corner roundness: ${cfg.radius}", style = MaterialTheme.typography.bodyMedium)
                    Slider(
                        value = cfg.radius.toFloat(),
                        onValueChange = { v -> ThemeState.edit { it.copy(radius = v.toInt()) } },
                        valueRange = 0f..28f
                    )
                    Text("Text size: ${(cfg.textScale * 100).toInt()}%", style = MaterialTheme.typography.bodyMedium)
                    Slider(
                        value = cfg.textScale,
                        onValueChange = { v -> ThemeState.edit { it.copy(textScale = (v * 20).toInt() / 20f) } },
                        valueRange = 0.85f..1.3f
                    )

                    val weak = listOf(
                        "Header text" to contrastRatio(r.header, r.headerText),
                        "Footer text" to contrastRatio(r.footer, r.footerText),
                        "Text on the screen" to contrastRatio(r.background, r.text),
                        "Text on cards" to contrastRatio(r.card, r.text),
                        "Button text" to contrastRatio(r.accent, r.onAccent)
                    ).filter { it.second < 3f }
                    if (weak.isNotEmpty()) {
                        Text(
                            "Hard to read: " + weak.joinToString(", ") { it.first } +
                                ". Pick colours that differ more, or set them back to Auto.",
                            color = MaterialTheme.colorScheme.error,
                            style = MaterialTheme.typography.bodyMedium
                        )
                    }

                    OutlinedButton(onClick = { ThemeState.reset() }, modifier = Modifier.fillMaxWidth()) {
                        Text("Reset everything to default")
                    }
                    Spacer(Modifier.height(24.dp))
                }
            }
        }
    }

    picking?.let { key ->
        ColorPickerDialog(
            title = ColorRows.first { it.first == key }.second,
            initial = resolvedColor(r, key),
            onPick = { v ->
                ThemeState.edit { it.withColor(key, v) }
                picking = null
            },
            onClose = { picking = null }
        )
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.titleSmall,
        fontWeight = FontWeight.Bold,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(top = 6.dp)
    )
}

@Composable
private fun ColorRow(label: String, color: Color, auto: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(
            Modifier.size(30.dp).clip(CircleShape).background(color)
                .border(1.dp, MaterialTheme.colorScheme.outline, CircleShape)
        )
        Spacer(Modifier.width(12.dp))
        Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
        Text(
            if (auto) "Auto" else hexOf(color),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}

/** A small mock-up that is drawn from the resolved colours, so it shows the header, grid cards and footer together. */
@Composable
private fun ThemePreview(r: ResolvedTheme) {
    val shape = RoundedCornerShape(r.radius.dp)
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(r.background)
            .border(1.dp, r.text.copy(alpha = 0.25f), RoundedCornerShape(12.dp))
    ) {
        Box(Modifier.fillMaxWidth().background(r.header).padding(14.dp)) {
            Text("Eazyradius CRM", color = r.headerText, fontWeight = FontWeight.Bold)
        }
        Row(
            Modifier.fillMaxWidth().padding(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            listOf("Income today" to "12,400", "Open leads" to "14").forEach { (label, value) ->
                Column(Modifier.weight(1f).clip(shape).background(r.card).padding(12.dp)) {
                    Text(label, color = r.muted, style = MaterialTheme.typography.labelMedium)
                    Text(value, color = r.accent, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleLarge)
                }
            }
        }
        Row(Modifier.padding(start = 12.dp, end = 12.dp, bottom = 12.dp)) {
            Box(
                Modifier.clip(RoundedCornerShape(50)).background(r.accent).padding(horizontal = 18.dp, vertical = 8.dp)
            ) { Text("Button", color = r.onAccent, style = MaterialTheme.typography.labelLarge) }
            Spacer(Modifier.width(12.dp))
            Text("Body text", color = r.text, modifier = Modifier.align(Alignment.CenterVertically))
        }
        Row(
            Modifier.fillMaxWidth().background(r.footer).padding(vertical = 10.dp),
            horizontalArrangement = Arrangement.SpaceEvenly
        ) {
            listOf("Home", "Plans", "History").forEach { Text(it, color = r.footerText, style = MaterialTheme.typography.labelMedium) }
        }
    }
}

private fun parseHex(s: String): Color? {
    val t = s.trim().removePrefix("#")
    if (t.length != 6 || t.any { it !in "0123456789abcdefABCDEF" }) return null
    return Color(0xFF000000.toInt() or t.toInt(16))
}

/** Pick a colour with hue/saturation/brightness sliders, a hex code or a quick swatch; or go back to Auto. */
@Composable
private fun ColorPickerDialog(title: String, initial: Color, onPick: (Int?) -> Unit, onClose: () -> Unit) {
    val hsv = remember {
        val a = FloatArray(3)
        android.graphics.Color.colorToHSV(initial.toArgb(), a)
        a
    }
    var hue by remember { mutableFloatStateOf(hsv[0]) }
    var sat by remember { mutableFloatStateOf(hsv[1]) }
    var value by remember { mutableFloatStateOf(hsv[2]) }
    var hex by remember { mutableStateOf(hexOf(initial)) }

    fun current(): Color = Color(android.graphics.Color.HSVToColor(floatArrayOf(hue, sat, value)))
    fun setFrom(c: Color) {
        val a = FloatArray(3)
        android.graphics.Color.colorToHSV(c.toArgb(), a)
        hue = a[0]; sat = a[1]; value = a[2]
        hex = hexOf(c)
    }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(title) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Box(
                    Modifier.fillMaxWidth().height(48.dp).clip(RoundedCornerShape(10.dp)).background(current())
                        .border(1.dp, MaterialTheme.colorScheme.outline, RoundedCornerShape(10.dp))
                )
                Text("Shade", style = MaterialTheme.typography.labelMedium)
                Slider(value = hue, onValueChange = { hue = it; hex = hexOf(current()) }, valueRange = 0f..360f)
                Text("Strength", style = MaterialTheme.typography.labelMedium)
                Slider(value = sat, onValueChange = { sat = it; hex = hexOf(current()) }, valueRange = 0f..1f)
                Text("Brightness", style = MaterialTheme.typography.labelMedium)
                Slider(value = value, onValueChange = { value = it; hex = hexOf(current()) }, valueRange = 0f..1f)
                OutlinedTextField(
                    value = hex,
                    onValueChange = { t ->
                        hex = t
                        parseHex(t)?.let { c ->
                            val a = FloatArray(3)
                            android.graphics.Color.colorToHSV(c.toArgb(), a)
                            hue = a[0]; sat = a[1]; value = a[2]
                        }
                    },
                    label = { Text("Hex code") },
                    singleLine = true,
                    isError = parseHex(hex) == null,
                    modifier = Modifier.fillMaxWidth()
                )
                QuickColors.chunked(6).forEach { rowColors ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        rowColors.forEach { v ->
                            val c = Color(v)
                            Box(
                                Modifier.size(34.dp).clip(CircleShape).background(c)
                                    .border(1.dp, MaterialTheme.colorScheme.outline, CircleShape)
                                    .clickable { setFrom(c) }
                            )
                        }
                    }
                }
                TextButton(onClick = { onPick(null) }) { Text("Set to Auto") }
            }
        },
        confirmButton = { Button(onClick = { onPick(current().toArgb()) }) { Text("Apply") } },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
