<?php
/**
 * Mobile API for the Eazyradius CRM Android app.
 *
 * Install: copy to  system/plugin/mobile_api.php
 * Endpoint: https://YOUR-PANEL/?_route=plugin/mobile_api&a=<action>
 *
 * Accounts
 *   staff   -> tbl_users (SuperAdmin, Admin, Report, Agent, Sales). Field technicians are the users
 *              listed in tbl_ops_techs. What a staff member sees in the app is decided by the
 *              Access screen (table tbl_mobile_access) and is enforced here on every request.
 *   client  -> tbl_customers
 *
 * Auth: POST a=login returns a token. Send it later as header X-Mobile-Token
 * (or POST field "token"). Only a SHA-256 hash of the token is stored.
 */

function mobile_api()
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $a = mobile_api_in('a');
    try {
        mobile_api_boot();

        if ($a === 'ping') {
            mobile_api_out(['ok' => true, 'version' => '2.0']);
        }
        if ($a === 'login') {
            mobile_api_login();
        }

        $s = mobile_api_auth();

        switch ($a) {
            case 'logout':
                ORM::for_table('tbl_mobile_tokens')->where('id', $s['token_id'])->delete_many();
                mobile_api_out(['ok' => true]);
            case 'me':
                mobile_api_out(mobile_api_me($s));

            // ---- client ----
            case 'client_home':
                mobile_api_client_only($s);
                mobile_api_client_home($s);
            case 'client_plans':
                mobile_api_client_only($s);
                $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
                mobile_api_plans($c ? $c['service_type'] : null);
            case 'client_buy':
                mobile_api_client_only($s);
                mobile_api_client_buy($s);
            case 'client_history':
                mobile_api_client_only($s);
                $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
                mobile_api_transactions($c ? $c['username'] : '-');

            // ---- staff: business ----
            case 'admin_dashboard':
                mobile_api_mod($s, 'dashboard');
                mobile_api_admin_dashboard($s);
            case 'admin_customers':
                mobile_api_mod($s, 'customers');
                mobile_api_admin_customers();
            case 'admin_customer':
                mobile_api_mod($s, 'customers');
                mobile_api_admin_customer();
            case 'admin_plans':
                mobile_api_mod($s, 'customers');
                mobile_api_plans(null);
            case 'admin_recharge':
                mobile_api_mod($s, 'customers');
                mobile_api_admin_recharge($s);
            case 'admin_transactions':
                mobile_api_mod($s, 'payments');
                mobile_api_transactions(null);

            // ---- staff: accounts + access ----
            case 'admin_users':
                mobile_api_mod($s, 'users');
                mobile_api_users_list($s);
            case 'admin_user_save':
                mobile_api_mod($s, 'users');
                mobile_api_user_save($s);
            case 'admin_user_delete':
                mobile_api_mod($s, 'users');
                mobile_api_user_delete($s);
            case 'access_get':
                mobile_api_super($s);
                mobile_api_access_get();
            case 'access_save':
                mobile_api_super($s);
                mobile_api_access_save($s);
            case 'access_reset':
                mobile_api_super($s);
                mobile_api_access_reset();

            // ---- staff: leads, tickets, installations ----
            case 'ops_list':
                mobile_api_staff($s);
                mobile_api_ops_list($s);
            case 'ops_update':
                mobile_api_staff($s);
                mobile_api_ops_update($s);
            case 'ops_create':
                mobile_api_staff($s);
                mobile_api_ops_create($s);
            case 'tech_dashboard':
                mobile_api_staff($s);
                mobile_api_out(['ok' => true] + mobile_api_ops_overview(mobile_api_scope_uid($s), mobile_api_ops_allowed($s)));

            // ---- staff: HR ----
            case 'hr_me':
                mobile_api_mod($s, 'my_hr');
                mobile_api_hr_me($s);
            case 'hr_clock':
                mobile_api_mod($s, 'my_hr');
                mobile_api_hr_clock($s);
            case 'hr_leave_request':
                mobile_api_mod($s, 'my_hr');
                mobile_api_hr_leave_request($s);
            case 'hr_employees':
                mobile_api_mod($s, 'hr_employees');
                mobile_api_hr_employees($s);
            case 'hr_employee_save':
                mobile_api_mod($s, 'hr_employees');
                mobile_api_hr_employee_save($s);
            case 'hr_attendance':
                mobile_api_mod($s, 'hr_attendance');
                mobile_api_hr_attendance();
            case 'hr_leave':
                mobile_api_mod($s, 'hr_leave');
                mobile_api_hr_leave($s);
            case 'hr_leave_decide':
                mobile_api_mod($s, 'hr_leave');
                mobile_api_hr_leave_decide($s);
            case 'hr_payroll':
                mobile_api_mod($s, 'hr_payroll');
                mobile_api_hr_payroll();

            default:
                mobile_api_fail('Unknown action', 404);
        }
    } catch (Throwable $e) {
        mobile_api_fail($e->getMessage(), 500);
    }
}

/* ------------------------------------------------------------------ */
/* helpers                                                             */
/* ------------------------------------------------------------------ */

function mobile_api_in($k, $d = '')
{
    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input');
        $json = [];
        if ($raw !== '' && $raw[0] === '{') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $json = $j;
            }
        }
    }
    if (isset($_POST[$k])) return $_POST[$k];
    if (isset($_GET[$k])) return $_GET[$k];
    if (isset($json[$k])) return $json[$k];
    return $d;
}

function mobile_api_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function mobile_api_fail($msg, $code = 400)
{
    mobile_api_out(['ok' => false, 'error' => $msg], $code);
}

function mobile_api_need($role, $allowed)
{
    if (!in_array($role, $allowed, true)) {
        mobile_api_fail('Not allowed for your role', 403);
    }
}

