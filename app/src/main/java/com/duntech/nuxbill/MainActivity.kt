package com.duntech.nuxbill

import android.content.res.Configuration
import android.graphics.drawable.ColorDrawable
import android.os.Bundle
import androidx.fragment.app.FragmentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.TabRowDefaults.tabIndicatorOffset
import androidx.compose.material3.TopAppBarDefaults
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch

class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        CrashLog.install(this)
        Store.init(this)
        ThemeState.init(this)
        AppLock.init(this)
        AppLock.applySecure(this)
        Notifier.ensureChannel(this)
        Notifier.schedule(this)
        val night = (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        window.setBackgroundDrawable(ColorDrawable(resolveTheme(ThemeState.config, night).background.toArgb()))
        setContent {
            NuxTheme {
                Surface(Modifier.fillMaxSize()) {
                    Box(Modifier.fillMaxSize()) {
                        var crash by remember { mutableStateOf(CrashLog.pending(this@MainActivity)) }
                        crash?.let { trace ->
                            androidx.compose.material3.AlertDialog(
                                onDismissRequest = { CrashLog.clear(this@MainActivity); crash = null },
                                title = { Text("The app closed unexpectedly last time") },
                                text = { androidx.compose.foundation.layout.Column(Modifier.height(320.dp).verticalScroll(androidx.compose.foundation.rememberScrollState())) { Text(trace, style = MaterialTheme.typography.labelSmall) } },
                                confirmButton = {
                                    TextButton(onClick = {
                                        val cm = getSystemService(android.content.Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager
                                        cm.setPrimaryClip(android.content.ClipData.newPlainText("crash", trace))
                                    }) { Text("Copy") }
                                },
                                dismissButton = { TextButton(onClick = { CrashLog.clear(this@MainActivity); crash = null }) { Text("Close") } }
                            )
                        }
                        App()
                        if (AppLock.locked && Api.token.isNotEmpty()) {
                            LockScreen(onLogout = {
                                AppLock.locked = false
                                kotlinx.coroutines.MainScope().launch { try { Api.call("logout") } catch (e: Exception) { }; Api.onUnauthorized?.invoke() }
                            })
                        }
                    }
                }
            }
        }
    }

    override fun onStop() {
        AppLock.onStop()
        super.onStop()
    }

    override fun onStart() {
        super.onStart()
        AppLock.onStart()
    }
}

