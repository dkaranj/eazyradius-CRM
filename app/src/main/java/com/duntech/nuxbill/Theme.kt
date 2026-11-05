package com.duntech.nuxbill

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.toArgb
import org.json.JSONObject

/**
 * One person's look for the app. Every colour is optional: null means "Auto", i.e. worked out from
 * the preset, the light/dark mode and the other colours chosen, so partial customisation always stays readable.
 */
data class ThemeConfig(
    val mode: String = "system",   // system | light | dark
    val preset: String = "teal",
    val accent: Int? = null,
    val header: Int? = null,
    val headerText: Int? = null,
    val footer: Int? = null,
    val footerText: Int? = null,
    val background: Int? = null,
    val card: Int? = null,
    val text: Int? = null,
    val radius: Int = 12,          // corner radius of cards and dialogs, dp
    val textScale: Float = 1f      // 0.85 .. 1.3
)

data class ThemePreset(
    val id: String,
    val name: String,
    val accentLight: Long,
    val accentDark: Long,
    /** true: header and footer are filled with the accent colour; false: they stay neutral. */
    val colorBars: Boolean
)

val ThemePresets = listOf(
    ThemePreset("teal", "Teal", 0xFF0F766E, 0xFF5EEAD4, false),
    ThemePreset("ocean", "Ocean", 0xFF1D4ED8, 0xFF93C5FD, true),
    ThemePreset("forest", "Forest", 0xFF15803D, 0xFF86EFAC, false),
    ThemePreset("sunset", "Sunset", 0xFFC2410C, 0xFFFDBA74, true),
    ThemePreset("royal", "Royal", 0xFF6D28D9, 0xFFC4B5FD, true),
    ThemePreset("rose", "Rose", 0xFFBE185D, 0xFFF9A8D4, false),
    ThemePreset("slate", "Slate", 0xFF334155, 0xFFCBD5E1, true),
    ThemePreset("gold", "Gold", 0xFFA16207, 0xFFFDE047, false)
)

fun presetOf(id: String): ThemePreset = ThemePresets.firstOrNull { it.id == id } ?: ThemePresets[0]

/** The theme with every Auto filled in. This is what the screens actually draw with. */
data class ResolvedTheme(
    val dark: Boolean,
    val accent: Color,
    val onAccent: Color,
    val header: Color,
    val headerText: Color,
    val footer: Color,
    val footerText: Color,
    val background: Color,
    val card: Color,
    val text: Color,
    val muted: Color,
    val radius: Int,
    val textScale: Float
)

private val InkDark = Color(0xFF1C1B1F)
private val InkLight = Color(0xFFE6E1E5)

/** Readable text colour for something drawn on this colour. */
fun Color.onTone(): Color = if (luminance() > 0.5f) InkDark else InkLight

fun contrastRatio(a: Color, b: Color): Float {
    val l1 = maxOf(a.luminance(), b.luminance())
    val l2 = minOf(a.luminance(), b.luminance())
    return (l1 + 0.05f) / (l2 + 0.05f)
}

fun resolveTheme(c: ThemeConfig, systemDark: Boolean): ResolvedTheme {
    val dark = when (c.mode) {
        "dark" -> true
        "light" -> false
        else -> systemDark
    }
    val p = presetOf(c.preset)
    val accent = c.accent?.let { Color(it) } ?: Color(if (dark) p.accentDark else p.accentLight)
    val background = c.background?.let { Color(it) } ?: if (dark) Color(0xFF1C1B1F) else Color(0xFFFFFBFE)
    val text = c.text?.let { Color(it) } ?: background.onTone()
    val card = c.card?.let { Color(it) } ?: lerp(background, background.onTone(), 0.11f)
    val header = c.header?.let { Color(it) } ?: if (p.colorBars) accent else background
    val footer = c.footer?.let { Color(it) } ?: if (p.colorBars) accent else card
    return ResolvedTheme(
        dark = dark,
        accent = accent,
        onAccent = accent.onTone(),
        header = header,
        headerText = c.headerText?.let { Color(it) } ?: header.onTone(),
        footer = footer,
        footerText = c.footerText?.let { Color(it) } ?: footer.onTone(),
        background = background,
        card = card,
        text = text,
        muted = text.copy(alpha = 0.7f),
        radius = c.radius.coerceIn(0, 28),
        textScale = c.textScale.coerceIn(0.85f, 1.3f)
    )
}

fun ThemeConfig.colorOf(key: String): Int? = when (key) {
    "accent" -> accent
    "header" -> header
    "headerText" -> headerText
    "footer" -> footer
    "footerText" -> footerText
    "background" -> background
    "card" -> card
    "text" -> text
    else -> null
}

fun ThemeConfig.withColor(key: String, v: Int?): ThemeConfig = when (key) {
    "accent" -> copy(accent = v)
    "header" -> copy(header = v)
    "headerText" -> copy(headerText = v)
    "footer" -> copy(footer = v)
    "footerText" -> copy(footerText = v)
    "background" -> copy(background = v)
    "card" -> copy(card = v)
    "text" -> copy(text = v)
    else -> this
}

/** Choosing a preset starts a fresh look: it clears the colour overrides but keeps mode, corners and text size. */
fun ThemeConfig.withPreset(id: String): ThemeConfig =
    ThemeConfig(mode = mode, preset = id, radius = radius, textScale = textScale)

private fun encode(c: ThemeConfig): String {
    val o = JSONObject()
    o.put("mode", c.mode).put("preset", c.preset).put("radius", c.radius).put("scale", c.textScale.toDouble())
    listOf("accent", "header", "headerText", "footer", "footerText", "background", "card", "text").forEach { k ->
        c.colorOf(k)?.let { o.put(k, it) }
    }
    return o.toString()
}

private fun decode(s: String?): ThemeConfig {
    if (s.isNullOrBlank()) return ThemeConfig()
    return try {
        val o = JSONObject(s)
        var c = ThemeConfig(
            mode = o.optString("mode", "system").takeIf { it in listOf("system", "light", "dark") } ?: "system",
            preset = o.optString("preset", "teal"),
            radius = o.optInt("radius", 12).coerceIn(0, 28),
            textScale = o.optDouble("scale", 1.0).toFloat().coerceIn(0.85f, 1.3f)
        )
        listOf("accent", "header", "headerText", "footer", "footerText", "background", "card", "text").forEach { k ->
            if (o.has(k)) c = c.withColor(k, o.getInt(k))
        }
        c
    } catch (e: Exception) {
        ThemeConfig()
    }
}

/** Holds the current theme (observed by Compose) and keeps it on the phone, so it survives restarts and logouts. */
object ThemeState {
    private var prefs: SharedPreferences? = null

    var config by mutableStateOf(ThemeConfig())
        private set

    fun init(ctx: Context) {
        prefs = ctx.applicationContext.getSharedPreferences("nux_theme", Context.MODE_PRIVATE)
        config = decode(prefs?.getString("cfg", null))
    }

    fun edit(change: (ThemeConfig) -> ThemeConfig) {
        config = change(config)
        prefs?.edit()?.putString("cfg", encode(config))?.apply()
    }

    fun reset() = edit { ThemeConfig() }
}

fun hexOf(c: Color): String = "#%06X".format(c.toArgb() and 0xFFFFFF)