function mobile_api_boot()
{
    $db = ORM::get_db();
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        role VARCHAR(16) NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME NOT NULL,
        last_used DATETIME NULL,
        UNIQUE KEY token_hash (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_access (
        role_key VARCHAR(64) NOT NULL PRIMARY KEY,
        modules TEXT NOT NULL,
        scope VARCHAR(8) NOT NULL DEFAULT 'mine',
        can_assign TINYINT(1) NOT NULL DEFAULT 0,
        can_create TINYINT(1) NOT NULL DEFAULT 0,
        updated_by INT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_lead_assign (
        lead_id INT NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        assigned_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mobile_api_tech_types()
{
    global $config;
    $raw = isset($config['mobile_api_tech_types']) && $config['mobile_api_tech_types'] !== ''
        ? $config['mobile_api_tech_types'] : 'Technician,Tech';
    return array_map('strtolower', array_map('trim', explode(',', $raw)));
}

function mobile_api_verify_admin($in, $stored)
{
    if (class_exists('Password') && method_exists('Password', '_verify')) {
        return Password::_verify($in, $stored);
    }
    return password_verify($in, $stored) || hash_equals((string) $stored, sha1($in));
}

function mobile_api_verify_customer($in, $stored)
{
    if (class_exists('Password') && method_exists('Password', '_uverify')) {
        return Password::_uverify($in, $stored);
    }
    return hash_equals((string) $stored, (string) $in);
}

function mobile_api_login()
{
    $u = trim((string) mobile_api_in('username'));
    $p = (string) mobile_api_in('password');
    if ($u === '' || $p === '') {
        mobile_api_fail('Enter username and password', 422);
    }

    $role = null;
    $uid = 0;
    $name = '';

    $admin = ORM::for_table('tbl_users')->where('username', $u)->find_one();
    if ($admin && mobile_api_verify_admin($p, $admin['password'])) {
        if (isset($admin['status']) && $admin['status'] !== 'Active') {
            mobile_api_fail('Account is not active', 403);
        }
        $role = 'staff';
        $uid = (int) $admin['id'];
        $name = $admin['fullname'] ?: $admin['username'];
    } else {
        $c = ORM::for_table('tbl_customers')->where('username', $u)->find_one();
        if ($c && mobile_api_verify_customer($p, $c['password'])) {
            if (isset($c['status']) && in_array($c['status'], ['Banned', 'Disabled'], true)) {
                mobile_api_fail('Account is disabled', 403);
            }
            $role = 'client';
            $uid = (int) $c['id'];
            $name = $c['fullname'] ?: $c['username'];
        }
    }

    if ($role === null) {
        mobile_api_fail('Wrong username or password', 401);
    }

    $token = bin2hex(random_bytes(32));
    $t = ORM::for_table('tbl_mobile_tokens')->create();
    $t->token_hash = hash('sha256', $token);
    $t->role = $role;
    $t->user_id = $uid;
    $t->created_at = date('Y-m-d H:i:s');
    $t->last_used = date('Y-m-d H:i:s');
    $t->save();

    $out = ['ok' => true, 'token' => $token, 'role' => $role, 'name' => $name, 'user_type' => ''];
    if ($role === 'staff') {
        $acc = mobile_api_access($uid);
        $out['user_type'] = $acc['user_type'];
        $out['access'] = mobile_api_access_out($acc);
    }
    mobile_api_out($out);
}

function mobile_api_auth()
{
    $token = isset($_SERVER['HTTP_X_MOBILE_TOKEN']) ? $_SERVER['HTTP_X_MOBILE_TOKEN'] : mobile_api_in('token');
    if (!$token) {
        mobile_api_fail('unauthorized', 401);
    }
    $row = ORM::for_table('tbl_mobile_tokens')->where('token_hash', hash('sha256', $token))->find_one();
    if (!$row || strtotime($row['created_at']) < time() - 60 * 86400) {
        mobile_api_fail('unauthorized', 401);
    }
    $row->last_used = date('Y-m-d H:i:s');
    $row->save();
    // tokens issued by older versions used the roles admin / technician: both are staff now
    $role = $row['role'] === 'client' ? 'client' : 'staff';
    $s = ['token_id' => $row['id'], 'role' => $role, 'user_id' => (int) $row['user_id']];
    if ($role === 'staff') {
        $s['access'] = mobile_api_access($s['user_id']);
    }
    return $s;
}

function mobile_api_safe($fn, $default = 0)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        return $default;
    }
}

function mobile_api_money($v)
{
    return number_format((float) $v, 0, '.', ',');
}

/* ------------------------------------------------------------------ */
/* admin                                                               */
/* ------------------------------------------------------------------ */

function mobile_api_admin_dashboard($s = null)
{
    $today = date('Y-m-d');
    $month = date('Y-m-01');

    $customers = mobile_api_safe(function () { return ORM::for_table('tbl_customers')->count(); });
    $active = mobile_api_safe(function () { return ORM::for_table('tbl_user_recharges')->where('status', 'on')->count(); });
    $expired = mobile_api_safe(function () { return ORM::for_table('tbl_user_recharges')->where('status', 'off')->count(); });
    $hotspot = mobile_api_safe(function () { return ORM::for_table('tbl_user_recharges')->where('status', 'on')->where('type', 'Hotspot')->count(); });
    $pppoe = mobile_api_safe(function () { return ORM::for_table('tbl_user_recharges')->where('status', 'on')->where('type', 'PPPoE')->count(); });
    $incToday = mobile_api_safe(function () use ($today) { return ORM::for_table('tbl_transactions')->where('recharged_on', $today)->sum('price'); });
    $incMonth = mobile_api_safe(function () use ($month) { return ORM::for_table('tbl_transactions')->where_gte('recharged_on', $month)->sum('price'); });
    $newMonth = mobile_api_safe(function () use ($month) { return ORM::for_table('tbl_customers')->where_gte('created_at', $month)->count(); });

    $cards = [
        ['label' => 'Income today', 'value' => mobile_api_money($incToday), 'tone' => 'good'],
        ['label' => 'Income this month', 'value' => mobile_api_money($incMonth), 'tone' => 'good'],
        ['label' => 'Customers', 'value' => (string) $customers, 'tone' => 'info'],
        ['label' => 'New this month', 'value' => (string) $newMonth, 'tone' => 'info'],
        ['label' => 'Active plans', 'value' => (string) $active, 'tone' => 'good'],
        ['label' => 'Expired plans', 'value' => (string) $expired, 'tone' => 'bad'],
        ['label' => 'Hotspot active', 'value' => (string) $hotspot, 'tone' => 'info'],
        ['label' => 'PPPoE active', 'value' => (string) $pppoe, 'tone' => 'info'],
    ];

    $recent = mobile_api_safe(function () {
        $out = [];
        foreach (ORM::for_table('tbl_transactions')->order_by_desc('id')->limit(8)->find_many() as $t) {
            $out[] = mobile_api_tx_row($t);
        }
        return $out;
    }, []);

    $allowed = $s ? mobile_api_ops_allowed($s) : null;
    $ops = mobile_api_safe(function () use ($allowed) { return mobile_api_ops_overview(null, $allowed); }, ['cards' => [], 'work' => new stdClass()]);
    $cards = array_merge(array_slice($cards, 0, 2), $ops['cards'], array_slice($cards, 2));

    mobile_api_out(['ok' => true, 'cards' => $cards, 'recent' => $recent, 'work' => $ops['work']]);
}

function mobile_api_admin_customers()
{
    $q = trim((string) mobile_api_in('q'));
    $page = max(0, (int) mobile_api_in('page', 0));
    $orm = ORM::for_table('tbl_customers')->order_by_desc('id');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $orm->where_raw('(username LIKE ? OR fullname LIKE ? OR phonenumber LIKE ?)', [$like, $like, $like]);
    }
    $rows = $orm->limit(40)->offset($page * 40)->find_many();
    $out = [];
    foreach ($rows as $c) {
        $r = mobile_api_active_recharge($c['id']);
        $out[] = [
            'id' => (int) $c['id'],
            'username' => $c['username'],
            'fullname' => $c['fullname'],
            'phone' => $c['phonenumber'],
            'balance' => mobile_api_money($c['balance']),
            'status' => $c['status'],
            'plan' => $r ? $r['namebp'] : '',
            'expires' => $r ? $r['expiration'] : '',
            'online' => $r ? ($r['status'] === 'on') : false,
        ];
    }
    mobile_api_out(['ok' => true, 'customers' => $out]);
}

function mobile_api_active_recharge($customerId)
{
    return mobile_api_safe(function () use ($customerId) {
        return ORM::for_table('tbl_user_recharges')->where('customer_id', $customerId)->order_by_desc('id')->find_one();
    }, null);
}

function mobile_api_admin_customer()
{
    $id = (int) mobile_api_in('id');
    $c = ORM::for_table('tbl_customers')->find_one($id);
    if (!$c) {
        mobile_api_fail('Customer not found', 404);
    }
    $r = mobile_api_active_recharge($id);
    $hist = [];
    foreach (ORM::for_table('tbl_transactions')->where('username', $c['username'])->order_by_desc('id')->limit(10)->find_many() as $t) {
        $hist[] = mobile_api_tx_row($t);
    }
    mobile_api_out(['ok' => true, 'customer' => [
        'id' => (int) $c['id'],
        'username' => $c['username'],
        'fullname' => $c['fullname'],
        'phone' => $c['phonenumber'],
        'email' => $c['email'],
        'address' => $c['address'],
        'balance' => mobile_api_money($c['balance']),
        'status' => $c['status'],
        'service' => $c['service_type'],
        'plan' => $r ? $r['namebp'] : '',
        'expires' => $r ? trim($r['expiration'] . ' ' . $r['time']) : '',
        'plan_status' => $r ? $r['status'] : '',
    ], 'history' => $hist]);
}

function mobile_api_admin_recharge($s)
{
    $cid = (int) mobile_api_in('customer_id');
    $pid = (int) mobile_api_in('plan_id');
    $c = ORM::for_table('tbl_customers')->find_one($cid);
    $plan = ORM::for_table('tbl_plans')->find_one($pid);
    if (!$c || !$plan) {
        mobile_api_fail('Customer or plan not found', 404);
    }
    if (!class_exists('Package') || !method_exists('Package', 'rechargeUser')) {
        mobile_api_fail('Package::rechargeUser not available in this PHPNuxBill version', 500);
    }
    $admin = ORM::for_table('tbl_users')->find_one($s['user_id']);
    $by = $admin ? ($admin['fullname'] ?: $admin['username']) : 'Admin';
    Package::rechargeUser($c['id'], $plan['routers'], $plan['id'], $by, 'Mobile App');
    mobile_api_out(['ok' => true, 'message' => 'Recharged ' . $c['username'] . ' with ' . $plan['name_plan']]);
}

/* ------------------------------------------------------------------ */
/* shared lists                                                        */
/* ------------------------------------------------------------------ */

function mobile_api_plans($serviceType)
{
    $orm = ORM::for_table('tbl_plans')->where('enabled', 1)->order_by_asc('price');
    if ($serviceType === 'Hotspot' || $serviceType === 'PPPoE') {
        $orm->where('type', $serviceType);
    }
    $out = [];
    foreach ($orm->find_many() as $p) {
        $out[] = [
            'id' => (int) $p['id'],
            'name' => $p['name_plan'],
            'price' => mobile_api_money($p['price']),
            'price_raw' => (float) $p['price'],
            'validity' => $p['validity'] . ' ' . $p['validity_unit'],
            'type' => $p['type'],
        ];
    }
    mobile_api_out(['ok' => true, 'plans' => $out]);
}

function mobile_api_tx_row($t)
{
    return [
        'id' => (int) $t['id'],
        'user' => $t['username'],
        'plan' => $t['plan_name'],
        'price' => mobile_api_money($t['price']),
        'date' => trim($t['recharged_on'] . ' ' . $t['recharged_time']),
        'method' => $t['method'],
        'invoice' => $t['invoice'],
    ];
}

function mobile_api_transactions($username)
{
    $orm = ORM::for_table('tbl_transactions')->order_by_desc('id');
    if ($username !== null) {
        $orm->where('username', $username);
    }
    $page = max(0, (int) mobile_api_in('page', 0));
    $out = [];
    foreach ($orm->limit(50)->offset($page * 50)->find_many() as $t) {
        $out[] = mobile_api_tx_row($t);
    }
    mobile_api_out(['ok' => true, 'transactions' => $out]);
}

/* ------------------------------------------------------------------ */
/* work: leads, support tickets, installations (assignable to techs)   */
/* ------------------------------------------------------------------ */
/*
 * Existing tables are detected (tbl_leads, tbl_tickets, tbl_installations ...). Override with
 * $config['mobile_api_table_lead' | '_ticket' | '_install']. If no leads/tickets table exists
 * the plugin creates tbl_mobile_leads / tbl_mobile_tickets. Installations are never created here.
 */

function mobile_api_tech_statuses()
{
    global $config;
    $raw = isset($config['mobile_api_tech_statuses']) && $config['mobile_api_tech_statuses'] !== ''
        ? $config['mobile_api_tech_statuses'] : 'pending,in_progress';
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function mobile_api_ops_closed()
{
    return ['won', 'lost', 'closed', 'resolved', 'completed', 'complete', 'done', 'cancelled', 'canceled', 'converted', 'installed', 'rejected', 'disqualified'];
}

function mobile_api_ops_first($cols, $cands)
{
    foreach ($cands as $c) {
        if (isset($cols[$c])) {
            return $cols[$c]['Field'];
        }
    }
    return null;
}

function mobile_api_ops_meta($entity)
{
    global $config;
    static $cache = [];
    if (array_key_exists($entity, $cache)) {
        return $cache[$entity];
    }
    $names = [
        'lead' => ['tbl_leads', 'leads', 'tbl_lead'],
        'ticket' => ['tbl_tickets', 'tickets', 'tbl_support_tickets', 'support_tickets'],
        'install' => ['tbl_installations', 'installations'],
    ];
    if (!isset($names[$entity])) {
        return $cache[$entity] = null;
    }
    $cands = $names[$entity];
    $ov = isset($config['mobile_api_table_' . $entity]) ? trim($config['mobile_api_table_' . $entity]) : '';
    if ($ov !== '' && preg_match('/^[A-Za-z0-9_]+$/', $ov)) {
        array_unshift($cands, $ov);
    }
    $table = null;
    foreach ($cands as $t) {
        if (ORM::get_db()->query("SHOW TABLES LIKE '" . $t . "'")->fetch()) {
            $table = $t;
            break;
        }
    }
    $fallback = false;
    if (!$table && $entity === 'lead') {
        ORM::get_db()->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_leads (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) NOT NULL, phone VARCHAR(40) NULL,
            address VARCHAR(255) NULL, notes TEXT NULL, source VARCHAR(80) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new', assigned_to INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $table = 'tbl_mobile_leads';
        $fallback = true;
    } elseif (!$table && $entity === 'ticket') {
        ORM::get_db()->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY, subject VARCHAR(191) NOT NULL, phone VARCHAR(40) NULL,
            address VARCHAR(255) NULL, description TEXT NULL, priority VARCHAR(16) NOT NULL DEFAULT 'normal',
            status VARCHAR(32) NOT NULL DEFAULT 'open', assigned_to INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $table = 'tbl_mobile_tickets';
        $fallback = true;
    }
    if (!$table) {
        return $cache[$entity] = null;
    }

    $cols = [];
    foreach (ORM::get_db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = $c;
    }

    $cand = [
        'title' => $entity === 'ticket'
            ? ['subject', 'title', 'issue', 'customer_name', 'name', 'fullname', 'username']
            : ($entity === 'lead'
                ? ['name', 'fullname', 'lead_name', 'customer_name', 'client_name', 'title']
                : ['customer_name', 'client_name', 'fullname', 'name', 'customer', 'username', 'title']),
        'subtitle' => $entity === 'ticket'
            ? ['description', 'message', 'category', 'issue']
            : ($entity === 'lead'
                ? ['plan_name', 'package', 'plan', 'interest', 'source', 'location']
                : ['package', 'plan', 'plan_name', 'service']),
        'phone' => ['phone', 'phonenumber', 'mobile', 'customer_phone', 'msisdn', 'contact'],
        'address' => ['address', 'location', 'site', 'area', 'estate'],
        'status' => ['status', 'state', 'stage'],
        'created' => ['created_at', 'created', 'creationdate', 'date', 'time'],
        'updated' => ['updated_at', 'updated', 'modified_at'],
        'priority' => ['priority', 'urgency'],
        'notes' => ['notes', 'note', 'comment', 'description', 'message', 'details'],
        'lat' => ['gps_lat', 'latitude', 'lat'],
        'lng' => ['gps_lng', 'longitude', 'lng', 'lon'],
        'assignee' => ['technician_id', 'tech_id', 'assigned_to', 'assigned_to_id', 'assigned_id', 'assignee_id', 'assigned_tech', 'technician', 'assigned_to_name'],
    ];
    $map = [];
    foreach ($cand as $k => $list) {
        $map[$k] = mobile_api_ops_first($cols, $list);
    }
    $assigneeInt = $map['assignee'] ? stripos($cols[strtolower($map['assignee'])]['Type'], 'int') !== false : false;
    // the real tbl_leads has no assignee column: keep assignments in a side table so the Leads plugin's table stays untouched
    $sidecar = ($entity === 'lead' && !$fallback && !$map['assignee']);
    if ($sidecar) {
        $assigneeInt = true;
    }

    return $cache[$entity] = [
        'entity' => $entity, 'table' => $table, 'cols' => $cols, 'map' => $map,
        'assignee_int' => $assigneeInt, 'fallback' => $fallback, 'sidecar' => $sidecar,
    ];
}

function mobile_api_ops_statuses($meta)
{
    global $config;
    $e = $meta['entity'];
    if (isset($config['mobile_api_statuses_' . $e]) && trim($config['mobile_api_statuses_' . $e]) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $config['mobile_api_statuses_' . $e]))));
    }
    if ($e === 'install') {
        // completing an installation stays in the Installations plugin so its M-Pesa payment gate is never bypassed
        return mobile_api_tech_statuses();
    }
    if ($e === 'lead') {
        // new -> pending_installation -> installed / disqualified is the Leads plugin's own workflow
        return $meta['fallback'] ? ['new', 'contacted', 'qualified', 'won', 'lost'] : [];
    }
    return ['open', 'in_progress', 'resolved', 'closed'];
}

