package com.duntech.nuxbill

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(onLoggedIn: (Auth) -> Unit) {
    var url by rememberSaveable { mutableStateOf(Store.lastUrl()) }
    var user by rememberSaveable { mutableStateOf("") }
    var pass by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var showTheme by remember { mutableStateOf(false) }
    var showCoverage by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()

    fun submit() {
        if (busy) return
        var base = url.trim().trimEnd('/')
        if (base.isEmpty() || user.isBlank() || pass.isEmpty()) {
            error = "Fill in the server address, username and password"
            return
        }
        if (!base.startsWith("http://") && !base.startsWith("https://")) base = "https://" + base
        busy = true
        error = ""
        scope.launch {
            try {
                Api.base = base
                Api.token = ""
                val r = Api.call("login", mapOf("username" to user.trim(), "password" to pass, "device" to deviceName()))
                onLoggedIn(
                    Auth(base, r.getString("token"), r.getString("role"), r.optString("name"), r.optString("user_type"))
                        .withAccess(r.optJSONObject("access"))
                )
            } catch (e: Exception) {
                error = e.message ?: "Login failed"
            } finally {
                busy = false
            }
        }
    }

    val primary = MaterialTheme.colorScheme.primary
    var showPass by remember { mutableStateOf(false) }
    var editServer by remember { mutableStateOf(url.isBlank()) }
    val fieldShape = RoundedCornerShape(14.dp)

    Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        // coloured top with the logo
        Box(
            Modifier.fillMaxWidth().fillMaxHeight(0.42f).background(
                Brush.verticalGradient(listOf(primary, primary.copy(alpha = 0.78f)))
            ).clip(RoundedCornerShape(bottomStart = 36.dp, bottomEnd = 36.dp))
        )
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(horizontal = 22.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Spacer(Modifier.height(56.dp))
            Image(painterResource(R.drawable.ic_logo), "Eazyradius", Modifier.size(84.dp).shadow(10.dp, RoundedCornerShape(22.dp)))
            Spacer(Modifier.height(12.dp))
            Text("Eazyradius CRM", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimary)
            Text("Sign in to continue", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimary.copy(alpha = 0.85f))
            Spacer(Modifier.height(28.dp))
            Card(
                Modifier.fillMaxWidth(), shape = RoundedCornerShape(26.dp),
                elevation = CardDefaults.cardElevation(defaultElevation = 8.dp),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
            ) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Welcome back", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    OutlinedTextField(
                        value = user, onValueChange = { user = it }, singleLine = true, shape = fieldShape,
                        label = { Text("Username") }, leadingIcon = { Icon(Icons.Filled.Person, null) },
                        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next, autoCorrect = false),
                        modifier = Modifier.fillMaxWidth()
                    )
                    OutlinedTextField(
                        value = pass, onValueChange = { pass = it }, singleLine = true, shape = fieldShape,
                        label = { Text("Password") }, leadingIcon = { Icon(Icons.Filled.Lock, null) },
                        trailingIcon = { TextButton(onClick = { showPass = !showPass }) { Text(if (showPass) "Hide" else "Show") } },
                        visualTransformation = if (showPass) VisualTransformation.None else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                        keyboardActions = KeyboardActions(onDone = { submit() }),
                        modifier = Modifier.fillMaxWidth()
                    )
                    if (editServer) {
                        OutlinedTextField(
                            value = url, onValueChange = { url = it }, singleLine = true, shape = fieldShape,
                            label = { Text("Server address") }, placeholder = { Text("billing.example.com") },
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Next, autoCorrect = false),
                            modifier = Modifier.fillMaxWidth()
                        )
                    } else {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Text("Server: " + url.removePrefix("https://").removePrefix("http://").trimEnd('/'), Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                            TextButton(onClick = { editServer = true }) { Text("Change") }
                        }
                    }
                    if (error.isNotEmpty()) Text(error, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                    Button(onClick = { submit() }, enabled = !busy, shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().height(52.dp)) {
                        if (busy) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                        else Text("Sign in", fontWeight = FontWeight.Bold)
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.Center) {
                TextButton(onClick = {
                    var b = url.trim().trimEnd('/')
                    if (b.isEmpty()) { error = "Enter the server address first"; editServer = true; return@TextButton }
                    if (!b.startsWith("http://") && !b.startsWith("https://")) b = "https://" + b
                    Api.base = b
                    Api.token = ""
                    showCoverage = true
                }) { Text("Do you cover my area?") }
                TextButton(onClick = { showTheme = true }) { Text("Theme") }
            }
            Spacer(Modifier.height(20.dp))
        }
    }
    if (showCoverage) PublicCoverageDialog(url) { showCoverage = false }
    if (showTheme) ThemeDialog { showTheme = false }
}
