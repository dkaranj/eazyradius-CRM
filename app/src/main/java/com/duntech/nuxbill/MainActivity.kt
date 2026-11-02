package com.duntech.nuxbill

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Store.init(this)
        setContent {
            NuxTheme {
                Surface(Modifier.fillMaxSize()) { App() }
            }
        }
    }
}

private fun applyAuth(a: Auth?) {
    if (a != null) {
        Api.base = a.base
        Api.token = a.token
    } else {
        Api.token = ""
    }
}

@Composable
fun App() {
    var auth by remember { mutableStateOf(Store.load().also { applyAuth(it) }) }

    SideEffect {
        Api.onUnauthorized = {
            Store.clear()
            applyAuth(null)
            auth = null
        }
    }

    val a = auth
    if (a == null) {
        LoginScreen { logged ->
            Store.save(logged)
            applyAuth(logged)
            auth = logged
        }
    } else {
        RoleShell(a) {
            Store.clear()
            applyAuth(null)
            auth = null
        }
    }
}

private data class Tab(val title: String, val icon: ImageVector)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RoleShell(auth: Auth, onLogout: () -> Unit) {
    val tabs = when (auth.role) {
        "admin" -> {
            val base = listOf(
                Tab("Dashboard", Icons.Default.Home), Tab("Customers", Icons.Default.Person),
                Tab("Work", Icons.Default.Build), Tab("Payments", Icons.Default.List)
            )
            if (auth.userType.lowercase() in listOf("superadmin", "admin")) base + Tab("Users", Icons.Default.Settings) else base
        }
        "technician" -> listOf(Tab("Home", Icons.Default.Home), Tab("Work", Icons.Default.Build))
        else -> listOf(Tab("Home", Icons.Default.Home), Tab("Plans", Icons.Default.ShoppingCart), Tab("History", Icons.Default.List))
    }
    val roleLabel = when (auth.role) {
        "admin" -> "Admin"
        "technician" -> "Technician"
        else -> "Client"
    }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var reload by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(roleLabel + if (auth.name.isNotBlank()) " · " + auth.name else "") },
                actions = {
                    TextButton(onClick = { reload++ }) { Text("Refresh") }
                    TextButton(onClick = {
                        scope.launch {
                            try { Api.call("logout") } catch (e: Exception) { }
                            onLogout()
                        }
                    }) { Text("Logout") }
                }
            )
        },
        bottomBar = {
            if (tabs.size > 1) {
                NavigationBar {
                    tabs.forEachIndexed { i, t ->
                        NavigationBarItem(
                            selected = tab == i,
                            onClick = { tab = i },
                            icon = { Icon(t.icon, contentDescription = t.title) },
                            label = { Text(t.title) }
                        )
                    }
                }
            }
        }
    ) { pad ->
        Box(Modifier.padding(pad).fillMaxSize()) {
            when (auth.role) {
                "admin" -> when (tabs.getOrNull(tab)?.title) {
                    "Customers" -> AdminCustomers(reload)
                    "Work" -> OpsWork(reload)
                    "Payments" -> AdminPayments(reload)
                    "Users" -> AdminUsers(reload)
                    else -> AdminDashboard(reload)
                }
                "technician" -> when (tabs.getOrNull(tab)?.title) {
                    "Work" -> OpsWork(reload)
                    else -> TechHome(reload)
                }
                else -> when (tab) {
                    0 -> ClientHome(reload)
                    1 -> ClientPlans(reload)
                    else -> ClientHistory(reload)
                }
            }
        }
    }
}