/** Statuses this user may set: managers (assign permission) get everything, field staff a safe subset. */
function mobile_api_ops_status_list($meta, $s)
{
    $all = mobile_api_ops_statuses($meta);
    if (!empty($s['access']['assign']) || $meta['entity'] !== 'ticket') {
        return $all;
    }
    $sub = array_values(array_intersect(['in_progress', 'resolved'], $all));
    return $sub ? $sub : $all;
}

function mobile_api_user_names()
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (ORM::for_table('tbl_users')->find_many() as $u) {
            $names[(int) $u['id']] = $u['fullname'] ?: $u['username'];
        }
    }
    return $names;
}

function mobile_api_is_tech($uid)
{
    return (bool) mobile_api_safe(function () use ($uid) {
        return ORM::for_table('tbl_ops_techs')->where('user_id', (int) $uid)->count();
    }, 0);
}

function mobile_api_ops_techs()
{
    $out = [];
    $rows = mobile_api_safe(function () {
        return ORM::for_table('tbl_users')->raw_query(
            'SELECT u.* FROM tbl_users u JOIN tbl_ops_techs t ON t.user_id = u.id WHERE u.status = ? ORDER BY u.fullname',
            ['Active']
        )->find_many();
    }, []);
    foreach ($rows as $u) {
        $out[] = ['id' => (int) $u['id'], 'name' => $u['fullname'] ?: $u['username']];
    }
    return $out;
}

function mobile_api_lead_assign_map()
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (mobile_api_safe(function () { return ORM::for_table('tbl_mobile_lead_assign')->find_many(); }, []) as $r) {
            $map[(int) $r['lead_id']] = (int) $r['user_id'];
        }
    }
    return $map;
}

function mobile_api_ops_item($meta, $a)
{
    $m = $meta['map'];
    $g = function ($k) use ($m, $a) {
        return ($m[$k] && isset($a[$m[$k]]) && $a[$m[$k]] !== null) ? trim((string) $a[$m[$k]]) : '';
    };
    $title = $g('title');
    $sub = $g('subtitle');
    $notes = $g('notes');
    if ($notes === $sub || $notes === $title) {
        $notes = '';
    }
    $addr = $g('address');
    if ($addr === '' && isset($a['location']) && trim((string) $a['location']) !== '') {
        $addr = trim((string) $a['location']);
    }
    if ($addr !== '' && $m['address'] === 'location' && !empty($a['sub_location'])) {
        $addr .= ', ' . trim((string) $a['sub_location']);
    }
    $aid = 0;
    $aname = '';
    if (!empty($meta['sidecar'])) {
        $map = mobile_api_lead_assign_map();
        $aid = isset($map[(int) $a['id']]) ? $map[(int) $a['id']] : 0;
        if ($aid) {
            $names = mobile_api_user_names();
            $aname = isset($names[$aid]) ? $names[$aid] : '#' . $aid;
        }
    } elseif ($m['assignee'] && isset($a[$m['assignee']]) && $a[$m['assignee']] !== '' && $a[$m['assignee']] !== null) {
        $raw = $a[$m['assignee']];
        if ($meta['assignee_int']) {
            $aid = (int) $raw;
            if ($aid) {
                $names = mobile_api_user_names();
                $aname = isset($names[$aid]) ? $names[$aid] : '#' . $aid;
            }
        } else {
            $aname = (string) $raw;
        }
    }
    $lat = ($m['lat'] && isset($a[$m['lat']])) ? (float) $a[$m['lat']] : 0;
    $lng = ($m['lng'] && isset($a[$m['lng']])) ? (float) $a[$m['lng']] : 0;
    return [
        'id' => (int) $a['id'],
        'title' => $title !== '' ? $title : ('#' . $a['id']),
        'subtitle' => $sub,
        'notes' => $notes,
        'phone' => $g('phone'),
        'address' => $addr,
        'lat' => $lat,
        'lng' => $lng,
        'status' => $g('status'),
        'priority' => $g('priority'),
        'created' => $g('created'),
        'assignee_id' => $aid,
        'assignee' => $aname,
    ];
}

/** Restrict a query to items assigned to technician $uid (no-op if the table has no assignee column). */
/** Restrict a query to items assigned to $uid (no-op if the table has no way to assign). */
function mobile_api_ops_scope($orm, $meta, $uid)
{
    if (!empty($meta['sidecar'])) {
        return $orm->where_raw('id IN (SELECT lead_id FROM tbl_mobile_lead_assign WHERE user_id = ?)', [(int) $uid]);
    }
    $c = $meta['map']['assignee'];
    if (!$c) {
        return $orm;
    }
    if ($meta['assignee_int']) {
        return $orm->where($c, (int) $uid);
    }
    $u = ORM::for_table('tbl_users')->find_one($uid);
    $full = $u ? (string) $u['fullname'] : '';
    $user = $u ? (string) $u['username'] : '';
    return $orm->where_raw('(`' . $c . '` = ? OR `' . $c . '` = ?)', [$full, $user]);
}

function mobile_api_ops_unassigned_sql($meta)
{
    if (!empty($meta['sidecar'])) {
        return 'id NOT IN (SELECT lead_id FROM tbl_mobile_lead_assign WHERE user_id > 0)';
    }
    $c = $meta['map']['assignee'];
    if (!$c) {
        return null;
    }
    return $meta['assignee_int'] ? "(`$c` IS NULL OR `$c` = 0)" : "(`$c` IS NULL OR `$c` = '')";
}

function mobile_api_ops_open_orm($meta, $scopeUid)
{
    $orm = ORM::for_table($meta['table']);
    $sc = $meta['map']['status'];
    if ($sc) {
        $in = "'" . implode("','", mobile_api_ops_closed()) . "'";
        $orm->where_raw('(`' . $sc . '` IS NULL OR LOWER(`' . $sc . '`) NOT IN (' . $in . '))');
    }
    if ($scopeUid) {
        mobile_api_ops_scope($orm, $meta, $scopeUid);
    }
    return $orm;
}

/** Cards + latest open items for the dashboards. $scopeUid null = everything (admin). */
function mobile_api_ops_overview($scopeUid, $allowed = null)
{
    $labels = [
        'lead' => [$scopeUid ? 'My leads' : 'Open leads', 'Leads needing attention'],
        'ticket' => [$scopeUid ? 'My tickets' : 'Open tickets', 'Support tickets'],
        'install' => [$scopeUid ? 'My installs' : 'Pending installs', 'Installations'],
    ];
    $cards = [];
    $work = [];
    $unassigned = 0;
    foreach ($labels as $e => $l) {
        if ($allowed !== null && !in_array($e, $allowed, true)) {
            continue;
        }
        $meta = mobile_api_ops_meta($e);
        if (!$meta) {
            continue;
        }
        $open = mobile_api_safe(function () use ($meta, $scopeUid) { return mobile_api_ops_open_orm($meta, $scopeUid)->count(); });
        $cards[] = ['label' => $l[0], 'value' => (string) $open, 'tone' => $e === 'ticket' && $open > 0 ? 'bad' : 'info'];
        if (!$scopeUid) {
            $raw = mobile_api_ops_unassigned_sql($meta);
            if ($raw) {
                $unassigned += mobile_api_safe(function () use ($meta, $raw) {
                    return mobile_api_ops_open_orm($meta, null)->where_raw($raw)->count();
                });
            }
        }
        $items = mobile_api_safe(function () use ($meta, $scopeUid) {
            $out = [];
            foreach (mobile_api_ops_open_orm($meta, $scopeUid)->order_by_desc('id')->limit(5)->find_many() as $r) {
                $out[] = mobile_api_ops_item($meta, $r->as_array());
            }
            return $out;
        }, []);
        $work[$e] = ['label' => $l[1], 'items' => $items];
    }
    if (!$scopeUid && $cards) {
        $cards[] = ['label' => 'Unassigned work', 'value' => (string) $unassigned, 'tone' => $unassigned > 0 ? 'bad' : 'good'];
    }
    return ['cards' => $cards, 'work' => $work ? $work : new stdClass()];
}

function mobile_api_ops_entity()
{
    $e = (string) mobile_api_in('entity');
    if (!in_array($e, ['lead', 'ticket', 'install'], true)) {
        mobile_api_fail('Unknown entity', 422);
    }
    return $e;
}

function mobile_api_ops_is_mine($meta, $a, $uid)
{
    if (!empty($meta['sidecar'])) {
        $map = mobile_api_lead_assign_map();
        return isset($map[(int) $a['id']]) && $map[(int) $a['id']] === (int) $uid;
    }
    $c = $meta['map']['assignee'];
    if (!$c) {
        return true;
    }
    $raw = isset($a[$c]) ? $a[$c] : '';
    if ($meta['assignee_int']) {
        return (int) $raw === (int) $uid;
    }
    $u = ORM::for_table('tbl_users')->find_one($uid);
    return $u && $raw !== '' && (strcasecmp($raw, $u['fullname']) === 0 || strcasecmp($raw, $u['username']) === 0);
}

