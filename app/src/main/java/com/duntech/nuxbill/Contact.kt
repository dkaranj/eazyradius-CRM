package com.duntech.nuxbill

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp

/** Phone number in the form WhatsApp wants: digits only, with the country code (Kenya assumed for local numbers). */
fun waNumber(phone: String): String {
    var d = phone.filter { it.isDigit() }
    if (d.startsWith("00")) d = d.drop(2)
    else if (d.startsWith("0")) d = "254" + d.drop(1)
    else if (d.length == 9 && (d.startsWith("7") || d.startsWith("1"))) d = "254$d"
    return d
}

private fun open(ctx: Context, i: Intent, none: String) {
    try {
        ctx.startActivity(i)
    } catch (e: Exception) {
        toast(ctx, none)
    }
}

@Composable
fun ContactRow(phone: String, message: String = "") {
    if (phone.isBlank()) return
    val ctx = LocalContext.current
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedButton(onClick = { open(ctx, Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + phone)), "No phone app found") }) { Text("Call") }
        OutlinedButton(onClick = {
            val url = "https://wa.me/" + waNumber(phone) + if (message.isNotBlank()) "?text=" + Uri.encode(message) else ""
            open(ctx, Intent(Intent.ACTION_VIEW, Uri.parse(url)), "WhatsApp is not installed")
        }) { Text("WhatsApp") }
        OutlinedButton(onClick = {
            val i = Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:" + phone))
            if (message.isNotBlank()) i.putExtra("sms_body", message)
            open(ctx, i, "No messaging app found")
        }) { Text("SMS") }
    }
}
