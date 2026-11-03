package com.duntech.nuxbill

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
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
        Api.modules = auth?.modules ?: emptyList()
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
        // Pick up changes the admin made on the Access screen without needing a fresh login.
        LaunchedEffect(a.token) {
            try {
                val r = Api.call("me")
                val updated = a.withAccess(r.optJSONObject("access"), r.optString("user_type"))
                if (updated != a) {
                    Store.save(updated)
                    auth = updated
                }
            } catch (e: Exception) {
            }
        }
        RoleShell(
            a,
            onAuth = { updated ->
                Store.save(updated)
                auth = updated
            },
            onLogout = {
                Store.clear()
                applyAuth(null)
                auth = null
            }
        )
    }
}

private data class NavTab(val key: String, val title: String)

private fun staffTabs(a: Auth): List<NavTab> {
    val m = a.modules
    val out = ArrayList<NavTab>()
    if ("dashboard" in m) out.add(NavTab("dashboard", "Dashboard"))
    else if (a.isTech) out.add(NavTab("home", "Home"))
    if ("leads" in m) out.add(NavTab("leads", "Leads"))
    if ("installations" in m) out.add(NavTab("installations", "Installs"))
    if ("support" in m) out.add(NavTab("support", "Support"))
    if ("customers" in m) out.add(NavTab("customers", "Customers"))
    if ("payments" in m) out.add(NavTab("payments", "Payments"))
    if ("my_hr" in m) out.add(NavTab("my_hr", "My HR"))
    if ("hr_employees" in m) out.add(NavTab("hr_employees", "Employees"))
    if ("hr_attendance" in m) out.add(NavTab("hr_attendance", "Attendance"))
    if ("hr_leave" in m) out.add(NavTab("hr_leave", "Leave"))
    if ("hr_payroll" in m) out.add(NavTab("hr_payroll", "Payroll"))
    if ("users" in m) out.add(NavTab("users", "Users"))
    if (a.isSuper) out.add(NavTab("access", "Access"))
    if (out.isEmpty()) out.add(NavTab("my_hr", "My HR"))
    return out
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RoleShell(auth: Auth, onAuth: (Auth) -> Unit, onLogout: () -> Unit) {
    val isClient = auth.role == "client"
    val tabs = if (isClient) {
        listOf(NavTab("c_home", "Home"), NavTab("c_plans", "Plans"), NavTab("c_history", "History"))
    } else {
        staffTabs(auth)
    }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var reload by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val idx = tab.coerceIn(0, tabs.lastIndex)

    val roleLabel = when {
        isClient -> "Client"
        auth.isSuper -> "SuperAdmin"
        auth.userType.isNotBlank() -> auth.userType
        else -> "Staff"
    }

    Scaffold(
        topBar = {
            Column {
                TopAppBar(
                    title = { Text(roleLabel + if (auth.name.isNotBlank()) " · " + auth.name else "") },
                    actions = {
                        TextButton(onClick = {
                            scope.launch {
                                if (!isClient) {
                                    try {
                                        val r = Api.call("me")
                                        onAuth(auth.withAccess(r.optJSONObject("access"), r.optString("user_type")))
                                    } catch (e: Exception) {
                                    }
                                }
                                reload++
                            }
                        }) { Text("Refresh") }
                        TextButton(onClick = {
                            scope.launch {
                                try { Api.call("logout") } catch (e: Exception) { }
                                onLogout()
                            }
                        }) { Text("Logout") }
                    }
                )
                if (!isClient && tabs.size > 1) {
                    ScrollableTabRow(selectedTabIndex = idx, edgePadding = 8.dp) {
                        tabs.forEachIndexed { i, t ->
                            Tab(selected = idx == i, onClick = { tab = i }, text = { Text(t.title) })
                        }
                    }
                }
            }
        },
        bottomBar = {
            if (isClient) {
                val icons = listOf(Icons.Default.Home, Icons.Default.ShoppingCart, Icons.Default.List)
                NavigationBar {
                    tabs.forEachIndexed { i, t ->
                        NavigationBarItem(
                            selected = idx == i,
                            onClick = { tab = i },
                            icon = { Icon(icons[i], contentDescription = t.title) },
                            label = { Text(t.title) }
                        )
                    }
                }
            }
        }
    ) { pad ->
        Box(Modifier.padding(pad).fillMaxSize()) {
            when (tabs[idx].key) {
                "c_home" -> ClientHome(reload)
                "c_plans" -> ClientPlans(reload)
                "c_history" -> ClientHistory(reload)
                "home" -> TechHome(reload)
                "dashboard" -> AdminDashboard(reload)
                "leads" -> OpsList("lead", reload)
                "installations" -> OpsList("install", reload)
                "support" -> OpsList("ticket", reload)
                "customers" -> AdminCustomers(reload)
                "payments" -> AdminPayments(reload)
                "my_hr" -> MyHr(reload)
                "hr_employees" -> HrEmployees(reload)
                "hr_attendance" -> HrAttendance(reload)
                "hr_leave" -> HrLeave(reload)
                "hr_payroll" -> HrPayroll(reload)
                "users" -> AdminUsers(reload)
                "access" -> AdminAccess(reload)
            }
        }
    }
}