function mobile_api_ops_module($e)
{
    $m = ['lead' => 'leads', 'ticket' => 'support', 'install' => 'installations'];
    return $m[$e];
}

function mobile_api_ops_allowed($s)
{
    $out = [];
    foreach (['lead', 'ticket', 'install'] as $e) {
        if (in_array(mobile_api_ops_module($e), $s['access']['modules'], true)) {
            $out[] = $e;
        }
    }
    return $out;
}

function mobile_api_ops_gate($s, $e)
{
    if (!in_array($e, mobile_api_ops_allowed($s), true)) {
        mobile_api_fail('Your role cannot open this section', 403);
    }
}

/** null = sees everything, otherwise only items assigned to this user. */
function mobile_api_scope_uid($s)
{
    return $s['access']['scope'] === 'all' ? null : (int) $s['user_id'];
}

function mobile_api_ticket_log($row, $meta, $s, $msg)
{
    if ($meta['entity'] !== 'ticket' || !isset($meta['cols']['log'])) {
        return;
    }
    $line = date('Y-m-d H:i') . ' - ' . $s['access']['name'] . ' ' . $msg;
    $cur = (string) $row['log'];
    $row->set('log', $cur === '' ? $line : $cur . "\n" . $line);
}

function mobile_api_ops_list($s)
{
    $e = mobile_api_ops_entity();
    mobile_api_ops_gate($s, $e);
    $meta = mobile_api_ops_meta($e);
    $perm = !empty($s['access']['assign']);
    if (!$meta) {
        mobile_api_out(['ok' => true, 'items' => [], 'statuses' => [], 'techs' => [], 'can_create' => false,
            'can_assign' => false, 'note' => 'No ' . $e . ' table found. Set mobile_api_table_' . $e . ' to its table name.']);
    }
    $orm = ORM::for_table($meta['table'])->order_by_desc('id');
    $uid = mobile_api_scope_uid($s);
    if ($uid) {
        mobile_api_ops_scope($orm, $meta, $uid);
    }
    $items = [];
    foreach ($orm->limit(150)->find_many() as $r) {
        $items[] = mobile_api_ops_item($meta, $r->as_array());
    }
    $hasAssign = (bool) ($meta['map']['assignee'] || !empty($meta['sidecar']));
    mobile_api_out([
        'ok' => true,
        'items' => $items,
        'statuses' => mobile_api_ops_status_list($meta, $s),
        'techs' => $perm ? mobile_api_ops_techs() : [],
        'can_create' => $e !== 'install' && !empty($s['access']['create']),
        'can_assign' => $perm && $hasAssign,
        'note' => $hasAssign ? '' : 'This table has no assignee column, so items cannot be assigned.',
    ]);
}

function mobile_api_ops_set_assignee($row, $meta, $techId, $s = null)
{
    $techName = '';
    if ($techId > 0) {
        $t = ORM::for_table('tbl_users')->find_one($techId);
        if (!$t || $t['status'] !== 'Active' || !mobile_api_is_tech($techId)) {
            mobile_api_fail('Pick a technician (mark users as Technician in the Users tab)', 422);
        }
        $techName = $t['fullname'] ?: $t['username'];
    }
    if (!empty($meta['sidecar'])) {
        $db = ORM::get_db();
        if ($techId > 0) {
            $db->prepare('REPLACE INTO tbl_mobile_lead_assign (lead_id, user_id, assigned_at) VALUES (?, ?, ?)')
                ->execute([(int) $row['id'], (int) $techId, date('Y-m-d H:i:s')]);
        } else {
            $db->prepare('DELETE FROM tbl_mobile_lead_assign WHERE lead_id = ?')->execute([(int) $row['id']]);
        }
        return;
    }
    $c = $meta['map']['assignee'];
    if (!$c) {
        mobile_api_fail('This table has no assignee column', 422);
    }
    if ($techId <= 0) {
        $row->set($c, $meta['assignee_int'] ? null : '');
        return;
    }
    $row->set($c, $meta['assignee_int'] ? (int) $techId : $techName);
    if ($s) {
        mobile_api_ticket_log($row, $meta, $s, 'assigned to ' . $techName);
    }
}

function mobile_api_ops_update($s)
{
    $e = mobile_api_ops_entity();
    mobile_api_ops_gate($s, $e);
    $meta = mobile_api_ops_meta($e);
    if (!$meta) {
        mobile_api_fail('Table not found', 404);
    }
    $row = ORM::for_table($meta['table'])->find_one((int) mobile_api_in('id'));
    if (!$row) {
        mobile_api_fail('Item not found', 404);
    }
    $uid = mobile_api_scope_uid($s);
    if ($uid && !mobile_api_ops_is_mine($meta, $row->as_array(), $uid)) {
        mobile_api_fail('This item is not assigned to you', 403);
    }

    $status = trim((string) mobile_api_in('status'));
    if ($status !== '') {
        if (!in_array($status, mobile_api_ops_status_list($meta, $s), true)) {
            mobile_api_fail($e === 'install'
                ? 'That status can only be set from the Installations plugin'
                : ($e === 'lead' ? 'Lead status is managed in the Leads plugin' : 'You cannot set that status'), 403);
        }
        if (!$meta['map']['status']) {
            mobile_api_fail('This table has no status column', 422);
        }
        $row->set($meta['map']['status'], $status);
        if ($e === 'install' && $status === 'in_progress' && isset($meta['cols']['started_at']) && empty($row['started_at'])) {
            $row->set('started_at', date('Y-m-d H:i:s'));
        }
        $words = ['in_progress' => 'started work', 'resolved' => 'marked resolved (awaiting verification)', 'closed' => 'verified and closed', 'open' => 'reopened the ticket'];
        mobile_api_ticket_log($row, $meta, $s, isset($words[$status]) ? $words[$status] : 'set status to ' . $status);
    }

    $assign = mobile_api_in('assignee_id', null);
    if (($assign !== null && $assign !== '') || mobile_api_in('unassign') === '1') {
        if (empty($s['access']['assign'])) {
            mobile_api_fail('Your role cannot assign work', 403);
        }
        mobile_api_ops_set_assignee($row, $meta, mobile_api_in('unassign') === '1' ? 0 : (int) $assign, $s);
    }
    if ($meta['map']['updated']) {
        $row->set($meta['map']['updated'], date('Y-m-d H:i:s'));
    }
    $row->save();
    mobile_api_out(['ok' => true]);
}

function mobile_api_ops_create($s)
{
    $e = mobile_api_ops_entity();
    mobile_api_ops_gate($s, $e);
    if ($e === 'install') {
        mobile_api_fail('Create installations from the Installations plugin', 403);
    }
    if (empty($s['access']['create'])) {
        mobile_api_fail('Your role cannot add new items', 403);
    }
    $meta = mobile_api_ops_meta($e);
    if (!$meta) {
        mobile_api_fail('Table not found', 404);
    }
    $m = $meta['map'];
    $title = trim((string) mobile_api_in('title'));
    if ($title === '' || !$m['title']) {
        mobile_api_fail($e === 'lead' ? 'Enter the lead name' : 'Enter the ticket subject', 422);
    }
    $r = ORM::for_table($meta['table'])->create();
    $r->set($m['title'], $title);
    $phone = trim((string) mobile_api_in('phone'));
    $address = trim((string) mobile_api_in('address'));
    $notes = trim((string) mobile_api_in('notes'));
    if ($m['phone'] && $phone !== '') $r->set($m['phone'], $phone);
    if ($m['address'] && $address !== '') $r->set($m['address'], $address);
    if ($m['notes'] && $notes !== '' && $m['notes'] !== $m['title']) $r->set($m['notes'], $notes);
    if ($m['status']) {
        $st = mobile_api_ops_statuses($meta);
        $r->set($m['status'], $st ? $st[0] : ($e === 'lead' ? 'new' : 'open'));
    }
    $prio = trim((string) mobile_api_in('priority'));
    if ($m['priority'] && $prio !== '' && ($meta['fallback'] || stripos($meta['cols'][strtolower($m['priority'])]['Type'], 'char') !== false)) {
        $r->set($m['priority'], $prio);
    }
    $now = date('Y-m-d H:i:s');
    if ($m['created']) $r->set($m['created'], $now);
    if ($m['updated']) $r->set($m['updated'], $now);
    if (isset($meta['cols']['created_by'])) $r->set('created_by', (int) $s['user_id']);
    if (isset($meta['cols']['created_by_name'])) $r->set('created_by_name', $s['access']['name']);

    mobile_api_ticket_log($r, $meta, $s, 'created ticket');
    $assign = !empty($s['access']['assign']) ? (int) mobile_api_in('assignee_id', 0) : ($s['access']['is_tech'] ? (int) $s['user_id'] : 0);
    if ($m['assignee'] && $assign > 0) {
        mobile_api_ops_set_assignee($r, $meta, $assign, $s);
    }

    // fill any other NOT NULL columns so strict MySQL accepts the insert
    $data = $r->as_array();
    foreach ($meta['cols'] as $c) {
        $f = $c['Field'];
        if ($c['Null'] === 'NO' && $c['Default'] === null && strpos($c['Extra'], 'auto_increment') === false
            && !array_key_exists($f, $data)) {
            if (stripos($c['Type'], 'int') !== false) {
                $r->set($f, 0);
            } elseif (stripos($c['Type'], 'date') !== false || stripos($c['Type'], 'timestamp') !== false) {
                $r->set($f, $now);
            } else {
                $r->set($f, '');
            }
        }
    }
    $r->save();
    if ($e === 'lead' && isset($meta['cols']['lead_no']) && empty($r['lead_no'])) {
        $r->set('lead_no', sprintf('LDS%05d', (int) $r['id']));
        $r->save();
    }
    if (!empty($meta['sidecar']) && $assign > 0) {
        mobile_api_ops_set_assignee($r, $meta, $assign, $s);
    }
    mobile_api_out(['ok' => true, 'id' => (int) $r['id'], 'message' => $e === 'lead' ? 'Lead added' : 'Ticket created']);
}

/* ------------------------------------------------------------------ */
/* client                                                              */
/* ------------------------------------------------------------------ */

function mobile_api_client_home($s)
{
    $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
    if (!$c) {
        mobile_api_fail('Account not found', 404);
    }
    $r = mobile_api_active_recharge($c['id']);
    $plan = null;
    if ($r) {
        $plan = [
            'name' => $r['namebp'],
            'type' => $r['type'],
            'expires' => trim($r['expiration'] . ' ' . $r['time']),
            'active' => $r['status'] === 'on',
        ];
    }
    mobile_api_out(['ok' => true, 'client' => [
        'name' => $c['fullname'] ?: $c['username'],
        'username' => $c['username'],
        'phone' => $c['phonenumber'],
        'balance' => mobile_api_money($c['balance']),
        'balance_raw' => (float) $c['balance'],
        'plan' => $plan,
    ]]);
}