private fun applyAuth(a: Auth?) {
    if (a != null) {
        Api.base = a.base
        Api.token = a.token
        Offline.owner = a.base.trimEnd('/') + "|" + a.name
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
        val appCtx = androidx.compose.ui.platform.LocalContext.current
        val notifPerm = androidx.activity.compose.rememberLauncherForActivityResult(
            androidx.activity.result.contract.ActivityResultContracts.RequestPermission()
        ) { }
        LaunchedEffect(a.token) {
            if (android.os.Build.VERSION.SDK_INT >= 33 && !Notifier.canShow(appCtx)) {
                notifPerm.launch(android.Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        LaunchedEffect(a.token) {
            while (true) {
                try { Offline.flush() } catch (e: Exception) { }
                try { Notifier.poll(appCtx) } catch (e: Exception) { }
                kotlinx.coroutines.delay(30000)
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

/** Text button that stays readable on whatever header colour the person picked. */
@Composable
private fun HeaderButton(text: String, onClick: () -> Unit) {
    val r = LocalResolvedTheme.current
    TextButton(onClick = onClick, colors = ButtonDefaults.textButtonColors(contentColor = r.headerText)) { Text(text) }
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
    if ("install_pay" in m) out.add(NavTab("install_pay", "Install pay"))
    if ("store" in m) out.add(NavTab("store", "Store"))
    if ("customers" in m) out.add(NavTab("customers", "Customers"))
    if ("payments" in m) out.add(NavTab("payments", "Payments"))
    if ("reports" in m) out.add(NavTab("reports", "Reports"))
    if ("notices" in m) out.add(NavTab("notices", "Notices"))
    if ("activation" in m) out.add(NavTab("activation", "Activation"))
    if ("loyalty" in m) out.add(NavTab("loyalty", "Rewards"))
    if ("pause" in m) out.add(NavTab("pause", "Pauses"))
    if ("coverage" in m) out.add(NavTab("coverage", "Coverage"))
    if ("chat" in m) out.add(NavTab("chat", "Chat"))
    if ("onu" in m) out.add(NavTab("onu", "ONUs"))
    if ("netmap" in m) out.add(NavTab("netmap", "Fibre map"))
    if ("usage" in m) out.add(NavTab("usage", "Usage"))
    if ("my_hr" in m) out.add(NavTab("my_hr", "My HR"))
    if ("hr_employees" in m) out.add(NavTab("hr_employees", "Employees"))
    if ("hr_attendance" in m) out.add(NavTab("hr_attendance", "Attendance"))
    if ("hr_leave" in m) out.add(NavTab("hr_leave", "Leave"))
    if ("hr_payroll" in m) out.add(NavTab("hr_payroll", "Payroll"))
    if ("users" in m) out.add(NavTab("users", "Users"))
    if ("audit" in m) out.add(NavTab("audit", "Audit"))
    if (a.isSuper) out.add(NavTab("access", "Access"))
    if (out.isEmpty()) out.add(NavTab("my_hr", "My HR"))
    return out
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RoleShell(auth: Auth, onAuth: (Auth) -> Unit, onLogout: () -> Unit) {
    val isClient = auth.role == "client"
    val tabs = if (isClient) {
        listOf(NavTab("c_home", "Home"), NavTab("c_plans", "Plans"), NavTab("c_support", "Support"), NavTab("c_usage", "Usage"), NavTab("c_history", "History"))
    } else {
        staffTabs(auth)
    }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var reload by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val idx = tab.coerceIn(0, tabs.lastIndex)
    val r = LocalResolvedTheme.current
    var showTheme by remember { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }

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
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = r.header,
                        scrolledContainerColor = r.header,
                        titleContentColor = r.headerText,
                        actionIconContentColor = r.headerText
                    ),
                    actions = {
                        HeaderButton("Settings") { showSettings = true }
                        HeaderButton("Refresh") {
                            scope.launch {
                                if (!isClient) {
                                    try {
                                        val me = Api.call("me")
                                        onAuth(auth.withAccess(me.optJSONObject("access"), me.optString("user_type")))
                                    } catch (e: Exception) {
                                    }
                                }
                                reload++
                            }
                        }
                        HeaderButton("Logout") {
                            scope.launch {
                                try { Api.call("logout") } catch (e: Exception) { }
                                onLogout()
                            }
                        }
                    }
                )
                if (!isClient && tabs.size > 1) {
                    ScrollableTabRow(
                        selectedTabIndex = idx,
                        edgePadding = 8.dp,
                        containerColor = r.header,
                        contentColor = r.headerText,
                        indicator = { pos ->
                            Box(Modifier.tabIndicatorOffset(pos[idx]).height(3.dp).background(r.headerText))
                        }
                    ) {
                        tabs.forEachIndexed { i, t ->
                            Tab(
                                selected = idx == i,
                                onClick = { tab = i },
                                selectedContentColor = r.headerText,
                                unselectedContentColor = r.headerText.copy(alpha = 0.65f),
                                text = { Text(t.title) }
                            )
                        }
                    }
                }
            }
        },
        bottomBar = {
            if (isClient) {
                val icons = listOf(Icons.Default.Home, Icons.Default.ShoppingCart, Icons.Default.Phone, Icons.Default.Info, Icons.Default.List)
                NavigationBar(containerColor = r.footer, contentColor = r.footerText) {
                    tabs.forEachIndexed { i, t ->
                        NavigationBarItem(
                            selected = idx == i,
                            onClick = { tab = i },
                            icon = { Icon(icons[i], contentDescription = t.title) },
                            label = { Text(t.title) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = r.footerText,
                                selectedTextColor = r.footerText,
                                unselectedIconColor = r.footerText.copy(alpha = 0.65f),
                                unselectedTextColor = r.footerText.copy(alpha = 0.65f),
                                indicatorColor = r.accent.copy(alpha = 0.3f)
                            )
                        )
                    }
                }
            } else {
                Box(Modifier.fillMaxWidth().background(r.footer).height(30.dp), contentAlignment = Alignment.Center) {
                    Text(
                        "Eazyradius CRM · " + Api.base.removePrefix("https://").removePrefix("http://").trimEnd('/'),
                        color = r.footerText,
                        style = MaterialTheme.typography.labelSmall
                    )
                }
            }
        }
    ) { pad ->
        Column(Modifier.padding(pad).fillMaxSize()) {
          SyncBanner(onDone = { reload++ })
          Box(Modifier.weight(1f).fillMaxWidth()) {
            when (tabs[idx].key) {
                "c_home" -> ClientHome(reload)
                "c_plans" -> ClientPlans(reload)
                "c_support" -> ClientSupport(reload)
                "c_usage" -> ClientUsage(reload)
                "c_history" -> ClientHistory(reload)
                "home" -> TechHome(reload)
                "dashboard" -> AdminDashboard(reload)
                "leads" -> OpsList("lead", reload)
                "installations" -> OpsList("install", reload)
                "support" -> OpsList("ticket", reload)
                "store" -> StoreScreen(reload)
                "install_pay" -> InstallPayScreen(reload)
                "customers" -> AdminCustomers(reload)
                "payments" -> AdminPayments(reload)
                "reports" -> ReportsScreen(reload)
                "onu" -> OnuScreen(reload)
                "netmap" -> NetMapScreen(reload)
                "usage" -> UsageScreen(reload)
                "audit" -> AuditScreen(reload)
                "notices" -> NoticesScreen(reload)
                "activation" -> ActivationScreen(reload)
                "loyalty" -> LoyaltyScreen(reload)
                "pause" -> PausesScreen(reload)
                "coverage" -> CoverageScreen(reload)
                "chat" -> ChatInboxScreen(reload)
                "my_hr" -> MyHr(reload)
                "hr_employees" -> HrEmployees(reload)
                "hr_attendance" -> HrAttendance(reload)
                "hr_leave" -> HrLeave(reload)
                "hr_payroll" -> HrPayroll(reload)
                "users" -> AdminUsers(reload)
                "access" -> AdminAccess(reload)
            }
            if (tabs[idx].key.startsWith("c_")) ClientChatPopup()
          }
        }
    }
    if (showSettings) SettingsDialog(onTheme = { showTheme = true }, onClose = { showSettings = false })
    if (showTheme) ThemeDialog { showTheme = false }

}
