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
 *                  (default: Tech,Technician). Admins may also log in "as technician".
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

            // ---- technician ----
            case 'tech_jobs':
                mobile_api_need($role, ['technician', 'admin']);
                mobile_api_tech_jobs($s);
            case 'tech_update':
                mobile_api_need($role, ['technician', 'admin']);
                mobile_api_tech_update($s);

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
        ? $config['mobile_api_tech_types'] : 'Tech,Technician';
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

    $admin = ORM::for_table('tbl_users')->where('username', $u)->find_one();
    if ($admin && mobile_api_verify_admin($p, $admin['password'])) {
        if (isset($admin['status']) && $admin['status'] !== 'Active') {
            mobile_api_fail('Account is not active', 403);
        }
        $type = strtolower((string) $admin['user_type']);
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

    mobile_api_out(['ok' => true, 'token' => $token, 'role' => $role, 'name' => $name]);
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

    mobile_api_out(['ok' => true, 'cards' => $cards, 'recent' => $recent]);
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
/* technician (reads the Installations plugin table)                   */
/* ------------------------------------------------------------------ */

function mobile_api_install_table()
{
    foreach (['tbl_installations', 'installations'] as $t) {
        $r = ORM::get_db()->query("SHOW TABLES LIKE '" . $t . "'")->fetch();
        if ($r) {
            return $t;
        }
    }
    return null;
}

function mobile_api_pick($row, $keys)
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
            return (string) $row[$k];
        }
    }
    return '';
}

function mobile_api_tech_statuses()
{
    global $config;
    $raw = isset($config['mobile_api_tech_statuses']) && $config['mobile_api_tech_statuses'] !== ''
        ? $config['mobile_api_tech_statuses'] : 'pending,in_progress';
    return array_map('trim', explode(',', $raw));
}

function mobile_api_tech_jobs($s)
{
    $table = mobile_api_install_table();
    if (!$table) {
        mobile_api_out(['ok' => true, 'jobs' => [], 'statuses' => [], 'note' => 'Installations table not found']);
    }
    $cols = [];
    foreach (ORM::get_db()->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['Field'];
    }
    $orm = ORM::for_table($table)->order_by_desc('id');

    if ($s['role'] === 'technician') {
        foreach (['technician_id', 'tech_id', 'assigned_to', 'assigned_to_id'] as $k) {
            if (in_array($k, $cols, true)) {
                $orm->where($k, $s['user_id']);
                break;
            }
        }
    }
    $status = trim((string) mobile_api_in('status'));
    if ($status !== '' && in_array('status', $cols, true)) {
        $orm->where('status', $status);
    }

    $jobs = [];
    foreach ($orm->limit(100)->find_many() as $r) {
        $row = $r->as_array();
        $jobs[] = [
            'id' => (int) $row['id'],
            'title' => mobile_api_pick($row, ['customer_name', 'client_name', 'fullname', 'name', 'customer', 'username', 'title']),
            'subtitle' => mobile_api_pick($row, ['package', 'plan', 'plan_name', 'service', 'area', 'notes']),
            'phone' => mobile_api_pick($row, ['phone', 'phonenumber', 'mobile', 'customer_phone']),
            'address' => mobile_api_pick($row, ['address', 'location', 'site', 'area']),
            'status' => mobile_api_pick($row, ['status']),
            'created' => mobile_api_pick($row, ['created_at', 'date', 'created']),
        ];
    }
    mobile_api_out(['ok' => true, 'jobs' => $jobs, 'statuses' => mobile_api_tech_statuses()]);
}

function mobile_api_tech_update($s)
{
    $table = mobile_api_install_table();
    if (!$table) {
        mobile_api_fail('Installations table not found', 404);
    }
    $id = (int) mobile_api_in('id');
    $status = trim((string) mobile_api_in('status'));
    // Completion stays inside the Installations plugin so its M-Pesa payment gate is never bypassed.
    if (!in_array($status, mobile_api_tech_statuses(), true)) {
        mobile_api_fail('That status can only be set from the Installations plugin', 403);
    }
    $row = ORM::for_table($table)->find_one($id);
    if (!$row) {
        mobile_api_fail('Job not found', 404);
    }
    $row->status = $status;
    $row->save();
    mobile_api_out(['ok' => true]);
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