function mobile_api_client_buy($s)
{
    $pid = (int) mobile_api_in('plan_id');
    $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
    $plan = ORM::for_table('tbl_plans')->where('enabled', 1)->find_one($pid);
    if (!$c || !$plan) {
        mobile_api_fail('Plan not found', 404);
    }
    $price = (float) $plan['price'];
    if ((float) $c['balance'] < $price) {
        mobile_api_fail('Not enough balance. Top up first.', 402);
    }
    if (!class_exists('Package') || !method_exists('Package', 'rechargeUser')) {
        mobile_api_fail('Purchasing is not available in this version', 500);
    }
    $old = $c['balance'];
    $c->balance = $old - $price;
    $c->save();
    try {
        Package::rechargeUser($c['id'], $plan['routers'], $plan['id'], 'Customer', 'Balance');
    } catch (Throwable $e) {
        $c->balance = $old;
        $c->save();
        mobile_api_fail('Could not activate plan: ' . $e->getMessage(), 500);
    }
    mobile_api_out(['ok' => true, 'message' => $plan['name_plan'] . ' activated']);
}


/* ------------------------------------------------------------------ */
/* staff management (SuperAdmin: everyone, Admin: non-admin roles)     */
/* ------------------------------------------------------------------ */

function mobile_api_all_roles()
{
    $r = mobile_api_safe(function () {
        return ORM::get_db()->query("SHOW COLUMNS FROM tbl_users LIKE 'user_type'")->fetch(PDO::FETCH_ASSOC);
    }, null);
    if ($r && preg_match_all("/'([^']+)'/", $r['Type'], $m) && $m[1]) {
        return $m[1];
    }
    return ['SuperAdmin', 'Admin', 'Report', 'Agent', 'Sales'];
}

function mobile_api_actor($s)
{
    $u = ORM::for_table('tbl_users')->find_one($s['user_id']);
    if (!$u) {
        mobile_api_fail('unauthorized', 401);
    }
    return $u;
}

function mobile_api_is_privileged($type)
{
    return in_array(strtolower((string) $type), ['superadmin', 'admin'], true);
}

function mobile_api_can_manage($actor, $targetType)
{
    $a = strtolower((string) $actor['user_type']);
    if ($a === 'superadmin') {
        return true;
    }
    if ($a === 'admin') {
        return !mobile_api_is_privileged($targetType);
    }
    return false;
}

function mobile_api_assignable_roles($actor)
{
    $out = [];
    foreach (mobile_api_all_roles() as $r) {
        if (mobile_api_can_manage($actor, $r)) {
            $out[] = $r;
        }
    }
    return $out;
}

function mobile_api_need_manager($actor)
{
    if (!in_array(strtolower((string) $actor['user_type']), ['superadmin', 'admin'], true)) {
        mobile_api_fail('Only SuperAdmin or Admin can manage users', 403);
    }
}

function mobile_api_other_active_supers($exceptId)
{
    return ORM::for_table('tbl_users')
        ->where_raw("LOWER(user_type) = 'superadmin'")
        ->where('status', 'Active')
        ->where_not_equal('id', $exceptId)
        ->count();
}

function mobile_api_revoke_staff_tokens($userId)
{
    ORM::for_table('tbl_mobile_tokens')
        ->where('user_id', $userId)
        ->where_in('role', ['admin', 'technician', 'staff'])
        ->delete_many();
}

function mobile_api_hash_pw($pw)
{
    if (class_exists('Password') && method_exists('Password', '_crypt')) {
        return Password::_crypt($pw);
    }
    return sha1($pw); // PHPNuxBill stores admin passwords as SHA1
}

function mobile_api_users_list($s)
{
    $actor = mobile_api_actor($s);
    mobile_api_need_manager($actor);
    $techs = [];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_ops_techs')->find_many(); }, []) as $t) {
        $techs[(int) $t['user_id']] = true;
    }
    $roleNames = [];
    $staffRoles = [];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_staff_roles')->order_by_asc('name')->find_many(); }, []) as $r) {
        $roleNames[(int) $r['id']] = $r['name'];
        $staffRoles[] = ['id' => (int) $r['id'], 'name' => $r['name']];
    }
    $userRoles = [];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_staff_user_roles')->find_many(); }, []) as $r) {
        $userRoles[(int) $r['user_id']][] = (int) $r['role_id'];
    }
    $out = [];
    foreach (ORM::for_table('tbl_users')->order_by_asc('username')->find_many() as $u) {
        $uid = (int) $u['id'];
        $self = $uid === (int) $actor['id'];
        $ids = isset($userRoles[$uid]) ? $userRoles[$uid] : [];
        $names = [];
        foreach ($ids as $i) {
            if (isset($roleNames[$i])) {
                $names[] = $roleNames[$i];
            }
        }
        $out[] = [
            'id' => $uid,
            'username' => $u['username'],
            'fullname' => $u['fullname'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'role' => $u['user_type'],
            'status' => $u['status'],
            'last_login' => isset($u['last_login']) ? (string) $u['last_login'] : '',
            'is_tech' => isset($techs[$uid]),
            'staff_role_ids' => $ids,
            'staff_role_names' => $names,
            'self' => $self,
            'can_edit' => $self || mobile_api_can_manage($actor, $u['user_type']),
        ];
    }
    mobile_api_out(['ok' => true, 'users' => $out, 'roles' => mobile_api_assignable_roles($actor), 'staff_roles' => $staffRoles]);
}

function mobile_api_user_save($s)
{
    $actor = mobile_api_actor($s);
    mobile_api_need_manager($actor);

    $id = (int) mobile_api_in('id', 0);
    $fullname = trim((string) mobile_api_in('fullname'));
    $phone = trim((string) mobile_api_in('phone'));
    $email = trim((string) mobile_api_in('email'));
    $password = (string) mobile_api_in('password');
    $roleIn = trim((string) mobile_api_in('role'));
    $status = mobile_api_in('status') === 'Inactive' ? 'Inactive' : 'Active';

    if ($fullname === '') {
        mobile_api_fail('Full name is required', 422);
    }
    if ($password !== '' && strlen($password) < 6) {
        mobile_api_fail('Password must be at least 6 characters', 422);
    }

    if ($id > 0) {
        $u = ORM::for_table('tbl_users')->find_one($id);
        if (!$u) {
            mobile_api_fail('User not found', 404);
        }
        $self = $id === (int) $actor['id'];
        if (!$self && !mobile_api_can_manage($actor, $u['user_type'])) {
            mobile_api_fail('You cannot edit this user', 403);
        }
        if ($self) {
            // own role and status are never changed from the app
            $roleIn = $u['user_type'];
            $status = $u['status'];
        } else {
            if (!in_array($roleIn, mobile_api_all_roles(), true)) {
                mobile_api_fail('Invalid role', 422);
            }
            if (!mobile_api_can_manage($actor, $roleIn)) {
                mobile_api_fail('You cannot assign that role', 403);
            }
            if (strtolower($u['user_type']) === 'superadmin'
                && (strtolower($roleIn) !== 'superadmin' || $status !== 'Active')
                && mobile_api_other_active_supers($id) === 0) {
                mobile_api_fail('You cannot remove the last active SuperAdmin', 403);
            }
        }
        $changedAccess = $roleIn !== $u['user_type'] || $status !== $u['status'] || $password !== '';
    } else {
        $username = trim((string) mobile_api_in('username'));
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,}$/', $username)) {
            mobile_api_fail('Username: at least 3 letters, numbers or . _ - @', 422);
        }
        if (strlen($password) < 6) {
            mobile_api_fail('Password must be at least 6 characters', 422);
        }
        if (!in_array($roleIn, mobile_api_all_roles(), true)) {
            mobile_api_fail('Invalid role', 422);
        }
        if (!mobile_api_can_manage($actor, $roleIn)) {
            mobile_api_fail('You cannot assign that role', 403);
        }
        if (ORM::for_table('tbl_users')->where('username', $username)->find_one()
            || ORM::for_table('tbl_customers')->where('username', $username)->find_one()) {
            mobile_api_fail('That username is already taken', 409);
        }
        $u = ORM::for_table('tbl_users')->create();
        $u->username = $username;
        $changedAccess = false;
    }

    $u->fullname = $fullname;
    $u->phone = $phone;
    $u->email = $email;
    $u->user_type = $roleIn;
    $u->status = $status;
    if ($password !== '') {
        $u->password = mobile_api_hash_pw($password);
    }

    if ($id === 0) {
        $u->creationdate = date('Y-m-d H:i:s');
        // fill any other NOT NULL columns so strict MySQL accepts the insert
        $data = $u->as_array();
        foreach (ORM::get_db()->query('SHOW COLUMNS FROM tbl_users')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $f = $c['Field'];
            if ($c['Null'] === 'NO' && $c['Default'] === null && strpos($c['Extra'], 'auto_increment') === false
                && !array_key_exists($f, $data)) {
                if (stripos($c['Type'], 'int') !== false) {
                    $u->set($f, 0);
                } elseif (stripos($c['Type'], 'date') !== false || stripos($c['Type'], 'timestamp') !== false) {
                    $u->set($f, date('Y-m-d H:i:s'));
                } else {
                    $u->set($f, '');
                }
            }
        }
    }
    $u->save();

    // technician flag (tbl_ops_techs) and custom staff roles (tbl_staff_user_roles) for other people's accounts
    $isSelfEdit = $id > 0 && $id === (int) $actor['id'];
    if (!$isSelfEdit) {
        $db = ORM::get_db();
        $uidSaved = (int) $u['id'];
        $techIn = mobile_api_in('is_tech', null);
        if ($techIn !== null) {
            $db->prepare('DELETE FROM tbl_ops_techs WHERE user_id = ?')->execute([$uidSaved]);
            if ($techIn === '1') {
                $db->prepare('INSERT INTO tbl_ops_techs (user_id) VALUES (?)')->execute([$uidSaved]);
            }
        }
        $rolesIn = mobile_api_in('staff_roles', null);
        if ($rolesIn !== null) {
            $db->prepare('DELETE FROM tbl_staff_user_roles WHERE user_id = ?')->execute([$uidSaved]);
            foreach (array_unique(array_filter(array_map('intval', explode(',', (string) $rolesIn)))) as $rid) {
                if (ORM::for_table('tbl_staff_roles')->find_one($rid)) {
                    $db->prepare('INSERT INTO tbl_staff_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$uidSaved, $rid]);
                }
            }
        }
    }

    if ($changedAccess) {
        mobile_api_revoke_staff_tokens((int) $u['id']);
    }
    mobile_api_out(['ok' => true, 'id' => (int) $u['id'], 'message' => $id > 0 ? 'User updated' : 'User added']);
}

function mobile_api_user_delete($s)
{
    $actor = mobile_api_actor($s);
    mobile_api_need_manager($actor);
    $id = (int) mobile_api_in('id');
    $u = ORM::for_table('tbl_users')->find_one($id);
    if (!$u) {
        mobile_api_fail('User not found', 404);
    }
    if ($id === (int) $actor['id']) {
        mobile_api_fail('You cannot delete your own account', 403);
    }
    if (!mobile_api_can_manage($actor, $u['user_type'])) {
        mobile_api_fail('You cannot delete this user', 403);
    }
    if (strtolower($u['user_type']) === 'superadmin' && mobile_api_other_active_supers($id) === 0) {
        mobile_api_fail('You cannot delete the last active SuperAdmin', 403);
    }
    mobile_api_revoke_staff_tokens($id);
    $db = ORM::get_db();
    $db->prepare('DELETE FROM tbl_ops_techs WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM tbl_staff_user_roles WHERE user_id = ?')->execute([$id]);
    $u->delete();
    mobile_api_out(['ok' => true, 'message' => 'User deleted']);
}


