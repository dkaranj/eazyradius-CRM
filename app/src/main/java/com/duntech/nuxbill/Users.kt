package com.duntech.nuxbill

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

@Composable
fun AdminUsers(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var adding by remember { mutableStateOf(false) }

    Remote(listOf(reload, bump), load = { Api.call("admin_users") }) { j ->
        val users = j.optJSONArray("users").list()
        val types = j.optJSONArray("roles").strings()
        val staffRoles = j.optJSONArray("staff_roles").list()

        Column(Modifier.fillMaxSize()) {
            Button(
                onClick = { adding = true },
                modifier = Modifier.fillMaxWidth().padding(12.dp)
            ) { Text("Add user") }
            LazyVerticalGrid(
                GridCells.Adaptive(300.dp),
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = 12.dp, end = 12.dp, bottom = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                items(users) { u ->
                    UserCard(u) { if (u.optBoolean("can_edit")) editing = u }
                }
            }
        }

        if (adding) {
            UserDialog(null, types, staffRoles, onClose = { adding = false }) {
                adding = false
                bump++
            }
        }
        editing?.let { u ->
            UserDialog(u, types, staffRoles, onClose = { editing = null }) {
                editing = null
                bump++
            }
        }
    }
}

@Composable
private fun UserCard(u: JSONObject, onClick: () -> Unit) {
    val active = u.optString("status") == "Active"
    val roleNames = u.optJSONArray("staff_role_names").strings()
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Column(Modifier.padding(14.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(
                    u.optString("fullname").ifBlank { u.optString("username") } + if (u.optBoolean("self")) " (you)" else "",
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f)
                )
                Text(
                    u.optString("role"),
                    color = MaterialTheme.colorScheme.primary,
                    style = MaterialTheme.typography.labelMedium
                )
            }
            Text(u.optString("username"), style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(6.dp))
            KeyValue("Staff roles", roleNames.joinToString(", "))
            KeyValue("Technician", if (u.optBoolean("is_tech")) "Yes" else "")
            KeyValue("Phone", u.optString("phone"))
            KeyValue("Email", u.optString("email"))
            KeyValue("Last login", u.optString("last_login"))
            Text(
                if (active) "Active" else "Disabled",
                color = if (active) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.labelMedium
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun UserDialog(
    user: JSONObject?,
    types: List<String>,
    staffRoles: List<JSONObject>,
    onClose: () -> Unit,
    onDone: () -> Unit
) {
    val isNew = user == null
    val self = user?.optBoolean("self") ?: false
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()

    var fullname by remember { mutableStateOf(user?.optString("fullname") ?: "") }
    var username by remember { mutableStateOf(user?.optString("username") ?: "") }
    var phone by remember { mutableStateOf(user?.optString("phone") ?: "") }
    var email by remember { mutableStateOf(user?.optString("email") ?: "") }
    var password by remember { mutableStateOf("") }
    var type by remember { mutableStateOf(user?.optString("role") ?: types.lastOrNull() ?: "") }
    var isTech by remember { mutableStateOf(user?.optBoolean("is_tech") ?: false) }
    var roleIds by remember {
        mutableStateOf(user?.optJSONArray("staff_role_ids")?.let { a -> (0 until a.length()).map { a.getInt(it) }.toSet() } ?: emptySet())
    }
    var active by remember { mutableStateOf((user?.optString("status") ?: "Active") == "Active") }
    var confirmDelete by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    val typeChips = (types + listOfNotNull(user?.optString("role"))).filter { it.isNotBlank() }.distinct()

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(if (isNew) "Add user" else "Edit user") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(fullname, { fullname = it }, label = { Text("Full name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    username, { username = it }, label = { Text("Username") }, singleLine = true,
                    enabled = isNew, modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    phone, { phone = it }, label = { Text("Phone") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    email, { email = it }, label = { Text("Email") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    password, { password = it }, singleLine = true,
                    label = { Text(if (isNew) "Password (min 6)" else "New password (leave blank to keep)") },
                    visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth()
                )

                Text("Account type", style = MaterialTheme.typography.labelLarge)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(typeChips) { r ->
                        FilterChip(
                            selected = type == r,
                            onClick = { if (!self) type = r },
                            enabled = !self,
                            label = { Text(r) }
                        )
                    }
                }

                Text("Staff roles (decide what they see in the app)", style = MaterialTheme.typography.labelLarge)
                if (staffRoles.isEmpty()) {
                    Text("No staff roles exist yet. Create them in the web panel.", style = MaterialTheme.typography.bodySmall)
                } else {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(staffRoles) { r ->
                            val id = r.optInt("id")
                            FilterChip(
                                selected = id in roleIds,
                                onClick = { roleIds = if (id in roleIds) roleIds - id else roleIds + id },
                                label = { Text(r.optString("name")) }
                            )
                        }
                    }
                }

                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Technician (can be assigned work)", Modifier.weight(1f))
                    Switch(checked = isTech, onCheckedChange = { isTech = it })
                }
                if (!self) {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                        Text(if (active) "Account active" else "Account disabled")
                        Switch(checked = active, onCheckedChange = { active = it })
                    }
                }
                if (!isNew && !self) {
                    OutlinedButton(
                        onClick = {
                            scope.launch {
                                try {
                                    toast(ctx, Api.call("admin_user_logout", mapOf("id" to user!!.optInt("id").toString())).optString("message"))
                                } catch (e: Exception) {
                                    toast(ctx, e.message)
                                }
                            }
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) { Text("Log out of all devices (lost phone)") }
                    OutlinedButton(
                        onClick = {
                            if (!confirmDelete) {
                                confirmDelete = true
                            } else {
                                scope.launch {
                                    try {
                                        Api.call("admin_user_delete", mapOf("id" to user!!.optInt("id").toString()))
                                        toast(ctx, "User deleted")
                                        onDone()
                                    } catch (e: Exception) {
                                        toast(ctx, e.message)
                                    }
                                }
                            }
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text(if (confirmDelete) "Tap again to delete permanently" else "Delete user", color = MaterialTheme.colorScheme.error)
                    }
                }
            }
        },
        confirmButton = {
            Button(
                enabled = !busy,
                onClick = {
                    busy = true
                    scope.launch {
                        try {
                            val params = mutableMapOf(
                                "id" to (user?.optInt("id") ?: 0).toString(),
                                "fullname" to fullname.trim(),
                                "username" to username.trim(),
                                "phone" to phone.trim(),
                                "email" to email.trim(),
                                "password" to password,
                                "role" to type,
                                "is_tech" to if (isTech) "1" else "0",
                                "staff_roles" to roleIds.joinToString(","),
                                "status" to if (active) "Active" else "Inactive"
                            )
                            val r = Api.call("admin_user_save", params)
                            toast(ctx, r.optString("message", "Saved"))
                            onDone()
                        } catch (e: Exception) {
                            toast(ctx, e.message)
                        } finally {
                            busy = false
                        }
                    }
                }
            ) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}
