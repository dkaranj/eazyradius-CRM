package com.duntech.nuxbill

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
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.json.JSONObject

private fun gridSpec() = GridCells.Adaptive(160.dp)

@Composable
private fun statusColor(status: String): Color = when (status.lowercase()) {
    "approved", "present", "paid" -> MaterialTheme.colorScheme.primary
    "rejected", "absent", "late" -> MaterialTheme.colorScheme.error
    else -> MaterialTheme.colorScheme.onSurfaceVariant
}

private fun LazyGridScope.fullWidth(content: @Composable () -> Unit) {
    item(span = { GridItemSpan(maxLineSpan) }) { content() }
}

/* ------------------------------------------------------------------ */
/* My HR: clock in/out, leave, payslips                                 */
/* ------------------------------------------------------------------ */

@Composable
fun MyHr(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var requesting by remember { mutableStateOf(false) }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()

    Remote(listOf(reload, bump), load = { Api.call("hr_me") }) { j ->
        if (!j.optBoolean("linked")) {
            Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
                Text(
                    "Your account is not linked to an employee record yet. Ask HR to link it from the Employees tab.",
                    style = MaterialTheme.typography.bodyLarge
                )
            }
        } else {
            val emp = j.getJSONObject("employee")
            val today = j.optJSONObject("today")
            val balances = j.optJSONArray("balances").list()
            val leaves = j.optJSONArray("leaves").list()
            val slips = j.optJSONArray("payslips").list()
            val clockedIn = today != null
            val clockedOut = today != null && today.optString("clock_out").isNotBlank() && today.optString("clock_out") != today.optString("clock_in")

            fun clock(act: String) {
                scope.launch {
                    try {
                        val r = Api.call("hr_clock", mapOf("act" to act))
                        toast(ctx, r.optString("message", "Done"))
                        bump++
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    }
                }
            }

            LazyVerticalGrid(
                gridSpec(),
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                fullWidth {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Text(emp.optString("name"), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                            KeyValue("Employee no", emp.optString("no"))
                            KeyValue("Job title", emp.optString("job_title"))
                            KeyValue("Department", emp.optString("department"))
                            KeyValue("Hired", emp.optString("hire_date"))
                        }
                    }
                }
                fullWidth {
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(14.dp)) {
                            Text("Today", style = MaterialTheme.typography.titleMedium)
                            Spacer(Modifier.height(4.dp))
                            if (today == null) {
                                Text("Not clocked in yet (work starts " + j.optString("work_start") + ")")
                            } else {
                                KeyValue("Clocked in", today.optString("clock_in"))
                                KeyValue("Clocked out", if (clockedOut) today.optString("clock_out") else "")
                                KeyValue("Status", today.optString("status"))
                            }
                            Spacer(Modifier.height(8.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Button(onClick = { clock("in") }, enabled = !clockedIn) { Text("Clock in") }
                                OutlinedButton(onClick = { clock("out") }, enabled = clockedIn && !clockedOut) { Text("Clock out") }
                            }
                        }
                    }
                }
                if (balances.isNotEmpty()) {
                    fullWidth { Text("Leave balance", style = MaterialTheme.typography.titleMedium) }
                    items(balances) { b ->
                        StatCard(
                            b.optString("type") + " (" + b.optString("used") + " of " + b.optString("quota") + " used)",
                            b.optString("left") + " days left",
                            "good",
                            Modifier.fillMaxWidth()
                        )
                    }
                }
                fullWidth {
                    Button(onClick = { requesting = true }, modifier = Modifier.fillMaxWidth()) { Text("Request leave") }
                }
                if (leaves.isNotEmpty()) {
                    fullWidth { Text("My leave requests", style = MaterialTheme.typography.titleMedium) }
                    items(leaves, span = { GridItemSpan(maxLineSpan) }) { l -> LeaveCard(l, null) }
                }
                if (slips.isNotEmpty()) {
                    fullWidth { Text("Payslips", style = MaterialTheme.typography.titleMedium) }
                    items(slips, span = { GridItemSpan(maxLineSpan) }) { p ->
                        Card(Modifier.fillMaxWidth()) {
                            Row(
                                Modifier.padding(12.dp).fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text(p.optString("month"), fontWeight = FontWeight.SemiBold)
                                    Text("Gross " + p.optString("gross"), style = MaterialTheme.typography.bodySmall)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text("Net " + p.optString("net"), fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                                    Text(p.optString("status"), style = MaterialTheme.typography.labelMedium, color = statusColor(p.optString("status")))
                                }
                            }
                        }
                    }
                }
            }

            if (requesting) {
                LeaveRequestDialog(j.optJSONArray("types").strings(), onClose = { requesting = false }) {
                    requesting = false
                    bump++
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun LeaveRequestDialog(types: List<String>, onClose: () -> Unit, onDone: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf(types.firstOrNull() ?: "") }
    var start by remember { mutableStateOf("") }
    var end by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text("Request leave") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Leave type", style = MaterialTheme.typography.labelLarge)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(types) { t -> FilterChip(selected = type == t, onClick = { type = t }, label = { Text(t) }) }
                }
                OutlinedTextField(
                    start, { start = it }, singleLine = true, label = { Text("First day (YYYY-MM-DD)") },
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    end, { end = it }, singleLine = true, label = { Text("Last day (YYYY-MM-DD)") },
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(reason, { reason = it }, label = { Text("Reason (optional)") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        val r = Api.call(
                            "hr_leave_request",
                            mapOf("leave_type" to type, "start_date" to start.trim(), "end_date" to end.trim(), "reason" to reason.trim())
                        )
                        toast(ctx, r.optString("message", "Requested"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text("Submit") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

@Composable
private fun LeaveCard(l: JSONObject, onDecide: ((String) -> Unit)?) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text(
                    if (onDecide != null) l.optString("employee") else l.optString("type"),
                    fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f)
                )
                Text(l.optString("status"), style = MaterialTheme.typography.labelMedium, color = statusColor(l.optString("status")))
            }
            if (onDecide != null) KeyValue("Type", l.optString("type"))
            KeyValue("From", l.optString("start"))
            KeyValue("To", l.optString("end"))
            KeyValue("Working days", l.optString("days"))
            KeyValue("Reason", l.optString("reason"))
            KeyValue("Decided by", l.optString("decided_by"))
            KeyValue("Note", l.optString("note"))
            if (onDecide != null && l.optBoolean("can_decide")) {
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { onDecide("approve") }) { Text("Approve") }
                    OutlinedButton(onClick = { onDecide("reject") }) { Text("Reject") }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Employees                                                            */
/* ------------------------------------------------------------------ */

@Composable
fun HrEmployees(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var q by remember { mutableStateOf("") }
    var applied by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var adding by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize()) {
        Row(
            Modifier.fillMaxWidth().padding(12.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            OutlinedTextField(
                q, { q = it }, singleLine = true, label = { Text("Search employees") },
                modifier = Modifier.weight(1f)
            )
            Button(onClick = { applied = q }) { Text("Go") }
        }
        Button(onClick = { adding = true }, modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp)) { Text("Add employee") }
        Remote(listOf(reload, bump, applied), Modifier.weight(1f), load = { Api.call("hr_employees", mapOf("q" to applied)) }) { j ->
            val emps = j.optJSONArray("employees").list()
            val depts = j.optJSONArray("departments").list()
            val users = j.optJSONArray("users").list()

            LazyVerticalGrid(
                GridCells.Adaptive(300.dp),
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                items(emps) { e ->
                    Card(Modifier.fillMaxWidth().clickable { editing = e }) {
                        Column(Modifier.padding(14.dp)) {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(e.optString("name"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                Text(e.optString("status"), style = MaterialTheme.typography.labelMedium, color = statusColor(if (e.optString("status") == "Active") "present" else "absent"))
                            }
                            Text(e.optString("no"), style = MaterialTheme.typography.bodySmall)
                            Spacer(Modifier.height(6.dp))
                            KeyValue("Job title", e.optString("job_title"))
                            KeyValue("Department", e.optString("department"))
                            KeyValue("Phone", e.optString("phone"))
                            KeyValue("Type", e.optString("type"))
                            KeyValue("Hired", e.optString("hire_date"))
                            if ("hr_payroll" in Api.modules) KeyValue("Salary", e.optString("salary"))
                        }
                    }
                }
            }

            if (adding) {
                EmployeeDialog(null, depts, users, onClose = { adding = false }) {
                    adding = false
                    bump++
                }
            }
            editing?.let { e ->
                EmployeeDialog(e, depts, users, onClose = { editing = null }) {
                    editing = null
                    bump++
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EmployeeDialog(
    emp: JSONObject?,
    depts: List<JSONObject>,
    users: List<JSONObject>,
    onClose: () -> Unit,
    onDone: () -> Unit
) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var first by remember { mutableStateOf(emp?.optString("first_name") ?: "") }
    var last by remember { mutableStateOf(emp?.optString("last_name") ?: "") }
    var phone by remember { mutableStateOf(emp?.optString("phone") ?: "") }
    var email by remember { mutableStateOf(emp?.optString("email") ?: "") }
    var job by remember { mutableStateOf(emp?.optString("job_title") ?: "") }
    var hired by remember { mutableStateOf(emp?.optString("hire_date") ?: "") }
    var etype by remember { mutableStateOf(emp?.optString("type")?.ifBlank { "Full-time" } ?: "Full-time") }
    var deptId by remember { mutableIntStateOf(emp?.optInt("department_id") ?: 0) }
    var adminId by remember { mutableIntStateOf(emp?.optInt("admin_id") ?: 0) }
    var salary by remember { mutableStateOf(emp?.optString("salary_raw")?.takeIf { it != "0" && it != "0.0" } ?: "") }
    var active by remember { mutableStateOf((emp?.optString("status") ?: "Active") == "Active") }
    var busy by remember { mutableStateOf(false) }
    val canSalary = "hr_payroll" in Api.modules

    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(if (emp == null) "Add employee" else "Edit employee") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(first, { first = it }, singleLine = true, label = { Text("First name") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(last, { last = it }, singleLine = true, label = { Text("Last name") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    phone, { phone = it }, singleLine = true, label = { Text("Phone") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    email, { email = it }, singleLine = true, label = { Text("Email") },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(job, { job = it }, singleLine = true, label = { Text("Job title") }, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    hired, { hired = it }, singleLine = true, label = { Text("Hire date (YYYY-MM-DD, blank = today)") },
                    modifier = Modifier.fillMaxWidth()
                )
                if (canSalary) {
                    OutlinedTextField(
                        salary, { salary = it }, singleLine = true, label = { Text("Basic salary") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth()
                    )
                }
                Text("Employment type", style = MaterialTheme.typography.labelLarge)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(listOf("Full-time", "Part-time", "Contract", "Intern")) { t ->
                        FilterChip(selected = etype == t, onClick = { etype = t }, label = { Text(t) })
                    }
                }
                Text("Department", style = MaterialTheme.typography.labelLarge)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(depts) { d ->
                        val id = d.optInt("id")
                        FilterChip(selected = deptId == id, onClick = { deptId = id }, label = { Text(d.optString("name")) })
                    }
                }
                Text("Linked app user (lets them clock in)", style = MaterialTheme.typography.labelLarge)
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item { FilterChip(selected = adminId == 0, onClick = { adminId = 0 }, label = { Text("None") }) }
                    items(users) { u ->
                        val id = u.optInt("id")
                        FilterChip(selected = adminId == id, onClick = { adminId = id }, label = { Text(u.optString("name")) })
                    }
                }
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(if (active) "Active" else "Inactive")
                    Switch(checked = active, onCheckedChange = { active = it })
                }
            }
        },
        confirmButton = {
            Button(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        val p = mutableMapOf(
                            "id" to (emp?.optInt("id") ?: 0).toString(),
                            "first_name" to first.trim(),
                            "last_name" to last.trim(),
                            "phone" to phone.trim(),
                            "email" to email.trim(),
                            "job_title" to job.trim(),
                            "hire_date" to hired.trim(),
                            "employment_type" to etype,
                            "department_id" to deptId.toString(),
                            "admin_id" to adminId.toString(),
                            "status" to if (active) "Active" else "Inactive"
                        )
                        if (canSalary) p["basic_salary"] = salary.trim()
                        val r = Api.call("hr_employee_save", p)
                        toast(ctx, r.optString("message", "Saved"))
                        onDone()
                    } catch (e: Exception) {
                        toast(ctx, e.message)
                    } finally {
                        busy = false
                    }
                }
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onClose) { Text("Cancel") } }
    )
}

/* ------------------------------------------------------------------ */
/* Attendance (one day, with previous / next)                           */
/* ------------------------------------------------------------------ */

@Composable
fun HrAttendance(reload: Int) {
    var req by remember { mutableStateOf(Pair("", 0)) }
    Remote(listOf(reload, req), load = {
        Api.call("hr_attendance", mapOf("date" to req.first, "shift" to req.second.toString()))
    }) { j ->
        val date = j.optString("date")
        val rows = j.optJSONArray("rows").list()
        val counts = j.optJSONObject("counts")
        LazyVerticalGrid(
            gridSpec(),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            fullWidth {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    OutlinedButton(onClick = { req = Pair(date, -1) }) { Text("Previous") }
                    Text(date, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    OutlinedButton(onClick = { req = Pair(date, 1) }) { Text("Next") }
                }
            }
            if (counts != null) {
                items(listOf("Present", "Late", "On Leave", "Absent")) { k ->
                    StatCard(k, counts.optString(k, "0"), if (k == "Present") "good" else if (k == "Absent" || k == "Late") "bad" else "info", Modifier.fillMaxWidth())
                }
            }
            items(rows, span = { GridItemSpan(maxLineSpan) }) { r ->
                Card(Modifier.fillMaxWidth()) {
                    Row(
                        Modifier.padding(12.dp).fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(r.optString("name"), fontWeight = FontWeight.SemiBold)
                            Text(
                                listOf(r.optString("department"), r.optString("clock_in"), r.optString("clock_out"))
                                    .filter { it.isNotBlank() }.joinToString(" · "),
                                style = MaterialTheme.typography.bodySmall
                            )
                        }
                        Text(r.optString("status"), style = MaterialTheme.typography.labelMedium, color = statusColor(r.optString("status")))
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Leave approvals                                                      */
/* ------------------------------------------------------------------ */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HrLeave(reload: Int) {
    var bump by remember { mutableIntStateOf(0) }
    var filter by remember { mutableStateOf("Pending") }
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()

    Column(Modifier.fillMaxSize()) {
        LazyRow(
            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            items(listOf("Pending", "Approved", "Rejected", "All")) { f ->
                FilterChip(selected = filter == f, onClick = { filter = f }, label = { Text(f) })
            }
        }
        Remote(listOf(reload, bump, filter), Modifier.weight(1f), load = {
            Api.call("hr_leave", mapOf("status" to if (filter == "All") "" else filter))
        }) { j ->
            val leaves = j.optJSONArray("leaves").list()
            if (leaves.isEmpty()) {
                Column(Modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
                    Text("No $filter leave requests")
                }
            } else {
                LazyVerticalGrid(
                    GridCells.Adaptive(300.dp),
                    Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(12.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    items(leaves) { l ->
                        LeaveCard(l) { decision ->
                            scope.launch {
                                try {
                                    val r = Api.call(
                                        "hr_leave_decide",
                                        mapOf("id" to l.optInt("id").toString(), "decision" to decision, "note" to "")
                                    )
                                    toast(ctx, r.optString("message", "Done"))
                                    bump++
                                } catch (e: Exception) {
                                    toast(ctx, e.message)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Payroll (view only, one month, with previous / next)                 */
/* ------------------------------------------------------------------ */

@Composable
fun HrPayroll(reload: Int) {
    var req by remember { mutableStateOf(Pair("", 0)) }
    Remote(listOf(reload, req), load = {
        Api.call("hr_payroll", mapOf("month" to req.first, "shift" to req.second.toString()))
    }) { j ->
        val month = j.optString("month")
        val rows = j.optJSONArray("rows").list()
        val t = j.optJSONObject("totals")
        LazyVerticalGrid(
            gridSpec(),
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            fullWidth {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    OutlinedButton(onClick = { req = Pair(month, -1) }) { Text("Previous") }
                    Text(month, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    OutlinedButton(onClick = { req = Pair(month, 1) }) { Text("Next") }
                }
            }
            if (t != null) {
                item { StatCard("Employees", t.optString("count", "0"), "info", Modifier.fillMaxWidth()) }
                item { StatCard("Paid", t.optString("paid", "0"), "good", Modifier.fillMaxWidth()) }
                item { StatCard("Gross", t.optString("gross", "0"), "info", Modifier.fillMaxWidth()) }
                item { StatCard("Net", t.optString("net", "0"), "good", Modifier.fillMaxWidth()) }
            }
            if (rows.isEmpty()) {
                fullWidth { Text("No payroll for this month") }
            }
            items(rows, span = { GridItemSpan(maxLineSpan) }) { r ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text(r.optString("employee"), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Text(r.optString("status"), style = MaterialTheme.typography.labelMedium, color = statusColor(r.optString("status")))
                        }
                        KeyValue("Basic", r.optString("basic"))
                        KeyValue("Gross", r.optString("gross"))
                        KeyValue("Deductions", r.optString("deductions"))
                        KeyValue("Net pay", r.optString("net"))
                    }
                }
            }
        }
    }
}