/* ------------------------------------------------------------------ */
/* access control: what each role can see in the app                   */
/* ------------------------------------------------------------------ */

function mobile_api_client_only($s)
{
    if ($s['role'] !== 'client') {
        mobile_api_fail('Not allowed for your account', 403);
    }
}

function mobile_api_staff($s)
{
    if ($s['role'] !== 'staff') {
        mobile_api_fail('Not allowed for your account', 403);
    }
}

function mobile_api_mod($s, $module)
{
    mobile_api_staff($s);
    if (!in_array($module, $s['access']['modules'], true)) {
        mobile_api_fail('Your role cannot open this section', 403);
    }
}

function mobile_api_super($s)
{
    mobile_api_staff($s);
    if (empty($s['access']['super'])) {
        mobile_api_fail('Only the SuperAdmin can change access', 403);
    }
}

function mobile_api_me($s)
{
    if ($s['role'] === 'client') {
        return ['ok' => true, 'role' => 'client'];
    }
    return ['ok' => true, 'role' => 'staff', 'name' => $s['access']['name'],
        'user_type' => $s['access']['user_type'], 'access' => mobile_api_access_out($s['access'])];
}

function mobile_api_catalog()
{
    return [
        ['key' => 'dashboard', 'label' => 'Business dashboard', 'group' => 'General'],
        ['key' => 'customers', 'label' => 'Customers and recharge', 'group' => 'General'],
        ['key' => 'payments', 'label' => 'Payments', 'group' => 'General'],
        ['key' => 'leads', 'label' => 'Leads', 'group' => 'Operations'],
        ['key' => 'installations', 'label' => 'Installations', 'group' => 'Operations'],
        ['key' => 'support', 'label' => 'Support tickets', 'group' => 'Operations'],
        ['key' => 'my_hr', 'label' => 'My HR: clock in, leave, payslips', 'group' => 'HR'],
        ['key' => 'hr_employees', 'label' => 'Employees', 'group' => 'HR'],
        ['key' => 'hr_attendance', 'label' => 'Attendance', 'group' => 'HR'],
        ['key' => 'hr_leave', 'label' => 'Leave approvals', 'group' => 'HR'],
        ['key' => 'hr_payroll', 'label' => 'Payroll (view only)', 'group' => 'HR'],
        ['key' => 'users', 'label' => 'Staff users', 'group' => 'Admin'],
    ];
}

function mobile_api_catalog_keys()
{
    return array_column(mobile_api_catalog(), 'key');
}

function mobile_api_default_access($key)
{
    switch ($key) {
        case 'type:Admin':
            return ['modules' => mobile_api_catalog_keys(), 'scope' => 'all', 'assign' => true, 'create' => true];
        case 'type:Report':
            return ['modules' => ['dashboard', 'payments', 'my_hr'], 'scope' => 'all', 'assign' => false, 'create' => false];
        case 'type:Sales':
        case 'type:Agent':
            return ['modules' => ['leads', 'customers', 'my_hr'], 'scope' => 'all', 'assign' => false, 'create' => true];
        case 'tech':
            return ['modules' => ['installations', 'support', 'leads', 'my_hr'], 'scope' => 'mine', 'assign' => false, 'create' => true];
    }
    return null;
}

/** Sensible starting point for a web staff role, read from its permission strings. */
function mobile_api_role_derived($id)
{
    $r = ORM::for_table('tbl_staff_roles')->find_one($id);
    if (!$r) {
        return null;
    }
    $perms = json_decode((string) $r['permissions'], true);
    $perms = is_array($perms) ? $perms : [];
    $mods = ['my_hr'];
    $field = false;
    foreach ($perms as $p) {
        $p = (string) $p;
        if (strpos($p, 'leads.') === 0) $mods[] = 'leads';
        if (strpos($p, 'installations.') === 0 || strpos($p, 'jobs.') === 0) $mods[] = 'installations';
        if (strpos($p, 'tickets.') === 0 || strpos($p, 'support.') === 0) $mods[] = 'support';
        if (strpos($p, 'customers.') === 0) $mods[] = 'customers';
        if ($p === 'installations.work' || $p === 'jobs.update') $field = true;
    }
    return ['modules' => array_values(array_unique($mods)), 'scope' => $field ? 'mine' : 'all', 'assign' => false, 'create' => true];
}

/** Stored record if the SuperAdmin customised it, else the default. $custom tells which. */
function mobile_api_access_rec($key, &$custom = null)
{
    $custom = false;
    $row = ORM::for_table('tbl_mobile_access')->where('role_key', $key)->find_one();
    if ($row) {
        $custom = true;
        $mods = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) $row['modules']))), mobile_api_catalog_keys()));
        return ['modules' => $mods, 'scope' => $row['scope'] === 'all' ? 'all' : 'mine',
            'assign' => (bool) $row['can_assign'], 'create' => (bool) $row['can_create']];
    }
    if (strpos($key, 'role:') === 0) {
        return mobile_api_role_derived((int) substr($key, 5));
    }
    return mobile_api_default_access($key);
}

/**
 * Resolve what a staff member can use. SuperAdmin: everything. Everyone else gets the union of
 * their Technician flag and custom staff roles; accounts with neither (and all Admins) use their account type.
 */
function mobile_api_access($uid)
{
    $u = ORM::for_table('tbl_users')->find_one($uid);
    if (!$u || (isset($u['status']) && $u['status'] !== 'Active')) {
        mobile_api_fail('unauthorized', 401);
    }
    $type = (string) $u['user_type'];
    $isTech = mobile_api_is_tech((int) $u['id']);
    $name = $u['fullname'] ?: $u['username'];
    if ($type === 'SuperAdmin') {
        return ['modules' => mobile_api_catalog_keys(), 'scope' => 'all', 'assign' => true, 'create' => true,
            'super' => true, 'is_tech' => $isTech, 'user_type' => $type, 'name' => $name];
    }
    $keys = [];
    if ($isTech) {
        $keys[] = 'tech';
    }
    foreach (mobile_api_safe(function () use ($uid) {
        return ORM::for_table('tbl_staff_user_roles')->where('user_id', $uid)->find_many();
    }, []) as $r) {
        $keys[] = 'role:' . (int) $r['role_id'];
    }
    if (!$keys || $type === 'Admin') {
        array_unshift($keys, 'type:' . $type);
    }
    $mods = [];
    $all = false;
    $assign = false;
    $create = false;
    foreach ($keys as $k) {
        $rec = mobile_api_access_rec($k);
        if (!$rec) {
            continue;
        }
        $mods = array_merge($mods, $rec['modules']);
        $all = $all || $rec['scope'] === 'all';
        $assign = $assign || $rec['assign'];
        $create = $create || $rec['create'];
    }
    if (!$mods) {
        $mods = ['my_hr'];
    }
    return ['modules' => array_values(array_unique($mods)), 'scope' => $all ? 'all' : 'mine', 'assign' => $assign,
        'create' => $create, 'super' => false, 'is_tech' => $isTech, 'user_type' => $type, 'name' => $name];
}

function mobile_api_access_out($acc)
{
    return ['modules' => $acc['modules'], 'scope' => $acc['scope'], 'assign' => $acc['assign'],
        'create' => $acc['create'], 'super' => !empty($acc['super']), 'is_tech' => $acc['is_tech']];
}

function mobile_api_access_keys()
{
    $keys = [
        ['type:Admin', 'Admin accounts', 'type'],
        ['type:Agent', 'Agent accounts', 'type'],
        ['type:Sales', 'Sales accounts', 'type'],
        ['type:Report', 'Report accounts', 'type'],
        ['tech', 'Technicians (flagged in Users)', 'tech'],
    ];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_staff_roles')->order_by_asc('name')->find_many(); }, []) as $r) {
        $keys[] = ['role:' . (int) $r['id'], $r['name'] . ' (staff role)', 'role'];
    }
    return $keys;
}

function mobile_api_access_get()
{
    $roles = [];
    foreach (mobile_api_access_keys() as $k) {
        $rec = mobile_api_access_rec($k[0], $custom);
        if (!$rec) {
            $rec = ['modules' => ['my_hr'], 'scope' => 'mine', 'assign' => false, 'create' => false];
        }
        $roles[] = ['key' => $k[0], 'label' => $k[1], 'kind' => $k[2], 'custom' => $custom,
            'modules' => $rec['modules'], 'scope' => $rec['scope'], 'assign' => $rec['assign'], 'create' => $rec['create']];
    }
    mobile_api_out(['ok' => true, 'catalog' => mobile_api_catalog(), 'roles' => $roles]);
}

function mobile_api_access_valid_key($key)
{
    foreach (mobile_api_access_keys() as $k) {
        if ($k[0] === $key) {
            return true;
        }
    }
    return false;
}

function mobile_api_access_save($s)
{
    $key = trim((string) mobile_api_in('role_key'));
    if (!mobile_api_access_valid_key($key)) {
        mobile_api_fail('Unknown role', 404);
    }
    $mods = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) mobile_api_in('modules')))), mobile_api_catalog_keys()));
    // role_key is the primary key (there is no id column), so use a real upsert instead of ORM->save()
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_access (role_key, modules, scope, can_assign, can_create, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE modules = VALUES(modules), scope = VALUES(scope), can_assign = VALUES(can_assign),
        can_create = VALUES(can_create), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
        ->execute([
            $key,
            implode(',', $mods),
            mobile_api_in('scope') === 'all' ? 'all' : 'mine',
            mobile_api_in('assign') === '1' ? 1 : 0,
            mobile_api_in('create') === '1' ? 1 : 0,
            (int) $s['user_id'],
            date('Y-m-d H:i:s'),
        ]);
    mobile_api_out(['ok' => true, 'message' => 'Access saved']);
}

function mobile_api_access_reset()
{
    $key = trim((string) mobile_api_in('role_key'));
    ORM::for_table('tbl_mobile_access')->where('role_key', $key)->delete_many();
    mobile_api_out(['ok' => true, 'message' => 'Back to the default']);
}

/* ------------------------------------------------------------------ */
/* HR (tbl_hr_*)                                                       */
/* ------------------------------------------------------------------ */

function mobile_api_hr_cfg($k, $d)
{
    global $config;
    return (isset($config[$k]) && $config[$k] !== '') ? $config[$k] : $d;
}

function mobile_api_hr_name($e)
{
    return trim($e['first_name'] . ' ' . $e['last_name']);
}

function mobile_api_hr_my_emp($uid)
{
    return ORM::for_table('tbl_hr_employees')->where('admin_id', (int) $uid)->find_one();
}

