<?php
/**
 * Mobile API for the NuxBill native Android app.
 *
 * Install: copy to  system/plugin/mobile_api.php
 * Endpoint: https://YOUR-PANEL/?_route=plugin/mobile_api&a=<action>
 *
 * Roles
 *   admin       -> tbl_users (SuperAdmin, Admin, Report, Agent, Sales ...)
 *   technician  -> tbl_users whose user_type is listed in $config['mobile_api_tech_types']
 *                  (default: Technician,Tech; the first one is what the app assigns). Admins may also log in "as technician".
 *   client      -> tbl_customers
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
            mobile_api_out(['ok' => true, 'version' => '1.0']);
        }
        if ($a === 'login') {
            mobile_api_login();
        }

        $s = mobile_api_auth();
        $role = $s['role'];

        switch ($a) {
            case 'logout':
                ORM::for_table('tbl_mobile_tokens')->where('id', $s['token_id'])->delete_many();
                mobile_api_out(['ok' => true]);

            // ---- admin ----
            case 'admin_dashboard':
                mobile_api_need($role, ['admin']);
                mobile_api_admin_dashboard();
            case 'admin_customers':
                mobile_api_need($role, ['admin']);
                mobile_api_admin_customers();
            case 'admin_customer':
                mobile_api_need($role, ['admin']);
                mobile_api_admin_customer();
            case 'admin_plans':
                mobile_api_need($role, ['admin']);
                mobile_api_plans(null);
            case 'admin_recharge':
                mobile_api_need($role, ['admin']);
                mobile_api_admin_recharge($s);
            case 'admin_transactions':
                mobile_api_need($role, ['admin']);
                mobile_api_transactions(null);
            case 'admin_users':
                mobile_api_need($role, ['admin']);
                mobile_api_users_list($s);
            case 'admin_user_save':
                mobile_api_need($role, ['admin']);
                mobile_api_user_save($s);
            case 'admin_user_delete':
                mobile_api_need($role, ['admin']);
                mobile_api_user_delete($s);

            // ---- work: leads, tickets, installations ----
            case 'ops_list':
                mobile_api_need($role, ['admin', 'technician']);
                mobile_api_ops_list($s);
            case 'ops_update':
                mobile_api_need($role, ['admin', 'technician']);
                mobile_api_ops_update($s);
            case 'ops_create':
                mobile_api_need($role, ['admin', 'technician']);
                mobile_api_ops_create($s);
            case 'tech_dashboard':
                mobile_api_need($role, ['technician']);
                mobile_api_out(['ok' => true] + mobile_api_ops_overview($s['user_id']));

            // ---- client ----
            case 'client_home':
                mobile_api_need($role, ['client']);
                mobile_api_client_home($s);
            case 'client_plans':
                mobile_api_need($role, ['client']);
                $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
                mobile_api_plans($c ? $c['service_type'] : null);
            case 'client_buy':
                mobile_api_need($role, ['client']);
                mobile_api_client_buy($s);
            case 'client_history':
                mobile_api_need($role, ['client']);
                $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
                mobile_api_transactions($c ? $c['username'] : '-');

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
    ORM::get_db()->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        role VARCHAR(16) NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME NOT NULL,
        last_used DATETIME NULL,
        UNIQUE KEY token_hash (token_hash)
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
    $asTech = mobile_api_in('mode') === 'technician';
    if ($u === '' || $p === '') {
        mobile_api_fail('Enter username and password', 422);
    }

    $role = null;
    $uid = 0;
    $name = '';
    $utype = '';

    $admin = ORM::for_table('tbl_users')->where('username', $u)->find_one();
    if ($admin && mobile_api_verify_admin($p, $admin['password'])) {
        if (isset($admin['status']) && $admin['status'] !== 'Active') {
            mobile_api_fail('Account is not active', 403);
        }
        $utype = (string) $admin['user_type'];
        $type = strtolower($utype);
        if (in_array($type, mobile_api_tech_types(), true)) {
            $role = 'technician';
        } elseif ($asTech && in_array($type, ['superadmin', 'admin'], true)) {
            $role = 'technician';
        } else {
            $role = 'admin';
        }
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

    mobile_api_out(['ok' => true, 'token' => $token, 'role' => $role, 'name' => $name, 'user_type' => $utype]);
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
    return ['token_id' => $row['id'], 'role' => $row['role'], 'user_id' => (int) $row['user_id']];
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

function mobile_api_admin_dashboard()
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

    $ops = mobile_api_safe(function () { return mobile_api_ops_overview(null); }, ['cards' => [], 'work' => new stdClass()]);
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
    return ['won', 'lost', 'closed', 'resolved', 'completed', 'complete', 'done', 'cancelled', 'canceled', 'converted', 'installed', 'rejected'];
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
                ? ['package', 'plan', 'interest', 'source', 'location']
                : ['package', 'plan', 'plan_name', 'service']),
        'phone' => ['phone', 'phonenumber', 'mobile', 'customer_phone', 'msisdn', 'contact'],
        'address' => ['address', 'location', 'site', 'area', 'estate'],
        'status' => ['status', 'state', 'stage'],
        'created' => ['created_at', 'created', 'creationdate', 'date', 'time'],
        'updated' => ['updated_at', 'updated', 'modified_at'],
        'priority' => ['priority', 'urgency'],
        'notes' => ['notes', 'note', 'description', 'message', 'details'],
        'assignee' => ['technician_id', 'tech_id', 'assigned_to', 'assigned_to_id', 'assigned_id', 'assignee_id', 'assigned_tech', 'technician', 'assigned_to_name'],
    ];
    $map = [];
    foreach ($cand as $k => $list) {
        $map[$k] = mobile_api_ops_first($cols, $list);
    }
    $assigneeInt = $map['assignee'] ? stripos($cols[strtolower($map['assignee'])]['Type'], 'int') !== false : false;

    return $cache[$entity] = [
        'entity' => $entity, 'table' => $table, 'cols' => $cols, 'map' => $map,
        'assignee_int' => $assigneeInt, 'fallback' => $fallback,
    ];
}

function mobile_api_ops_statuses($meta)
{
    global $config;
    $e = $meta['entity'];
    if ($e === 'install') {
        return mobile_api_tech_statuses();
    }
    if (isset($config['mobile_api_statuses_' . $e]) && trim($config['mobile_api_statuses_' . $e]) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $config['mobile_api_statuses_' . $e]))));
    }
    $defaults = $e === 'lead' ? ['new', 'contacted', 'qualified', 'won', 'lost'] : ['open', 'in_progress', 'resolved', 'closed'];
    $sc = $meta['map']['status'];
    if (!$sc || $meta['fallback']) {
        return $defaults;
    }
    $type = $meta['cols'][strtolower($sc)]['Type'];
    if (stripos($type, 'enum(') === 0 && preg_match_all("/'((?:[^']|'')*)'/", $type, $m)) {
        return array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
    }
    $vals = [];
    foreach (ORM::get_db()->query('SELECT DISTINCT `' . $sc . '` AS v FROM `' . $meta['table'] . '` LIMIT 20')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['v'] !== null && $r['v'] !== '') {
            $vals[] = (string) $r['v'];
        }
    }
    return $vals ? $vals : $defaults;
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

function mobile_api_ops_techs()
{
    $types = mobile_api_tech_types();
    $out = [];
    foreach (ORM::for_table('tbl_users')->order_by_asc('fullname')->find_many() as $u) {
        if (in_array(strtolower((string) $u['user_type']), $types, true) && (!isset($u['status']) || $u['status'] === 'Active')) {
            $out[] = ['id' => (int) $u['id'], 'name' => $u['fullname'] ?: $u['username']];
        }
    }
    return $out;
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
    $aid = 0;
    $aname = '';
    if ($m['assignee'] && isset($a[$m['assignee']]) && $a[$m['assignee']] !== '' && $a[$m['assignee']] !== null) {
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
    return [
        'id' => (int) $a['id'],
        'title' => $title !== '' ? $title : ('#' . $a['id']),
        'subtitle' => $sub,
        'notes' => $notes,
        'phone' => $g('phone'),
        'address' => $g('address'),
        'status' => $g('status'),
        'priority' => $g('priority'),
        'created' => $g('created'),
        'assignee_id' => $aid,
        'assignee' => $aname,
    ];
}

/** Restrict a query to items assigned to technician $uid (no-op if the table has no assignee column). */
function mobile_api_ops_scope($orm, $meta, $uid)
{
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
function mobile_api_ops_overview($scopeUid)
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

function mobile_api_ops_list($s)
{
    $e = mobile_api_ops_entity();
    $meta = mobile_api_ops_meta($e);
    $isAdmin = $s['role'] === 'admin';
    if (!$meta) {
        mobile_api_out(['ok' => true, 'items' => [], 'statuses' => [], 'techs' => [], 'can_create' => false,
            'can_assign' => false, 'note' => 'No ' . $e . ' table found. Set mobile_api_table_' . $e . ' to its table name.']);
    }
    $orm = ORM::for_table($meta['table'])->order_by_desc('id');
    if (!$isAdmin) {
        mobile_api_ops_scope($orm, $meta, $s['user_id']);
    }
    $items = [];
    foreach ($orm->limit(150)->find_many() as $r) {
        $items[] = mobile_api_ops_item($meta, $r->as_array());
    }
    mobile_api_out([
        'ok' => true,
        'items' => $items,
        'statuses' => mobile_api_ops_statuses($meta),
        'techs' => $isAdmin ? mobile_api_ops_techs() : [],
        'can_create' => $e !== 'install',
        'can_assign' => $isAdmin && (bool) $meta['map']['assignee'],
        'note' => $meta['map']['assignee'] ? '' : 'This table has no assignee column, so items cannot be assigned.',
    ]);
}

function mobile_api_ops_set_assignee($row, $meta, $techId)
{
    $c = $meta['map']['assignee'];
    if (!$c) {
        mobile_api_fail('This table has no assignee column', 422);
    }
    if ($techId <= 0) {
        $row->set($c, $meta['assignee_int'] ? null : '');
        return;
    }
    $t = ORM::for_table('tbl_users')->find_one($techId);
    if (!$t || !in_array(strtolower((string) $t['user_type']), mobile_api_tech_types(), true)) {
        mobile_api_fail('Pick a technician', 422);
    }
    $row->set($c, $meta['assignee_int'] ? (int) $t['id'] : ($t['fullname'] ?: $t['username']));
}

function mobile_api_ops_update($s)
{
    $e = mobile_api_ops_entity();
    $meta = mobile_api_ops_meta($e);
    if (!$meta) {
        mobile_api_fail('Table not found', 404);
    }
    $row = ORM::for_table($meta['table'])->find_one((int) mobile_api_in('id'));
    if (!$row) {
        mobile_api_fail('Item not found', 404);
    }
    $isAdmin = $s['role'] === 'admin';
    if (!$isAdmin && !mobile_api_ops_is_mine($meta, $row->as_array(), $s['user_id'])) {
        mobile_api_fail('This item is not assigned to you', 403);
    }

    $status = trim((string) mobile_api_in('status'));
    if ($status !== '') {
        // Completing an installation stays in the Installations plugin so its M-Pesa payment gate is never bypassed.
        if (!in_array($status, mobile_api_ops_statuses($meta), true)) {
            mobile_api_fail($e === 'install'
                ? 'That status can only be set from the Installations plugin'
                : 'Invalid status', 403);
        }
        if (!$meta['map']['status']) {
            mobile_api_fail('This table has no status column', 422);
        }
        $row->set($meta['map']['status'], $status);
    }

    $assign = mobile_api_in('assignee_id', null);
    if ($assign !== null && $assign !== '' || mobile_api_in('unassign') === '1') {
        if (!$isAdmin) {
            mobile_api_fail('Only admins can assign work', 403);
        }
        mobile_api_ops_set_assignee($row, $meta, mobile_api_in('unassign') === '1' ? 0 : (int) $assign);
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
    if ($e === 'install') {
        mobile_api_fail('Create installations from the Installations plugin', 403);
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

    if ($m['assignee']) {
        $assign = $s['role'] === 'admin' ? (int) mobile_api_in('assignee_id', 0) : (int) $s['user_id'];
        if ($assign > 0) {
            mobile_api_ops_set_assignee($r, $meta, $assign);
        }
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
    global $config;
    $raw = isset($config['mobile_api_tech_types']) && $config['mobile_api_tech_types'] !== ''
        ? $config['mobile_api_tech_types'] : 'Technician,Tech';
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $tech = $parts ? $parts[0] : 'Technician';
    return ['SuperAdmin', 'Admin', 'Agent', 'Sales', 'Report', $tech];
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
        ->where_in('role', ['admin', 'technician'])
        ->delete_many();
}

function mobile_api_hash_pw($pw)
{
    if (class_exists('Password') && method_exists('Password', '_crypt')) {
        return Password::_crypt($pw);
    }
    return password_hash($pw, PASSWORD_DEFAULT);
}

function mobile_api_users_list($s)
{
    $actor = mobile_api_actor($s);
    mobile_api_need_manager($actor);
    $out = [];
    foreach (ORM::for_table('tbl_users')->order_by_asc('username')->find_many() as $u) {
        $self = (int) $u['id'] === (int) $actor['id'];
        $out[] = [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'fullname' => $u['fullname'],
            'phone' => $u['phone'],
            'email' => $u['email'],
            'role' => $u['user_type'],
            'status' => $u['status'],
            'last_login' => isset($u['last_login']) ? (string) $u['last_login'] : '',
            'self' => $self,
            'can_edit' => $self || mobile_api_can_manage($actor, $u['user_type']),
        ];
    }
    mobile_api_out(['ok' => true, 'users' => $out, 'roles' => mobile_api_assignable_roles($actor)]);
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
    $u->delete();
    mobile_api_out(['ok' => true, 'message' => 'User deleted']);
}