function mobile_api_hr_depts()
{
    $m = [];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_hr_departments')->order_by_asc('name')->find_many(); }, []) as $d) {
        $m[(int) $d['id']] = $d['name'];
    }
    return $m;
}

function mobile_api_hr_emp_names()
{
    $m = [];
    foreach (ORM::for_table('tbl_hr_employees')->find_many() as $e) {
        $m[(int) $e['id']] = mobile_api_hr_name($e);
    }
    return $m;
}

function mobile_api_hr_audit($s, $action, $type, $id, $details)
{
    try {
        $st = ORM::get_db()->prepare('INSERT INTO tbl_hr_audit_logs (actor_id, actor_name, action, entity_type, entity_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([(int) $s['user_id'], $s['access']['name'], $action, $type, (int) $id, $details,
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // the audit trail must never block the action itself
    }
}

function mobile_api_hr_quota()
{
    $q = json_decode((string) mobile_api_hr_cfg('hrm_leave_quota', '{}'), true);
    return is_array($q) ? $q : [];
}

function mobile_api_hr_leave_types()
{
    // the HRM plugin's own list of leave types (hrm_leave_types); quotas (hrm_leave_quota) only cap some of them
    $t = json_decode((string) mobile_api_hr_cfg('hrm_leave_types', '[]'), true);
    if (is_array($t) && $t) {
        return array_values(array_filter(array_map('strval', $t), 'strlen'));
    }
    $q = mobile_api_hr_quota();
    return $q ? array_keys($q) : ['Annual Leave', 'Sick Leave', 'Unpaid Leave'];
}

function mobile_api_hr_valid_date($d)
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

/** Working days between two dates: Sundays and public holidays do not count. */
function mobile_api_hr_days($from, $to)
{
    $hol = [];
    foreach (mobile_api_safe(function () { return ORM::for_table('tbl_hr_holidays')->find_many(); }, []) as $h) {
        $hol[$h['is_recurring'] ? 'r' . date('m-d', strtotime($h['holiday_date'])) : $h['holiday_date']] = true;
    }
    $n = 0;
    for ($d = strtotime($from), $end = strtotime($to); $d <= $end; $d = strtotime('+1 day', $d)) {
        if (date('N', $d) == 7 || isset($hol[date('Y-m-d', $d)]) || isset($hol['r' . date('m-d', $d)])) {
            continue;
        }
        $n++;
    }
    return $n;
}

function mobile_api_hr_leave_used($empId, $type, $year)
{
    return (float) ORM::for_table('tbl_hr_leave_requests')->where('employee_id', $empId)->where('leave_type', $type)
        ->where_in('status', ['Approved', 'Pending'])
        ->where_gte('start_date', $year . '-01-01')->where_lte('start_date', $year . '-12-31')->sum('days');
}

function mobile_api_hr_leave_row($l, $names)
{
    return [
        'id' => (int) $l['id'],
        'employee' => isset($names[(int) $l['employee_id']]) ? $names[(int) $l['employee_id']] : '#' . $l['employee_id'],
        'type' => $l['leave_type'],
        'start' => $l['start_date'],
        'end' => $l['end_date'],
        'days' => (float) $l['days'],
        'reason' => (string) $l['reason'],
        'status' => $l['status'],
        'decided_by' => (string) $l['decided_by_name'],
        'note' => (string) $l['decision_note'],
    ];
}

function mobile_api_hr_me($s)
{
    $emp = mobile_api_hr_my_emp($s['user_id']);
    $types = mobile_api_hr_leave_types();
    if (!$emp) {
        mobile_api_out(['ok' => true, 'linked' => false, 'types' => $types]);
    }
    $id = (int) $emp['id'];
    $depts = mobile_api_hr_depts();
    $att = ORM::for_table('tbl_hr_attendance')->where('employee_id', $id)->where('work_date', date('Y-m-d'))->find_one();
    $year = date('Y');
    $balances = [];
    foreach (mobile_api_hr_quota() as $type => $quota) {
        $used = mobile_api_hr_leave_used($id, $type, $year);
        $balances[] = ['type' => $type, 'quota' => (float) $quota, 'used' => $used, 'left' => max(0, (float) $quota - $used)];
    }
    $names = [$id => mobile_api_hr_name($emp)];
    $leaves = [];
    foreach (ORM::for_table('tbl_hr_leave_requests')->where('employee_id', $id)->order_by_desc('id')->limit(10)->find_many() as $l) {
        $leaves[] = mobile_api_hr_leave_row($l, $names);
    }
    $slips = [];
    foreach (ORM::for_table('tbl_hr_payroll')->where('employee_id', $id)->order_by_desc('period_month')->limit(6)->find_many() as $p) {
        $slips[] = ['month' => $p['period_month'], 'gross' => mobile_api_money($p['gross_pay']), 'net' => mobile_api_money($p['net_pay']), 'status' => $p['status']];
    }
    mobile_api_out([
        'ok' => true, 'linked' => true, 'types' => $types,
        'employee' => [
            'no' => $emp['employee_no'], 'name' => mobile_api_hr_name($emp), 'job_title' => (string) $emp['job_title'],
            'department' => isset($depts[(int) $emp['department_id']]) ? $depts[(int) $emp['department_id']] : '',
            'hire_date' => (string) $emp['hire_date'], 'status' => $emp['status'],
        ],
        'today' => $att ? ['clock_in' => substr((string) $att['clock_in'], 0, 5), 'clock_out' => substr((string) $att['clock_out'], 0, 5), 'status' => $att['status']] : null,
        'work_start' => mobile_api_hr_cfg('hrm_work_start', '08:30'),
        'balances' => $balances, 'leaves' => $leaves, 'payslips' => $slips,
    ]);
}

function mobile_api_hr_clock($s)
{
    $emp = mobile_api_hr_my_emp($s['user_id']);
    if (!$emp) {
        mobile_api_fail('Your account is not linked to an employee record. Ask HR to link it.', 422);
    }
    if ($emp['status'] !== 'Active') {
        mobile_api_fail('Your employee record is not active', 403);
    }
    $act = (string) mobile_api_in('act');
    $today = date('Y-m-d');
    $now = date('H:i:s');
    $att = ORM::for_table('tbl_hr_attendance')->where('employee_id', $emp['id'])->where('work_date', $today)->find_one();
    if ($act === 'in') {
        if ($att && !empty($att['clock_in'])) {
            mobile_api_fail('You already clocked in today', 409);
        }
        if (!$att) {
            $att = ORM::for_table('tbl_hr_attendance')->create();
            $att->employee_id = (int) $emp['id'];
            $att->work_date = $today;
            $att->created_at = date('Y-m-d H:i:s');
        }
        $late = strcmp(substr($now, 0, 5), mobile_api_hr_cfg('hrm_work_start', '08:30')) > 0;
        $att->clock_in = $now;
        $att->status = $late ? 'Late' : 'Present';
        $att->recorded_by = (int) $s['user_id'];
        $att->updated_at = date('Y-m-d H:i:s');
        $att->save();
        mobile_api_out(['ok' => true, 'message' => 'Clocked in at ' . substr($now, 0, 5) . ($late ? ' (late)' : '')]);
    }
    if ($act === 'out') {
        if (!$att || empty($att['clock_in'])) {
            mobile_api_fail('Clock in first', 409);
        }
        if (!empty($att['clock_out'])) {
            mobile_api_fail('You already clocked out today', 409);
        }
        $att->clock_out = $now;
        $att->updated_at = date('Y-m-d H:i:s');
        $att->save();
        mobile_api_out(['ok' => true, 'message' => 'Clocked out at ' . substr($now, 0, 5)]);
    }
    mobile_api_fail('Unknown action', 422);
}

function mobile_api_hr_leave_request($s)
{
    $emp = mobile_api_hr_my_emp($s['user_id']);
    if (!$emp) {
        mobile_api_fail('Your account is not linked to an employee record. Ask HR to link it.', 422);
    }
    $type = trim((string) mobile_api_in('leave_type'));
    $from = trim((string) mobile_api_in('start_date'));
    $to = trim((string) mobile_api_in('end_date'));
    $reason = trim((string) mobile_api_in('reason'));
    if (!in_array($type, mobile_api_hr_leave_types(), true)) {
        mobile_api_fail('Pick a leave type', 422);
    }
    if (!mobile_api_hr_valid_date($from) || !mobile_api_hr_valid_date($to) || $to < $from) {
        mobile_api_fail('Enter valid dates as YYYY-MM-DD, with the end on or after the start', 422);
    }
    if (strtotime($to) - strtotime($from) > 366 * 86400) {
        mobile_api_fail('That range is too long', 422);
    }
    $days = mobile_api_hr_days($from, $to);
    if ($days <= 0) {
        mobile_api_fail('Those dates are all Sundays or holidays', 422);
    }
    $clash = ORM::for_table('tbl_hr_leave_requests')->where('employee_id', $emp['id'])->where_in('status', ['Pending', 'Approved'])
        ->where_lte('start_date', $to)->where_gte('end_date', $from)->count();
    if ($clash) {
        mobile_api_fail('You already have leave in that period', 409);
    }
    $quota = mobile_api_hr_quota();
    if (isset($quota[$type])) {
        $left = (float) $quota[$type] - mobile_api_hr_leave_used((int) $emp['id'], $type, substr($from, 0, 4));
        if ($days > $left) {
            mobile_api_fail('Not enough ' . $type . ' left: ' . max(0, $left) . ' days remaining', 422);
        }
    }
    $r = ORM::for_table('tbl_hr_leave_requests')->create();
    $r->employee_id = (int) $emp['id'];
    $r->leave_type = $type;
    $r->start_date = $from;
    $r->end_date = $to;
    $r->days = $days;
    $r->reason = substr($reason, 0, 500);
    $r->status = 'Pending';
    $r->requested_by = (int) $s['user_id'];
    $r->created_at = date('Y-m-d H:i:s');
    $r->save();
    mobile_api_hr_audit($s, 'leave_request', 'leave', (int) $r['id'], $type . ' ' . $from . ' to ' . $to . ' (' . $days . ' days)');
    mobile_api_out(['ok' => true, 'message' => 'Leave requested: ' . $days . ' working days']);
}

function mobile_api_hr_employees($s)
{
    $q = trim((string) mobile_api_in('q'));
    $orm = ORM::for_table('tbl_hr_employees')->order_by_asc('first_name');
    if ($q !== '') {
        $like = '%' . $q . '%';
        $orm->where_raw('(first_name LIKE ? OR last_name LIKE ? OR employee_no LIKE ? OR phone LIKE ?)', [$like, $like, $like, $like]);
    }
    $depts = mobile_api_hr_depts();
    $pay = in_array('hr_payroll', $s['access']['modules'], true);
    $rows = [];
    foreach ($orm->limit(150)->find_many() as $e) {
        $rows[] = [
            'id' => (int) $e['id'], 'no' => $e['employee_no'], 'first_name' => $e['first_name'], 'last_name' => $e['last_name'],
            'name' => mobile_api_hr_name($e), 'phone' => (string) $e['phone'], 'email' => (string) $e['email'],
            'department_id' => (int) $e['department_id'],
            'department' => isset($depts[(int) $e['department_id']]) ? $depts[(int) $e['department_id']] : '',
            'job_title' => (string) $e['job_title'], 'hire_date' => (string) $e['hire_date'],
            'type' => $e['employment_type'], 'status' => $e['status'], 'admin_id' => (int) $e['admin_id'],
            'salary' => $pay ? mobile_api_money($e['basic_salary']) : '', 'salary_raw' => $pay ? (float) $e['basic_salary'] : 0,
        ];
    }
    $deps = [];
    foreach ($depts as $id => $n) {
        $deps[] = ['id' => $id, 'name' => $n];
    }
    $users = [];
    foreach (mobile_api_user_names() as $id => $n) {
        $users[] = ['id' => $id, 'name' => $n];
    }
    mobile_api_out(['ok' => true, 'employees' => $rows, 'departments' => $deps, 'users' => $users, 'can_pay' => $pay]);
}

function mobile_api_hr_employee_save($s)
{
    $id = (int) mobile_api_in('id', 0);
    $first = trim((string) mobile_api_in('first_name'));
    $last = trim((string) mobile_api_in('last_name'));
    if ($first === '' || $last === '') {
        mobile_api_fail('Enter first and last name', 422);
    }
    $e = $id > 0 ? ORM::for_table('tbl_hr_employees')->find_one($id) : ORM::for_table('tbl_hr_employees')->create();
    if (!$e) {
        mobile_api_fail('Employee not found', 404);
    }
    $hire = trim((string) mobile_api_in('hire_date'));
    if ($hire === '') {
        $hire = $id > 0 ? (string) $e['hire_date'] : date('Y-m-d');
    }
    if (!mobile_api_hr_valid_date($hire)) {
        mobile_api_fail('Hire date must be YYYY-MM-DD', 422);
    }
    $dept = (int) mobile_api_in('department_id', 0);
    if ($dept > 0 && !array_key_exists($dept, mobile_api_hr_depts())) {
        mobile_api_fail('Unknown department', 422);
    }
    $adminId = (int) mobile_api_in('admin_id', 0);
    if ($adminId > 0) {
        if (!ORM::for_table('tbl_users')->find_one($adminId)) {
            mobile_api_fail('Unknown user to link', 422);
        }
        $other = ORM::for_table('tbl_hr_employees')->where('admin_id', $adminId)->where_not_equal('id', $id)->count();
        if ($other) {
            mobile_api_fail('That user is already linked to another employee', 409);
        }
    }
    $status = mobile_api_in('status') === 'Inactive' ? 'Inactive' : 'Active';
    if ($id > 0 && !in_array($e['status'], ['Active', 'Inactive'], true) && mobile_api_in('status', null) === null) {
        $status = $e['status'];
    }
    $e->first_name = $first;
    $e->last_name = $last;
    $e->phone = trim((string) mobile_api_in('phone'));
    $e->email = trim((string) mobile_api_in('email'));
    $e->department_id = $dept > 0 ? $dept : null;
    $e->job_title = trim((string) mobile_api_in('job_title'));
    $e->hire_date = $hire;
    $e->employment_type = trim((string) mobile_api_in('employment_type')) ?: ($id > 0 ? $e['employment_type'] : 'Full-time');
    $e->status = $status;
    $e->admin_id = $adminId > 0 ? $adminId : null;
    if (in_array('hr_payroll', $s['access']['modules'], true) && mobile_api_in('basic_salary', null) !== null) {
        $sal = (float) mobile_api_in('basic_salary');
        if ($sal < 0) {
            mobile_api_fail('Salary cannot be negative', 422);
        }
        $e->basic_salary = $sal;
    }
    $now = date('Y-m-d H:i:s');
    if ($id === 0) {
        $prefix = mobile_api_hr_cfg('hrm_emp_prefix', 'EMP');
        $max = 0;
        foreach (ORM::for_table('tbl_hr_employees')->select('employee_no')->find_many() as $x) {
            if (preg_match('/(\d+)$/', (string) $x['employee_no'], $mm)) {
                $max = max($max, (int) $mm[1]);
            }
        }
        $e->employee_no = sprintf('%s-%04d', $prefix, $max + 1);
        if (mobile_api_in('basic_salary', null) === null || !in_array('hr_payroll', $s['access']['modules'], true)) {
            $e->basic_salary = 0;
        }
        $e->created_by = (int) $s['user_id'];
        $e->created_at = $now;
    }
    $e->updated_at = $now;
    $e->save();
    mobile_api_hr_audit($s, $id > 0 ? 'employee_update' : 'employee_create', 'employee', (int) $e['id'], mobile_api_hr_name($e));
    mobile_api_out(['ok' => true, 'id' => (int) $e['id'], 'message' => $id > 0 ? 'Employee updated' : 'Employee added: ' . $e['employee_no']]);
}

function mobile_api_hr_attendance()
{
    $date = (string) mobile_api_in('date');
    if (!mobile_api_hr_valid_date($date)) {
        $date = date('Y-m-d');
    }
    $shift = (int) mobile_api_in('shift', 0);
    if ($shift !== 0) {
        $date = date('Y-m-d', strtotime(($shift > 0 ? '+' : '') . $shift . ' day', strtotime($date)));
    }
    $today = date('Y-m-d');
    $att = [];
    foreach (ORM::for_table('tbl_hr_attendance')->where('work_date', $date)->find_many() as $a) {
        $att[(int) $a['employee_id']] = $a;
    }
    $onLeave = [];
    foreach (ORM::for_table('tbl_hr_leave_requests')->where('status', 'Approved')->where_lte('start_date', $date)->where_gte('end_date', $date)->find_many() as $l) {
        $onLeave[(int) $l['employee_id']] = true;
    }
    $depts = mobile_api_hr_depts();
    $rows = [];
    $count = ['Present' => 0, 'Late' => 0, 'On Leave' => 0, 'Absent' => 0];
    foreach (ORM::for_table('tbl_hr_employees')->where('status', 'Active')->order_by_asc('first_name')->find_many() as $e) {
        $id = (int) $e['id'];
        $a = isset($att[$id]) ? $att[$id] : null;
        if ($a && !empty($a['clock_in'])) {
            $st = $a['status'];
        } elseif (isset($onLeave[$id])) {
            $st = 'On Leave';
        } else {
            $st = $date > $today ? '-' : ($date === $today ? 'Not in yet' : 'Absent');
        }
        if (isset($count[$st])) {
            $count[$st]++;
        } elseif ($st === 'Not in yet') {
            $count['Absent']++;
        }
        $rows[] = [
            'id' => $id, 'name' => mobile_api_hr_name($e),
            'department' => isset($depts[(int) $e['department_id']]) ? $depts[(int) $e['department_id']] : '',
            'clock_in' => $a ? substr((string) $a['clock_in'], 0, 5) : '', 'clock_out' => $a ? substr((string) $a['clock_out'], 0, 5) : '',
            'status' => $st,
        ];
    }
    mobile_api_out(['ok' => true, 'date' => $date, 'rows' => $rows, 'counts' => $count, 'work_start' => mobile_api_hr_cfg('hrm_work_start', '08:30')]);
}

function mobile_api_hr_leave($s)
{
    $status = trim((string) mobile_api_in('status'));
    $orm = ORM::for_table('tbl_hr_leave_requests')->order_by_desc('id');
    if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $orm->where('status', $status);
    }
    $names = mobile_api_hr_emp_names();
    $mine = mobile_api_hr_my_emp($s['user_id']);
    $mineId = $mine ? (int) $mine['id'] : 0;
    $rows = [];
    foreach ($orm->limit(100)->find_many() as $l) {
        $row = mobile_api_hr_leave_row($l, $names);
        $row['can_decide'] = $l['status'] === 'Pending' && (int) $l['employee_id'] !== $mineId;
        $rows[] = $row;
    }
    mobile_api_out(['ok' => true, 'leaves' => $rows]);
}

function mobile_api_hr_leave_decide($s)
{
    $l = ORM::for_table('tbl_hr_leave_requests')->find_one((int) mobile_api_in('id'));
    if (!$l) {
        mobile_api_fail('Request not found', 404);
    }
    if ($l['status'] !== 'Pending') {
        mobile_api_fail('This request was already ' . strtolower($l['status']), 409);
    }
    $mine = mobile_api_hr_my_emp($s['user_id']);
    if ($mine && (int) $mine['id'] === (int) $l['employee_id']) {
        mobile_api_fail('You cannot decide your own leave request', 403);
    }
    $d = (string) mobile_api_in('decision');
    if (!in_array($d, ['approve', 'reject'], true)) {
        mobile_api_fail('Choose approve or reject', 422);
    }
    $l->status = $d === 'approve' ? 'Approved' : 'Rejected';
    $l->decided_by = (int) $s['user_id'];
    $l->decided_by_name = $s['access']['name'];
    $l->decided_at = date('Y-m-d H:i:s');
    $l->decision_note = substr(trim((string) mobile_api_in('note')), 0, 255);
    $l->save();
    mobile_api_hr_audit($s, 'leave_' . $d, 'leave', (int) $l['id'], $l['leave_type'] . ' ' . $l['start_date'] . ' to ' . $l['end_date']);
    mobile_api_out(['ok' => true, 'message' => 'Leave ' . strtolower($l['status'])]);
}

function mobile_api_hr_payroll()
{
    $month = (string) mobile_api_in('month');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $last = ORM::for_table('tbl_hr_payroll')->order_by_desc('period_month')->find_one();
        $month = $last ? $last['period_month'] : date('Y-m');
    }
    $shift = (int) mobile_api_in('shift', 0);
    if ($shift !== 0) {
        $month = date('Y-m', strtotime(($shift > 0 ? '+' : '') . $shift . ' month', strtotime($month . '-01')));
    }
    $names = mobile_api_hr_emp_names();
    $rows = [];
    $gross = 0;
    $net = 0;
    $paid = 0;
    foreach (ORM::for_table('tbl_hr_payroll')->where('period_month', $month)->find_many() as $p) {
        $gross += (float) $p['gross_pay'];
        $net += (float) $p['net_pay'];
        if (strcasecmp($p['status'], 'Paid') === 0) {
            $paid++;
        }
        $rows[] = [
            'id' => (int) $p['id'],
            'employee' => isset($names[(int) $p['employee_id']]) ? $names[(int) $p['employee_id']] : '#' . $p['employee_id'],
            'basic' => mobile_api_money($p['basic_salary']), 'gross' => mobile_api_money($p['gross_pay']),
            'deductions' => mobile_api_money((float) $p['gross_pay'] - (float) $p['net_pay']),
            'net' => mobile_api_money($p['net_pay']), 'status' => $p['status'],
        ];
    }
    usort($rows, function ($a, $b) { return strcmp($a['employee'], $b['employee']); });
    mobile_api_out(['ok' => true, 'month' => $month, 'rows' => $rows,
        'totals' => ['count' => count($rows), 'paid' => $paid, 'gross' => mobile_api_money($gross), 'net' => mobile_api_money($net)]]);
}
