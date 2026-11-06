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

        if ($a === 'mpesa_cb') {
            mobile_api_mpesa_cb();
        }

        $s = mobile_api_auth();

        // offline queue: an action replayed with an op_id we already applied is acknowledged, not applied twice
        $opId = trim((string) mobile_api_in('op_id'));
        $opId = ($opId !== '' && preg_match('/^[A-Za-z0-9_-]{8,40}$/', $opId) && in_array($a, mobile_api_queueable(), true)) ? $opId : '';
        $GLOBALS['mobile_api_ctx'] = ['a' => $a, 's' => $s, 'op_id' => $opId];
        if ($opId !== '' && mobile_api_rows('SELECT op_id FROM tbl_mobile_ops WHERE op_id = ? AND user_id = ?', [$opId, (int) $s['user_id']])) {
            $GLOBALS['mobile_api_ctx'] = null;
            mobile_api_out(['ok' => true, 'duplicate' => true, 'message' => 'Already applied']);
        }

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

            // ---- staff: tickets need a customer and a category ----
            case 'customer_lookup':
                mobile_api_any_mod($s, ['support', 'customers']);
                mobile_api_customer_lookup();
            case 'ticket_meta':
                mobile_api_any_mod($s, ['support']);
                mobile_api_ticket_meta();

            // ---- staff: network ----
            case 'onu_list':
                mobile_api_mod($s, 'onu');
                mobile_api_onu_list();
            case 'onu_detail':
                mobile_api_mod($s, 'onu');
                mobile_api_onu_detail();
            case 'onu_reboot':
                mobile_api_mod($s, 'onu');
                mobile_api_onu_reboot($s);
            case 'usage_overview':
                mobile_api_mod($s, 'usage');
                mobile_api_usage_overview();
            case 'usage_user':
                mobile_api_mod($s, 'usage');
                mobile_api_usage_user();
            case 'client_usage':
                mobile_api_client_only($s);
                mobile_api_client_usage($s);

            // ---- notifications, customer 360, visits ----
            case 'notify_poll':
                mobile_api_notify_poll($s);
            case 'customer_360':
                mobile_api_any_mod($s, ['customers', 'support', 'installations']);
                mobile_api_customer_360($s);
            case 'photo_add':
                mobile_api_photo_add($s);
            case 'photo_get':
                mobile_api_photo_get($s);
            case 'visit_info':
                mobile_api_visit_info($s);
            case 'checkin':
                mobile_api_checkin($s);

            // ---- staff: audit log ----
            case 'report':
                mobile_api_mod($s, 'reports');
                mobile_api_report();

            case 'report_export':
                mobile_api_mod($s, 'reports');
                mobile_api_report_export();

            // ---- client extras ----
            case 'client_stk':
                mobile_api_client_only($s);
                mobile_api_client_stk($s);
            case 'client_stk_status':
                mobile_api_client_only($s);
                mobile_api_client_stk_status($s);
            case 'client_tickets':
                mobile_api_client_only($s);
                mobile_api_client_tickets($s);
            case 'client_ticket_create':
                mobile_api_client_only($s);
                mobile_api_client_ticket_create($s);
            case 'client_ticket_comments':
                mobile_api_client_only($s);
                mobile_api_client_ticket_comments($s);
            case 'client_ticket_comment_add':
                mobile_api_client_only($s);
                mobile_api_client_ticket_comment_add($s);
            case 'client_notices':
                mobile_api_client_only($s);
                mobile_api_client_notices();
            case 'client_wifi_get':
                mobile_api_client_only($s);
                mobile_api_client_wifi_get($s);
            case 'client_wifi_set':
                mobile_api_client_only($s);
                mobile_api_client_wifi_set($s);

            // ---- notices (staff) ----
            case 'notice_list':
                mobile_api_mod($s, 'notices');
                mobile_api_notice_list();
            case 'notice_save':
                mobile_api_mod($s, 'notices');
                mobile_api_notice_save($s);
            case 'notice_end':
                mobile_api_mod($s, 'notices');
                mobile_api_notice_end($s);

            // ---- ticket comments (staff) ----
            case 'ticket_comments':
                mobile_api_ticket_comments($s);
            case 'ticket_comment_add':
                mobile_api_ticket_comment_add($s);

            // ---- security ----
            case 'sessions_list':
                mobile_api_sessions_list($s);
            case 'session_revoke':
                mobile_api_session_revoke($s);
            case 'admin_user_logout':
                mobile_api_staff($s);
                mobile_api_admin_user_logout($s);

            // ---- installation payments ----
            case 'inst_pay_list':
                mobile_api_mod($s, 'install_pay');
                mobile_api_instpay_list($s);
            case 'inst_pay_detail':
                mobile_api_mod($s, 'install_pay');
                mobile_api_instpay_detail($s);
            case 'inst_pay_record':
                mobile_api_mod($s, 'install_pay');
                mobile_api_instpay_record($s);
            case 'inst_pay_waive':
                mobile_api_mod($s, 'install_pay');
                mobile_api_instpay_waive($s);

            // ---- store ----
            case 'store_overview':
                mobile_api_mod($s, 'store');
                mobile_api_store_overview($s);
            case 'store_meta':
                mobile_api_mod($s, 'store');
                mobile_api_store_meta($s);
            case 'store_requests':
                mobile_api_mod($s, 'store');
                mobile_api_store_requests($s);
            case 'store_request_create':
                mobile_api_mod($s, 'store');
                mobile_api_store_request_create($s);
            case 'store_request_decide':
                mobile_api_mod($s, 'store');
                mobile_api_store_request_decide($s);
            case 'store_receive':
                mobile_api_mod($s, 'store');
                mobile_api_store_receive($s);
            case 'store_use':
                mobile_api_mod($s, 'store');
                mobile_api_store_use($s);

            case 'audit_list':
                mobile_api_mod($s, 'audit');
                mobile_api_audit_list($s);

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
    if (!empty($GLOBALS['mobile_api_ctx']) && is_array($data)) {
        mobile_api_finish($data, $code);
    }
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
    $defs = [
        'tbl_mobile_tokens' => [
            ['token_hash', 'role', 'user_id', 'created_at', 'last_used'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                role VARCHAR(16) NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                last_used DATETIME NULL,
                UNIQUE KEY token_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_access' => [
            ['role_key', 'modules', 'scope', 'can_assign', 'can_create', 'updated_by', 'updated_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_access (
                role_key VARCHAR(64) NOT NULL PRIMARY KEY,
                modules TEXT NOT NULL,
                scope VARCHAR(8) NOT NULL DEFAULT 'mine',
                can_assign TINYINT(1) NOT NULL DEFAULT 0,
                can_create TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_audit' => [
            ['user_id', 'user_name', 'user_role', 'action', 'entity', 'entity_id', 'details', 'ip', 'client_time', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_audit (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL DEFAULT 0,
                user_name VARCHAR(100) NULL,
                user_role VARCHAR(30) NULL,
                action VARCHAR(40) NOT NULL,
                entity VARCHAR(30) NULL,
                entity_id INT NULL,
                details VARCHAR(500) NULL,
                ip VARCHAR(45) NULL,
                client_time DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_created (created_at),
                KEY idx_user (user_id),
                KEY idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_notify' => [
            ['user_id', 'kind', 'title', 'body', 'entity', 'entity_id', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_notify (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                kind VARCHAR(30) NOT NULL,
                title VARCHAR(150) NOT NULL,
                body VARCHAR(300) NULL,
                entity VARCHAR(20) NULL,
                entity_id INT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_user (user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_photos' => [
            ['entity', 'entity_id', 'user_id', 'user_name', 'file', 'caption', 'lat', 'lng', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity VARCHAR(20) NOT NULL,
                entity_id INT NOT NULL,
                user_id INT NOT NULL,
                user_name VARCHAR(100) NULL,
                file VARCHAR(80) NOT NULL,
                caption VARCHAR(200) NULL,
                lat DECIMAL(10,7) NULL,
                lng DECIMAL(10,7) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_item (entity, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_checkins' => [
            ['entity', 'entity_id', 'user_id', 'user_name', 'kind', 'lat', 'lng', 'accuracy', 'distance_m', 'client_time', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_checkins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity VARCHAR(20) NOT NULL,
                entity_id INT NOT NULL,
                user_id INT NOT NULL,
                user_name VARCHAR(100) NULL,
                kind VARCHAR(10) NOT NULL,
                lat DECIMAL(10,7) NULL,
                lng DECIMAL(10,7) NULL,
                accuracy INT NULL,
                distance_m INT NULL,
                client_time DATETIME NULL,
                created_at DATETIME NOT NULL,
                KEY idx_item (entity, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_comments' => [
            ['ticket_id', 'author_type', 'author_id', 'author_name', 'body', 'internal', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                author_type VARCHAR(8) NOT NULL,
                author_id INT NOT NULL DEFAULT 0,
                author_name VARCHAR(100) NULL,
                body VARCHAR(1000) NOT NULL,
                internal TINYINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_notices' => [
            ['title', 'body', 'severity', 'active', 'ends_at', 'created_by', 'created_by_name', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_notices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(120) NOT NULL,
                body VARCHAR(500) NULL,
                severity VARCHAR(10) NOT NULL DEFAULT 'info',
                active TINYINT NOT NULL DEFAULT 1,
                ends_at DATETIME NULL,
                created_by INT NOT NULL DEFAULT 0,
                created_by_name VARCHAR(100) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_stk' => [
            ['customer_id', 'phone', 'amount', 'checkout_id', 'status', 'result_desc', 'created_at', 'updated_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_stk (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                phone VARCHAR(20) NOT NULL,
                amount INT NOT NULL,
                checkout_id VARCHAR(64) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                result_desc VARCHAR(200) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                KEY idx_cust (customer_id),
                KEY idx_checkout (checkout_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_token_info' => [
            ['token_id', 'device', 'ip'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_token_info (
                token_id INT NOT NULL PRIMARY KEY,
                device VARCHAR(80) NULL,
                ip VARCHAR(45) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_ops' => [
            ['op_id', 'user_id', 'action', 'created_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_ops (
                op_id VARCHAR(40) NOT NULL PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'tbl_mobile_lead_assign' => [
            ['lead_id', 'user_id', 'assigned_at'],
            "CREATE TABLE IF NOT EXISTS tbl_mobile_lead_assign (
                lead_id INT NOT NULL PRIMARY KEY,
                user_id INT NOT NULL,
                assigned_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
    ];

    // One query: which columns do our tables really have? CREATE TABLE IF NOT EXISTS never fixes a table
    // that already exists with a different shape (left by an older version or another tool).
    $have = [];
    $in = "'" . implode("','", array_keys($defs)) . "'";
    foreach ($db->query("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . $in . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $have[strtolower($r['t'])][] = strtolower($r['c']);
    }
    foreach ($defs as $table => $def) {
        if (isset($have[$table]) && array_diff($def[0], $have[$table])) {
            // keep the old data, just move it out of the way (these tables hold only app settings and sessions)
            $db->exec('RENAME TABLE ' . $table . ' TO ' . $table . '_old_' . date('YmdHis'));
        }
        $db->exec($def[1]);
    }
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

    // slow down password guessing: too many recent failures for this name or from this address
    try {
        $since = date('Y-m-d H:i:s', time() - 900);
        $byName = mobile_api_rows("SELECT COUNT(*) n FROM tbl_mobile_audit WHERE action = 'login_failed' AND user_name = ? AND created_at > ?", [mb_substr($u, 0, 100), $since]);
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $byIp = $ip === '' ? [['n' => 0]] : mobile_api_rows("SELECT COUNT(*) n FROM tbl_mobile_audit WHERE action = 'login_failed' AND ip = ? AND created_at > ?", [$ip, $since]);
        if ((int) $byName[0]['n'] >= 8 || (int) $byIp[0]['n'] >= 30) {
            mobile_api_fail('Too many failed attempts. Try again in 15 minutes.', 429);
        }
    } catch (Throwable $e) {
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
        mobile_api_audit(0, $u, '', 'login_failed', 'session', null, 'wrong username or password');
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
    try {
        ORM::get_db()->prepare('INSERT INTO tbl_mobile_token_info (token_id, device, ip) VALUES (?,?,?)')
            ->execute([(int) $t['id'], mb_substr(trim((string) mobile_api_in('device')), 0, 80), isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '']);
    } catch (Throwable $e) {
    }

    $out = ['ok' => true, 'token' => $token, 'role' => $role, 'name' => $name, 'user_type' => ''];
    if ($role === 'staff') {
        $acc = mobile_api_access($uid);
        $out['user_type'] = $acc['user_type'];
        $out['access'] = mobile_api_access_out($acc);
    }
    mobile_api_audit($uid, $name, $role === 'staff' ? $out['user_type'] : 'client', 'login', 'session', null, '');
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
        'sla' => mobile_api_sla($meta, $a),
        'pay' => ($meta['entity'] === 'install' && isset($a['pay_status'])) ? [
            'status' => (string) $a['pay_status'], 'due' => mobile_api_money(isset($a['amount_due']) ? $a['amount_due'] : 0),
            'paid' => mobile_api_money(isset($a['amount_paid']) ? $a['amount_paid'] : 0),
        ] : null,
        'customer_username' => ($meta['entity'] === 'ticket' && isset($a['description']) && preg_match('/^Username:\s*(\S+)/mi', (string) $a['description'], $mm)) ? $mm[1] : '',
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
    // past-SLA work first, the most overdue at the very top
    $overdue = 0;
    foreach ($items as $it) {
        if (!empty($it['sla']['overdue'])) $overdue++;
    }
    usort($items, function ($x, $y) {
        $a = !empty($x['sla']['overdue']);
        $b = !empty($y['sla']['overdue']);
        if ($a !== $b) return $a ? -1 : 1;
        if ($a && $b) return $x['sla']['left_min'] <=> $y['sla']['left_min'];
        return $y['id'] <=> $x['id'];
    });
    $hasAssign = (bool) ($meta['map']['assignee'] || !empty($meta['sidecar']));
    mobile_api_out([
        'ok' => true,
        'items' => $items,
        'overdue' => $overdue,
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
            if (!$s || (int) $s['user_id'] !== (int) $techId) {
                $ti = mobile_api_ops_item($meta, $row->as_array());
                mobile_api_notify((int) $techId, 'assigned', 'Lead assigned to you', $ti['title'], 'lead', (int) $row['id']);
            }
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
    if (!$s || (int) $s['user_id'] !== (int) $techId) {
        $ti = mobile_api_ops_item($meta, $row->as_array());
        mobile_api_notify((int) $techId, 'assigned', ucfirst($meta['entity'] === 'install' ? 'installation' : $meta['entity']) . ' assigned to you', $ti['title'], $meta['entity'], (int) $row['id']);
    }
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
        $cur = strtolower(trim((string) $row[$meta['map']['status']]));
        if ($e === 'install' && in_array($cur, ['completed', 'complete', 'cancelled', 'failed', 'awaiting_payment'], true)) {
            // finished / payment-gated jobs are final here: the customer exists and the M-Pesa gate has run
            mobile_api_fail('This installation is ' . str_replace('_', ' ', $cur) . '. Manage it in the Installations plugin.', 409);
        }
        if ($e === 'ticket' && $cur === 'closed' && empty($s['access']['assign'])) {
            mobile_api_fail('Closed tickets can only be reopened by a manager', 403);
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
    if ($e === 'ticket') {
        // a ticket is about a customer: pick one, and the details are filled in the same way the website writes them
        $cid = (int) mobile_api_in('customer_id', 0);
        if ($cid > 0) {
            $cu = ORM::for_table('tbl_customers')->find_one($cid);
            if (!$cu) {
                mobile_api_fail('That customer no longer exists', 422);
            }
            if ($phone === '') $phone = (string) $cu['phonenumber'];
            if ($address === '') $address = (string) $cu['address'];
            $head = 'Customer: ' . ($cu['fullname'] ?: $cu['username']) . ' (' . $cu['phonenumber'] . ')';
            if ($address !== '') $head .= "\nLocation: " . $address;
            $head .= "\nUsername: " . $cu['username'];
            $notes = $head . ($notes !== '' ? "\n\n" . $notes : '');
            if (isset($meta['cols']['customer_id'])) $r->set('customer_id', $cid);
        }
        $cat = (int) mobile_api_in('category_id', 0);
        if ($cat > 0 && isset($meta['cols']['category_id'])) {
            $okc = false;
            try { $okc = (bool) mobile_api_rows('SELECT id FROM tbl_support_categories WHERE id = ?', [$cat]); } catch (Throwable $x) { $okc = true; }
            if ($okc) $r->set('category_id', $cat);
        }
        $pr = ucfirst(strtolower(trim((string) mobile_api_in('priority'))));
        $_POST['priority'] = in_array($pr, ['Low', 'Medium', 'High'], true) ? $pr : 'Medium';
    }
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
        ['key' => 'reports', 'label' => 'Reports: revenue, expiring, team', 'group' => 'General'],
        ['key' => 'leads', 'label' => 'Leads', 'group' => 'Operations'],
        ['key' => 'installations', 'label' => 'Installations', 'group' => 'Operations'],
        ['key' => 'support', 'label' => 'Support tickets', 'group' => 'Operations'],
        ['key' => 'store', 'label' => 'Store: stock, requests, usage', 'group' => 'Operations'],
        ['key' => 'install_pay', 'label' => 'Installation payments', 'group' => 'Operations'],
        ['key' => 'my_hr', 'label' => 'My HR: clock in, leave, payslips', 'group' => 'HR'],
        ['key' => 'hr_employees', 'label' => 'Employees', 'group' => 'HR'],
        ['key' => 'hr_attendance', 'label' => 'Attendance', 'group' => 'HR'],
        ['key' => 'hr_leave', 'label' => 'Leave approvals', 'group' => 'HR'],
        ['key' => 'hr_payroll', 'label' => 'Payroll (view only)', 'group' => 'HR'],
        ['key' => 'onu', 'label' => 'ONU monitoring', 'group' => 'Network'],
        ['key' => 'usage', 'label' => 'Data usage and FUP', 'group' => 'Network'],
        ['key' => 'notices', 'label' => 'Outage notices to customers', 'group' => 'Network'],
        ['key' => 'audit', 'label' => 'Audit log', 'group' => 'Admin'],
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
            return ['modules' => ['installations', 'support', 'leads', 'store', 'onu', 'my_hr'], 'scope' => 'mine', 'assign' => false, 'create' => true];
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
        if (strpos($p, 'network.') === 0) $mods[] = 'onu';
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

/** Which access records apply to a user. Used both to enforce access and to show the admin who each role covers. */
function mobile_api_user_role_keys($u)
{
    $uid = (int) $u['id'];
    $keys = [];
    if (mobile_api_is_tech($uid)) {
        $keys[] = 'tech';
    }
    foreach (mobile_api_safe(function () use ($uid) {
        return ORM::for_table('tbl_staff_user_roles')->where('user_id', $uid)->find_many();
    }, []) as $r) {
        $keys[] = 'role:' . (int) $r['role_id'];
    }
    if (!$keys || (string) $u['user_type'] === 'Admin') {
        array_unshift($keys, 'type:' . $u['user_type']);
    }
    return $keys;
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
    $keys = mobile_api_user_role_keys($u);
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
    $members = [];
    foreach (mobile_api_safe(function () {
        return ORM::for_table('tbl_users')->where('status', 'Active')->where_not_equal('user_type', 'SuperAdmin')->order_by_asc('fullname')->find_many();
    }, []) as $u) {
        foreach (mobile_api_user_role_keys($u) as $key) {
            $members[$key][] = $u['fullname'] ?: $u['username'];
        }
    }
    foreach (mobile_api_access_keys() as $k) {
        $rec = mobile_api_access_rec($k[0], $custom);
        if (!$rec) {
            $rec = ['modules' => ['my_hr'], 'scope' => 'mine', 'assign' => false, 'create' => false];
        }
        $roles[] = ['key' => $k[0], 'label' => $k[1], 'kind' => $k[2], 'custom' => $custom,
            'modules' => $rec['modules'], 'scope' => $rec['scope'], 'assign' => $rec['assign'], 'create' => $rec['create'],
            'members' => isset($members[$k[0]]) ? $members[$k[0]] : []];
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
    // a clock tap made offline carries its real time (epoch seconds); accept only a sane, recent, non-future time
    $at = (int) mobile_api_in('at', 0);
    $ts = ($at > 0 && $at <= time() + 300 && $at >= time() - 3 * 86400) ? $at : time();
    $today = date('Y-m-d', $ts);
    $now = date('H:i:s', $ts);
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


/* ------------------------------------------------------------------ */
/* audit log and offline replay                                        */
/* ------------------------------------------------------------------ */

function mobile_api_rows($sql, $args = [])
{
    $st = ORM::get_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Actions the phone may queue while offline and replay later. Each is safe to receive twice thanks to op_id. */
function mobile_api_queueable()
{
    return ['ops_create', 'ops_update', 'hr_clock', 'hr_leave_request', 'checkin', 'store_request_create', 'store_use', 'ticket_comment_add'];
}

/** Actions that change something, and so belong in the audit log. */
function mobile_api_audited()
{
    return [
        'ops_create' => 'create', 'ops_update' => 'update', 'admin_recharge' => 'recharge',
        'admin_user_save' => 'user_save', 'admin_user_delete' => 'user_delete',
        'access_save' => 'access_save', 'access_reset' => 'access_reset',
        'client_buy' => 'purchase', 'hr_clock' => 'clock', 'hr_leave_request' => 'leave_request',
        'hr_employee_save' => 'employee_save', 'hr_leave_decide' => 'leave_decide',
        'onu_reboot' => 'onu_reboot', 'logout' => 'logout', 'photo_add' => 'photo', 'checkin' => 'checkin',
        'store_request_create' => 'stock_request', 'store_request_decide' => 'stock_decide', 'store_receive' => 'stock_receive', 'store_use' => 'stock_use',
        'client_stk' => 'stk_push', 'client_ticket_create' => 'client_ticket', 'client_wifi_set' => 'wifi_change', 'notice_save' => 'notice', 'notice_end' => 'notice_end',
        'ticket_comment_add' => 'comment', 'client_ticket_comment_add' => 'comment', 'session_revoke' => 'session_revoke', 'admin_user_logout' => 'user_logout',
        'inst_pay_record' => 'install_payment', 'inst_pay_waive' => 'install_waive',
    ];
}

function mobile_api_audit($uid, $name, $role, $action, $entity, $entityId, $details)
{
    try {
        $ct = null;
        $at = (int) mobile_api_in('queued_at', 0);
        if ($at > 0 && $at <= time() + 300 && $at >= time() - 30 * 86400) {
            $ct = date('Y-m-d H:i:s', $at);
        }
        $st = ORM::get_db()->prepare('INSERT INTO tbl_mobile_audit (user_id, user_name, user_role, action, entity, entity_id, details, ip, client_time, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([(int) $uid, mb_substr((string) $name, 0, 100), mb_substr((string) $role, 0, 30), $action,
            $entity === '' ? null : mb_substr((string) $entity, 0, 30), $entityId === null ? null : (int) $entityId,
            mb_substr((string) $details, 0, 500), isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            $ct, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // the audit trail must never block the action itself
    }
}

/** Called from mobile_api_out for every authenticated reply: records writes, denials, and applied op_ids. */
function mobile_api_finish($data, $code)
{
    $c = $GLOBALS['mobile_api_ctx'];
    $GLOBALS['mobile_api_ctx'] = null;
    try {
        $s = $c['s'];
        $a = $c['a'];
        $ok = $code < 400 && !empty($data['ok']);
        $uid = (int) $s['user_id'];
        $name = $s['role'] === 'staff' ? $s['access']['name'] : 'client #' . $uid;
        $role = $s['role'] === 'staff' ? ($s['access']['super'] ? 'SuperAdmin' : (isset($s['access']['user_type']) ? $s['access']['user_type'] : 'staff')) : 'client';
        $audited = mobile_api_audited();
        if ($code === 403 && isset($data['error'])) {
            mobile_api_audit($uid, $name, $role, 'denied', 'action', null, $a . ': ' . $data['error']);
            return;
        }
        if (!isset($audited[$a])) {
            return;
        }
        $entity = (string) mobile_api_in('entity');
        $parts = [];
        foreach (['title', 'status', 'assignee_id', 'username', 'customer_id', 'act', 'plan_id', 'id', 'from', 'to', 'decision'] as $k) {
            $v = mobile_api_in($k);
            if ($v !== '' && is_scalar($v)) $parts[] = $k . '=' . mb_substr((string) $v, 0, 60);
        }
        $id = isset($data['id']) ? (int) $data['id'] : (int) mobile_api_in('id', 0);
        if ($ok) {
            mobile_api_notify_after($a, $data, $s);
        }
        $state = $ok ? '' : 'FAILED: ' . (isset($data['error']) ? $data['error'] : $code) . ' ';
        mobile_api_audit($uid, $name, $role, $audited[$a], $entity !== '' ? $entity : null, $id > 0 ? $id : null, $state . implode(', ', $parts));
        if ($ok && $c['op_id'] !== '') {
            ORM::get_db()->prepare('INSERT IGNORE INTO tbl_mobile_ops (op_id, user_id, action, created_at) VALUES (?,?,?,?)')
                ->execute([$c['op_id'], $uid, $a, date('Y-m-d H:i:s')]);
            if (mt_rand(1, 50) === 1) {
                ORM::get_db()->exec("DELETE FROM tbl_mobile_ops WHERE created_at < '" . date('Y-m-d H:i:s', time() - 14 * 86400) . "'");
            }
        }
    } catch (Throwable $e) {
        // never block the reply
    }
}

function mobile_api_any_mod($s, $mods)
{
    mobile_api_staff($s);
    foreach ($mods as $m) {
        if (in_array($m, $s['access']['modules'], true)) return;
    }
    mobile_api_fail('Your role cannot open this section', 403);
}

function mobile_api_audit_list($s)
{
    $where = [];
    $args = [];
    $q = trim((string) mobile_api_in('q'));
    if ($q !== '') {
        $where[] = '(user_name LIKE ? OR details LIKE ? OR action LIKE ?)';
        $args = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    $act = trim((string) mobile_api_in('action'));
    if ($act !== '') {
        $where[] = 'action = ?';
        $args[] = $act;
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $page = max(0, (int) mobile_api_in('page', 0));
    $rows = mobile_api_rows('SELECT id, user_name, user_role, action, entity, entity_id, details, ip, client_time, created_at FROM tbl_mobile_audit' . $w
        . ' ORDER BY id DESC LIMIT 60 OFFSET ' . ($page * 60), $args);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'user' => $r['user_name'], 'role' => $r['user_role'], 'action' => $r['action'],
            'entity' => $r['entity'], 'entity_id' => $r['entity_id'], 'details' => $r['details'], 'ip' => $r['ip'],
            'time' => $r['created_at'], 'queued_time' => $r['client_time'], 'source' => 'app'];
    }
    // HR actions made on the website are in their own table; show them alongside when asked
    if ($page === 0 && (string) mobile_api_in('include_hr') === '1' && $q === '' && $act === '') {
        try {
            foreach (mobile_api_rows('SELECT id, actor_name, action, entity_type, entity_id, details, ip_address, created_at FROM tbl_hr_audit_logs ORDER BY id DESC LIMIT 30') as $r) {
                $out[] = ['id' => 0, 'user' => $r['actor_name'], 'role' => '', 'action' => $r['action'], 'entity' => $r['entity_type'],
                    'entity_id' => $r['entity_id'], 'details' => $r['details'], 'ip' => $r['ip_address'], 'time' => $r['created_at'],
                    'queued_time' => null, 'source' => 'hr'];
            }
            usort($out, function ($x, $y) { return strcmp($y['time'], $x['time']); });
        } catch (Throwable $e) {
        }
    }
    $kinds = [];
    foreach (mobile_api_rows('SELECT action, COUNT(*) n FROM tbl_mobile_audit GROUP BY action ORDER BY n DESC') as $k) {
        $kinds[] = $k['action'];
    }
    mobile_api_out(['ok' => true, 'rows' => $out, 'actions' => $kinds, 'more' => count($rows) === 60]);
}

/* ------------------------------------------------------------------ */
/* ticket form helpers                                                 */
/* ------------------------------------------------------------------ */

function mobile_api_customer_lookup()
{
    $q = trim((string) mobile_api_in('q'));
    $orm = ORM::for_table('tbl_customers')->select_many('id', 'username', 'fullname', 'phonenumber', 'address', 'service_type')->order_by_asc('fullname')->limit(25);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $orm->where_raw('(fullname LIKE ? OR username LIKE ? OR phonenumber LIKE ?)', [$like, $like, $like]);
    }
    $rows = [];
    foreach ($orm->find_many() as $c) {
        $rows[] = ['id' => (int) $c['id'], 'name' => $c['fullname'] ?: $c['username'], 'username' => $c['username'],
            'phone' => $c['phonenumber'], 'address' => (string) $c['address'], 'service' => $c['service_type']];
    }
    mobile_api_out(['ok' => true, 'rows' => $rows]);
}

function mobile_api_ticket_meta()
{
    $cats = [];
    try {
        foreach (mobile_api_rows('SELECT id, name FROM tbl_support_categories ORDER BY name') as $c) {
            $cats[] = ['id' => (int) $c['id'], 'name' => $c['name']];
        }
    } catch (Throwable $e) {
    }
    // the SLA has a separate 2-day clock for relocations, so the form needs that category
    try {
        $has = false;
        foreach ($cats as $c) {
            if (stripos($c['name'], 'reloc') !== false) $has = true;
        }
        if (!$has) {
            ORM::get_db()->prepare('INSERT INTO tbl_support_categories (name) VALUES (?)')->execute(['Relocation']);
            $cats = [];
            foreach (mobile_api_rows('SELECT id, name FROM tbl_support_categories ORDER BY name') as $c) {
                $cats[] = ['id' => (int) $c['id'], 'name' => $c['name']];
            }
        }
    } catch (Throwable $e) {
    }
    mobile_api_out(['ok' => true, 'categories' => $cats, 'priorities' => ['Low', 'Medium', 'High']]);
}

/* ------------------------------------------------------------------ */
/* ONU monitoring (GenieACS)                                           */
/* ------------------------------------------------------------------ */

function mobile_api_nbi($path, $method = 'GET', $body = null)
{
    global $config;
    $base = isset($config['tr069_url']) ? trim($config['tr069_url']) : '';
    if ($base === '' || !function_exists('curl_init')) {
        return null;
    }
    if (!preg_match('#^https?://#', $base)) $base = 'http://' . $base;
    $ch = curl_init(rtrim($base, '/') . $path);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_CUSTOMREQUEST => $method];
    $user = isset($config['tr069_user']) ? $config['tr069_user'] : '';
    $pass = isset($config['tr069_pass']) ? $config['tr069_pass'] : (isset($config['tr069_password']) ? $config['tr069_password'] : '');
    if ($user !== '') $opt[CURLOPT_USERPWD] = $user . ':' . $pass;
    if ($body !== null) {
        $opt[CURLOPT_POSTFIELDS] = json_encode($body);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400 || $code === 0) {
        return null;
    }
    $j = json_decode($res, true);
    return $j === null ? [] : $j;
}

function mobile_api_nbi_val($d, $path)
{
    $n = $d;
    foreach (explode('.', $path) as $p) {
        if (!is_array($n) || !isset($n[$p])) return null;
        $n = $n[$p];
    }
    return is_array($n) && array_key_exists('_value', $n) ? $n['_value'] : null;
}

function mobile_api_nbi_first($d, $paths)
{
    foreach ($paths as $p) {
        $v = mobile_api_nbi_val($d, $p);
        if ($v !== null && $v !== '') return $v;
    }
    return null;
}

function mobile_api_onu_online($lastInform)
{
    global $config;
    $secs = isset($config['tr069_online_secs']) ? max(60, (int) $config['tr069_online_secs']) : 600;
    $t = $lastInform ? strtotime($lastInform) : 0;
    return $t > 0 && (time() - $t) <= $secs;
}

/** One ONU as the app shows it, from a GenieACS device document. */
function mobile_api_onu_row($d, $links, $rx)
{
    $id = (string) $d['_id'];
    $inform = isset($d['_lastInform']) ? $d['_lastInform'] : null;
    $rxv = mobile_api_nbi_first($d, ['VirtualParameters.RXPower', 'VirtualParameters.rxpower', 'VirtualParameters.RxPower', 'VirtualParameters.rx_power']);
    $ssid = mobile_api_nbi_first($d, ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'Device.WiFi.SSID.1.SSID']);
    $row = [
        'id' => $id,
        'model' => (string) mobile_api_nbi_first($d, ['_deviceId._ProductClass']) ?: (isset($d['_deviceId']['_ProductClass']) ? $d['_deviceId']['_ProductClass'] : ''),
        'serial' => isset($d['_deviceId']['_SerialNumber']) ? $d['_deviceId']['_SerialNumber'] : '',
        'online' => mobile_api_onu_online($inform),
        'last_inform' => $inform ? date('Y-m-d H:i:s', strtotime($inform)) : '',
        'rx' => $rxv !== null ? mobile_api_onu_rx($rxv) : (isset($rx[$id]) ? mobile_api_onu_rx($rx[$id]) : null),
        'ssid' => (string) $ssid,
        'software' => (string) mobile_api_nbi_first($d, ['InternetGatewayDevice.DeviceInfo.SoftwareVersion', 'Device.DeviceInfo.SoftwareVersion']),
        'customer' => isset($links[$id]) ? $links[$id] : null,
    ];
    return $row;
}

function mobile_api_onu_rx($v)
{
    // optical power is always a negative dBm figure; anything else in the cache is an unscaled raw reading
    if ($v === null || $v === '') return null;
    $v = (float) $v;
    return ($v < 0 && $v > -60) ? $v : null;
}

function mobile_api_onu_state($rx)
{
    if ($rx === null) return 'unknown';
    if ($rx <= -27) return 'over';
    if ($rx <= -24) return 'warn';
    return 'ok';
}

function mobile_api_onu_maps()
{
    $links = [];
    try {
        foreach (mobile_api_rows('SELECT l.device_id, c.id cid, c.fullname, c.username, c.phonenumber FROM tbl_tr069_links l LEFT JOIN tbl_customers c ON c.id = l.customer_id') as $l) {
            if (!$l['cid']) continue;
            $links[$l['device_id']] = ['id' => (int) $l['cid'], 'name' => $l['fullname'] ?: $l['username'], 'phone' => $l['phonenumber']];
        }
    } catch (Throwable $e) {
    }
    $rx = [];
    try {
        foreach (mobile_api_rows('SELECT r.device_id, r.rx FROM tbl_tr069_rx r JOIN (SELECT device_id, MAX(id) m FROM tbl_tr069_rx GROUP BY device_id) x ON x.m = r.id') as $r) {
            $rx[$r['device_id']] = $r['rx'];
        }
    } catch (Throwable $e) {
    }
    return [$links, $rx];
}

function mobile_api_onu_list()
{
    list($links, $rx) = mobile_api_onu_maps();
    $proj = '_id,_lastInform,_deviceId,VirtualParameters,InternetGatewayDevice.DeviceInfo.SoftwareVersion,Device.DeviceInfo.SoftwareVersion';
    $docs = mobile_api_nbi('/devices?projection=' . rawurlencode($proj));
    $live = is_array($docs);
    $rows = [];
    if ($live) {
        foreach ($docs as $d) {
            if (isset($d['_id'])) $rows[] = mobile_api_onu_row($d, $links, $rx);
        }
    } else {
        // GenieACS not reachable: show what the website last recorded
        $ids = array_unique(array_merge(array_keys($links), array_keys($rx)));
        foreach ($ids as $id) {
            $rows[] = ['id' => $id, 'model' => '', 'serial' => '', 'online' => null, 'last_inform' => '', 'rx' => isset($rx[$id]) ? mobile_api_onu_rx($rx[$id]) : null,
                'ssid' => '', 'software' => '', 'customer' => isset($links[$id]) ? $links[$id] : null];
        }
    }
    $on = $off = $bad = 0;
    $total = count($rows);
    foreach ($rows as &$r) {
        $r['rx_state'] = mobile_api_onu_state($r['rx']);
        if ($r['online'] === true) $on++;
        elseif ($r['online'] === false) $off++;
        if ($r['rx_state'] === 'over' || $r['rx_state'] === 'warn') $bad++;
    }
    unset($r);
    $q = strtolower(trim((string) mobile_api_in('q')));
    $f = (string) mobile_api_in('filter');
    $rows = array_values(array_filter($rows, function ($r) use ($q, $f) {
        if ($f === 'offline' && $r['online'] !== false) return false;
        if ($f === 'online' && $r['online'] !== true) return false;
        if ($f === 'weak' && !in_array($r['rx_state'], ['over', 'warn'], true)) return false;
        if ($q !== '') {
            $hay = strtolower($r['id'] . ' ' . $r['serial'] . ' ' . $r['model'] . ' ' . ($r['customer'] ? $r['customer']['name'] . ' ' . $r['customer']['phone'] : ''));
            if (strpos($hay, $q) === false) return false;
        }
        return true;
    }));
    // offline first, then weakest signal
    usort($rows, function ($a, $b) {
        $ao = $a['online'] === false ? 0 : 1;
        $bo = $b['online'] === false ? 0 : 1;
        if ($ao !== $bo) return $ao - $bo;
        return ($a['rx'] === null ? 0 : $a['rx']) <=> ($b['rx'] === null ? 0 : $b['rx']);
    });
    $rows = array_slice($rows, 0, 300);
    mobile_api_out(['ok' => true, 'live' => $live, 'stats' => ['total' => $total, 'online' => $on, 'offline' => $off, 'weak' => $bad], 'rows' => $rows]);
}

function mobile_api_onu_id()
{
    $id = trim((string) mobile_api_in('id'));
    if ($id === '' || strlen($id) > 190 || !preg_match('/^[A-Za-z0-9._%\-]+$/', $id)) {
        mobile_api_fail('Choose an ONU', 422);
    }
    return $id;
}

function mobile_api_onu_detail()
{
    $id = mobile_api_onu_id();
    list($links, $rx) = mobile_api_onu_maps();
    $docs = mobile_api_nbi('/devices?query=' . rawurlencode(json_encode(['_id' => $id])));
    $row = null;
    if (is_array($docs) && isset($docs[0]['_id'])) {
        $row = mobile_api_onu_row($docs[0], $links, $rx);
    }
    $live = $row !== null;
    if (!$row) {
        if (!isset($links[$id]) && !isset($rx[$id])) {
            mobile_api_fail('ONU not found', 404);
        }
        $row = ['id' => $id, 'model' => '', 'serial' => '', 'online' => null, 'last_inform' => '', 'rx' => isset($rx[$id]) ? mobile_api_onu_rx($rx[$id]) : null,
            'ssid' => '', 'software' => '', 'customer' => isset($links[$id]) ? $links[$id] : null];
    }
    $row['rx_state'] = mobile_api_onu_state($row['rx']);
    $hist = [];
    foreach (mobile_api_rows('SELECT rx, tx, state, created_at FROM tbl_tr069_rx WHERE device_id = ? ORDER BY id DESC LIMIT 24', [$id]) as $h) {
        $hist[] = ['rx' => mobile_api_onu_rx($h['rx']), 'tx' => $h['tx'] === null ? null : (float) $h['tx'], 'state' => $h['state'], 'time' => $h['created_at']];
    }
    $alerts = [];
    foreach (mobile_api_rows('SELECT kind, message, created_at FROM tbl_tr069_alerts WHERE device_id = ? ORDER BY id DESC LIMIT 10', [$id]) as $a) {
        $alerts[] = ['kind' => $a['kind'], 'message' => $a['message'], 'time' => $a['created_at']];
    }
    $tasks = [];
    foreach (mobile_api_rows('SELECT task, status, detail, created_at FROM tbl_tr069_log WHERE device_id = ? ORDER BY id DESC LIMIT 8', [$id]) as $t) {
        $tasks[] = ['task' => $t['task'], 'status' => $t['status'], 'detail' => $t['detail'], 'time' => $t['created_at']];
    }
    mobile_api_out(['ok' => true, 'live' => $live, 'onu' => $row, 'history' => $hist, 'alerts' => $alerts, 'tasks' => $tasks]);
}

function mobile_api_onu_reboot($s)
{
    $id = mobile_api_onu_id();
    $res = mobile_api_nbi('/devices/' . rawurlencode($id) . '/tasks?connection_request', 'POST', ['name' => 'reboot']);
    if ($res === null) {
        mobile_api_fail('Could not reach GenieACS, the reboot was not sent', 502);
    }
    $queued = isset($res['_id']) && !empty($res['fault']) ? false : true;
    try {
        ORM::get_db()->prepare('INSERT INTO tbl_tr069_log (device_id, task, status, detail, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, 'reboot', 'sent', 'Reboot from the app by ' . $s['access']['name'], date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
    }
    mobile_api_out(['ok' => true, 'message' => $queued ? 'Reboot sent' : 'Reboot queued until the ONU next connects']);
}

/* ------------------------------------------------------------------ */
/* data usage and FUP                                                  */
/* ------------------------------------------------------------------ */

function mobile_api_bytes($b)
{
    $b = (float) $b;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
        if ($b < 1024 || $u === 'TB') return ($i === 0 ? (string) (int) $b : number_format($b, 2)) . ' ' . $u;
        $b /= 1024;
    }
    return '';
}

function mobile_api_fup_quota_bytes($rule)
{
    return (float) $rule['quota_mb'] * 1048576;
}

/** The FUP rule, plan and current throttle state for one username. */
function mobile_api_fup_for($username)
{
    $out = ['rule' => null, 'throttled' => false, 'used_period' => 0.0, 'quota' => 0.0];
    try {
        $c = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        $plan = null;
        if ($c) {
            $r = mobile_api_active_recharge($c['id']);
            if ($r) $plan = ORM::for_table('tbl_plans')->where('name_plan', $r['namebp'])->find_one();
        }
        if ($plan) {
            $rules = mobile_api_rows('SELECT * FROM tbl_fup_rules WHERE plan_id = ? AND enabled = 1 ORDER BY id LIMIT 1', [$plan['id']]);
            if ($rules) {
                $rule = $rules[0];
                $out['rule'] = ['period' => $rule['period'], 'quota' => mobile_api_bytes(mobile_api_fup_quota_bytes($rule)), 'action' => $rule['action'], 'throttle_rate' => $rule['throttle_rate']];
                $out['quota'] = mobile_api_fup_quota_bytes($rule);
                $u = mobile_api_rows('SELECT bytes_in, bytes_out FROM tbl_fup_usage WHERE username = ? AND period = ? ORDER BY updated_at DESC LIMIT 1', [$username, $rule['period']]);
                if ($u) $out['used_period'] = (float) $u[0]['bytes_in'] + (float) $u[0]['bytes_out'];
            }
        }
        $st = mobile_api_rows('SELECT fup_profile, throttled_at FROM tbl_fup_state WHERE username = ? LIMIT 1', [$username]);
        if ($st) {
            $out['throttled'] = true;
            $out['throttled_at'] = $st[0]['throttled_at'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function mobile_api_usage_series($username, $days)
{
    $rows = mobile_api_rows('SELECT period_start, rx, tx FROM tbl_du_daily WHERE username = ? AND period_start >= ? ORDER BY period_start', [$username, date('Y-m-d 00:00:00', time() - ($days - 1) * 86400)]);
    $series = [];
    foreach ($rows as $r) {
        $series[] = ['day' => substr($r['period_start'], 0, 10), 'down' => (float) $r['tx'], 'up' => (float) $r['rx'], 'down_text' => mobile_api_bytes($r['tx']), 'up_text' => mobile_api_bytes($r['rx'])];
    }
    return $series;
}

function mobile_api_usage_month($username)
{
    $m = mobile_api_rows('SELECT rx, tx FROM tbl_du_monthly WHERE username = ? AND period_start >= ? ORDER BY period_start DESC LIMIT 1', [$username, date('Y-m-01 00:00:00')]);
    return $m ? ['down' => (float) $m[0]['tx'], 'up' => (float) $m[0]['rx']] : ['down' => 0.0, 'up' => 0.0];
}

function mobile_api_usage_pack($username)
{
    $f = mobile_api_fup_for($username);
    $m = mobile_api_usage_month($username);
    $pct = $f['quota'] > 0 ? min(100, (int) round($f['used_period'] / $f['quota'] * 100)) : null;
    return [
        'username' => $username,
        'month_down' => mobile_api_bytes($m['down']), 'month_up' => mobile_api_bytes($m['up']),
        'fup' => $f['rule'], 'used' => mobile_api_bytes($f['used_period']), 'percent' => $pct,
        'throttled' => $f['throttled'], 'throttled_at' => isset($f['throttled_at']) ? $f['throttled_at'] : null,
        'days' => mobile_api_usage_series($username, 14),
    ];
}

function mobile_api_usage_overview()
{
    $top = [];
    foreach (mobile_api_rows('SELECT username, rx, tx FROM tbl_du_monthly WHERE period_start >= ? ORDER BY (rx + tx) DESC LIMIT 20', [date('Y-m-01 00:00:00')]) as $r) {
        $top[] = ['username' => $r['username'], 'down' => mobile_api_bytes($r['tx']), 'up' => mobile_api_bytes($r['rx']), 'total' => mobile_api_bytes((float) $r['rx'] + (float) $r['tx'])];
    }
    $throttled = [];
    try {
        foreach (mobile_api_rows('SELECT username, type, fup_profile, throttled_at FROM tbl_fup_state ORDER BY throttled_at DESC LIMIT 50') as $t) {
            $throttled[] = ['username' => $t['username'], 'type' => $t['type'], 'profile' => $t['fup_profile'], 'since' => $t['throttled_at']];
        }
    } catch (Throwable $e) {
    }
    $rules = [];
    try {
        foreach (mobile_api_rows('SELECT r.period, r.quota_mb, r.action, r.throttle_rate, p.name_plan, r.plan_id FROM tbl_fup_rules r LEFT JOIN tbl_plans p ON p.id = r.plan_id WHERE r.enabled = 1 ORDER BY p.name_plan') as $r) {
            $rules[] = ['plan' => $r['name_plan'] ?: 'Plan #' . $r['plan_id'], 'period' => $r['period'], 'quota' => mobile_api_bytes((float) $r['quota_mb'] * 1048576), 'action' => $r['action'], 'rate' => $r['throttle_rate']];
        }
    } catch (Throwable $e) {
    }
    $day = mobile_api_rows('SELECT COALESCE(SUM(rx),0) rx, COALESCE(SUM(tx),0) tx FROM tbl_du_daily WHERE period_start >= ?', [date('Y-m-d 00:00:00')]);
    $mon = mobile_api_rows('SELECT COALESCE(SUM(rx),0) rx, COALESCE(SUM(tx),0) tx FROM tbl_du_monthly WHERE period_start >= ?', [date('Y-m-01 00:00:00')]);
    mobile_api_out(['ok' => true,
        'today' => ['down' => mobile_api_bytes($day[0]['tx']), 'up' => mobile_api_bytes($day[0]['rx'])],
        'month' => ['down' => mobile_api_bytes($mon[0]['tx']), 'up' => mobile_api_bytes($mon[0]['rx'])],
        'top' => $top, 'throttled' => $throttled, 'rules' => $rules]);
}

function mobile_api_usage_user()
{
    $u = trim((string) mobile_api_in('username'));
    if ($u === '') mobile_api_fail('Choose a customer', 422);
    mobile_api_out(['ok' => true, 'usage' => mobile_api_usage_pack($u)]);
}

function mobile_api_client_usage($s)
{
    $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
    if (!$c) mobile_api_fail('Account not found', 404);
    mobile_api_out(['ok' => true, 'usage' => mobile_api_usage_pack($c['username'])]);
}


/* ------------------------------------------------------------------ */
/* notifications (the app polls; no Google services needed)             */
/* ------------------------------------------------------------------ */

function mobile_api_notify($userId, $kind, $title, $body, $entity = null, $entityId = null)
{
    try {
        ORM::get_db()->prepare('INSERT INTO tbl_mobile_notify (user_id, kind, title, body, entity, entity_id, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int) $userId, $kind, mb_substr((string) $title, 0, 150), mb_substr((string) $body, 0, 300), $entity, $entityId, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
    }
}

/** Active staff whose role can open $module (and optionally can assign work). Used for "needs attention" alerts. */
function mobile_api_notify_managers($module, $needAssign, $kind, $title, $body, $entity, $entityId, $exceptUid)
{
    try {
        foreach (ORM::for_table('tbl_users')->where('status', 'Active')->limit(80)->find_many() as $u) {
            $id = (int) $u['id'];
            if ($id === (int) $exceptUid) continue;
            $acc = mobile_api_access($id);
            if (!in_array($module, $acc['modules'], true)) continue;
            if ($needAssign && empty($acc['assign'])) continue;
            mobile_api_notify($id, $kind, $title, $body, $entity, $entityId);
        }
    } catch (Throwable $e) {
    }
}

function mobile_api_notify_after($a, $data, $s)
{
    if ($s['role'] !== 'staff') return;
    $uid = (int) $s['user_id'];
    $who = $s['access']['name'];
    if ($a === 'ops_create') {
        $e = (string) mobile_api_in('entity');
        $meta = in_array($e, ['lead', 'ticket'], true) ? mobile_api_ops_meta($e) : null;
        if (!$meta || empty($data['id'])) return;
        $row = ORM::for_table($meta['table'])->find_one((int) $data['id']);
        if (!$row) return;
        $it = mobile_api_ops_item($meta, $row->as_array());
        if ($it['assignee_id'] === 0) {
            mobile_api_notify_managers(mobile_api_ops_module($e), true, 'new_' . $e, ($e === 'ticket' ? 'New ticket' : 'New lead') . ' needs assigning', $it['title'] . ' (by ' . $who . ')', $e, (int) $data['id'], $uid);
        }
    } elseif ($a === 'hr_leave_request') {
        mobile_api_notify_managers('hr_leave', false, 'leave_request', 'Leave request waiting', $who . ' asked for leave', 'leave', null, $uid);
    } elseif ($a === 'hr_leave_decide') {
        $l = ORM::for_table('tbl_hr_leave_requests')->find_one((int) mobile_api_in('id'));
        if ($l) {
            $emp = ORM::for_table('tbl_hr_employees')->find_one((int) $l['employee_id']);
            if ($emp && (int) $emp['admin_id'] > 0) {
                mobile_api_notify((int) $emp['admin_id'], 'leave_' . strtolower($l['status']), 'Leave ' . strtolower($l['status']), $l['leave_type'] . ' ' . $l['start_date'] . ' to ' . $l['end_date'], 'leave', (int) $l['id']);
            }
        }
    }
}

/**
 * The app asks "anything new?" every so often. since_id / since_ts are the phone's own cursors;
 * a first call (both 0) only returns the cursors, so old events never flood a fresh install.
 */
function mobile_api_notify_poll($s)
{
    $now = time();
    $items = [];
    $maxId = 0;
    if ($s['role'] !== 'staff') {
        mobile_api_client_poll($s, $now);
    }
    $uid = (int) $s['user_id'];
    $sinceId = (int) mobile_api_in('since_id', 0);
    $sinceTs = (int) mobile_api_in('since_ts', 0);
    $last = mobile_api_rows('SELECT MAX(id) m FROM tbl_mobile_notify WHERE user_id = ?', [$uid]);
    $maxId = $last && $last[0]['m'] ? (int) $last[0]['m'] : 0;
    $init = ($sinceId === 0 && $sinceTs === 0);
    if (!$init) {
        if (mt_rand(1, 3) === 1) {
            mobile_api_sla_scan($s);
            mobile_api_pay_scan($s);
            $last = mobile_api_rows('SELECT MAX(id) m FROM tbl_mobile_notify WHERE user_id = ?', [$uid]);
            $maxId = $last && $last[0]['m'] ? (int) $last[0]['m'] : 0;
        }
        foreach (mobile_api_rows('SELECT id, kind, title, body, entity, entity_id, created_at FROM tbl_mobile_notify WHERE user_id = ? AND id > ? ORDER BY id LIMIT 30', [$uid, $sinceId]) as $n) {
            $items[] = ['key' => 'n' . $n['id'], 'kind' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'entity' => $n['entity'], 'id' => $n['entity_id'], 'time' => $n['created_at']];
        }
        $from = date('Y-m-d H:i:s', $sinceTs);
        $mods = $s['access']['modules'];
        if (in_array('onu', $mods, true)) {
            try {
                foreach (mobile_api_rows('SELECT id, device_id, kind, message, created_at FROM tbl_tr069_alerts WHERE created_at > ? ORDER BY id LIMIT 8', [$from]) as $al) {
                    $items[] = ['key' => 'a' . $al['id'], 'kind' => 'onu_' . $al['kind'], 'title' => 'ONU alert: ' . $al['kind'], 'body' => $al['message'], 'entity' => 'onu', 'id' => 0, 'time' => $al['created_at']];
                }
            } catch (Throwable $e) {
            }
        }
        if (in_array('usage', $mods, true)) {
            try {
                foreach (mobile_api_rows('SELECT id, username, throttled_at FROM tbl_fup_state WHERE throttled_at > ? ORDER BY id LIMIT 8', [$from]) as $f) {
                    $items[] = ['key' => 'f' . $f['id'] . '_' . strtotime($f['throttled_at']), 'kind' => 'fup', 'title' => 'FUP limit reached', 'body' => $f['username'] . ' was slowed down', 'entity' => 'usage', 'id' => 0, 'time' => $f['throttled_at']];
                }
            } catch (Throwable $e) {
            }
        }
    }
    mobile_api_out(['ok' => true, 'items' => $items, 'max_id' => $maxId, 'now' => $now]);
}

/* ------------------------------------------------------------------ */
/* customer 360                                                        */
/* ------------------------------------------------------------------ */

function mobile_api_customer_find()
{
    $id = (int) mobile_api_in('id', 0);
    $u = trim((string) mobile_api_in('username'));
    $ph = preg_replace('/\D+/', '', (string) mobile_api_in('phone'));
    $c = null;
    if ($id > 0) {
        $c = ORM::for_table('tbl_customers')->find_one($id);
    } elseif ($u !== '') {
        $c = ORM::for_table('tbl_customers')->where('username', $u)->find_one();
    } elseif (strlen($ph) >= 7) {
        $tail = substr($ph, -9);
        $c = ORM::for_table('tbl_customers')->where_like('phonenumber', '%' . $tail)->find_one();
    }
    return $c;
}

function mobile_api_customer_360($s)
{
    $c = mobile_api_customer_find();
    if (!$c) {
        mobile_api_fail('Customer not found', 404);
    }
    $mods = $s['access']['modules'];
    $money = in_array('customers', $mods, true) || in_array('payments', $mods, true);
    $uname = $c['username'];
    $r = mobile_api_active_recharge($c['id']);
    $cust = [
        'id' => (int) $c['id'], 'username' => $uname, 'fullname' => $c['fullname'], 'phone' => $c['phonenumber'], 'email' => $c['email'],
        'address' => (string) $c['address'], 'service' => $c['service_type'], 'status' => $c['status'],
        'plan' => $r ? $r['namebp'] : '', 'expires' => $r ? trim($r['expiration'] . ' ' . $r['time']) : '',
        'plan_active' => $r ? $r['status'] === 'on' : false,
        'balance' => $money ? mobile_api_money($c['balance']) : '',
    ];
    // ONU(s) linked on the website's TR-069 page
    $onus = [];
    try {
        list($links, $rx) = mobile_api_onu_maps();
        foreach (mobile_api_rows('SELECT device_id FROM tbl_tr069_links WHERE customer_id = ?', [(int) $c['id']]) as $l) {
            $id = $l['device_id'];
            $docs = mobile_api_nbi('/devices?query=' . rawurlencode(json_encode(['_id' => $id])) . '&projection=' . rawurlencode('_id,_lastInform,_deviceId,VirtualParameters'));
            if (is_array($docs) && isset($docs[0]['_id'])) {
                $row = mobile_api_onu_row($docs[0], $links, $rx);
            } else {
                $row = ['id' => $id, 'model' => '', 'serial' => '', 'online' => null, 'last_inform' => '', 'rx' => isset($rx[$id]) ? mobile_api_onu_rx($rx[$id]) : null];
            }
            $row['rx_state'] = mobile_api_onu_state($row['rx']);
            $onus[] = ['id' => $row['id'], 'model' => $row['model'], 'serial' => $row['serial'], 'online' => $row['online'], 'last_inform' => $row['last_inform'], 'rx' => $row['rx'], 'rx_state' => $row['rx_state']];
        }
    } catch (Throwable $e) {
    }
    // tickets that name this customer (the website writes "Username: x" into the description)
    $tickets = [];
    try {
        $tm = mobile_api_ops_meta('ticket');
        if ($tm && $tm['map']['notes']) {
            $orm = ORM::for_table($tm['table'])->where_raw('`' . $tm['map']['notes'] . '` LIKE ?', ['%Username: ' . $uname . '%'])->order_by_desc('id')->limit(8);
            foreach ($orm->find_many() as $t) {
                $it = mobile_api_ops_item($tm, $t->as_array());
                $tickets[] = ['id' => $it['id'], 'title' => $it['title'], 'status' => $it['status'], 'priority' => $it['priority'], 'created' => $it['created'], 'assignee' => $it['assignee']];
            }
        }
    } catch (Throwable $e) {
    }
    $payments = [];
    if ($money) {
        foreach (ORM::for_table('tbl_transactions')->where('username', $uname)->order_by_desc('id')->limit(8)->find_many() as $t) {
            $payments[] = mobile_api_tx_row($t);
        }
    }
    $usage = null;
    if (in_array('usage', $mods, true) || $money) {
        $usage = mobile_api_usage_pack($uname);
    }
    mobile_api_out(['ok' => true, 'customer' => $cust, 'onus' => $onus, 'tickets' => $tickets, 'payments' => $payments, 'usage' => $usage, 'can_recharge' => $money]);
}

/* ------------------------------------------------------------------ */
/* technician visits: GPS check-in and photos                          */
/* ------------------------------------------------------------------ */

/** Loads the ticket/lead/install the person is allowed to work on, or stops with an error. */
function mobile_api_visit_item($s)
{
    $e = mobile_api_ops_entity();
    mobile_api_ops_gate($s, $e);
    $meta = mobile_api_ops_meta($e);
    if (!$meta) mobile_api_fail('Table not found', 404);
    $row = ORM::for_table($meta['table'])->find_one((int) mobile_api_in('id'));
    if (!$row) mobile_api_fail('Item not found', 404);
    $uid = mobile_api_scope_uid($s);
    if ($uid && !mobile_api_ops_is_mine($meta, $row->as_array(), $uid)) {
        mobile_api_fail('This item is not assigned to you', 403);
    }
    return [$e, $meta, $row];
}

function mobile_api_photo_dir()
{
    $d = dirname(__DIR__) . '/uploads/mobile';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    if (is_dir($d) && !file_exists($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($d . '/index.html', '');
    }
    return $d;
}

function mobile_api_geo($v, $lim)
{
    if ($v === '' || $v === null || !is_numeric($v)) return null;
    $f = (float) $v;
    return abs($f) <= $lim ? $f : null;
}

function mobile_api_haversine($lat1, $lng1, $lat2, $lng2)
{
    $r = 6371000;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function mobile_api_checkin($s)
{
    list($e, $meta, $row) = mobile_api_visit_item($s);
    $kind = (string) mobile_api_in('kind');
    if (!in_array($kind, ['arrive', 'done'], true)) {
        mobile_api_fail('Choose arrive or done', 422);
    }
    $lat = mobile_api_geo(mobile_api_in('lat'), 90);
    $lng = mobile_api_geo(mobile_api_in('lng'), 180);
    if ($lat === null || $lng === null) {
        mobile_api_fail('Location is needed to check in. Turn on GPS and allow location.', 422);
    }
    $it = mobile_api_ops_item($meta, $row->as_array());
    $dist = ($it['lat'] && $it['lng']) ? mobile_api_haversine($lat, $lng, $it['lat'], $it['lng']) : null;
    $at = (int) mobile_api_in('at', 0);
    $ct = ($at > 0 && $at <= time() + 300 && $at >= time() - 3 * 86400) ? date('Y-m-d H:i:s', $at) : null;
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_checkins (entity, entity_id, user_id, user_name, kind, lat, lng, accuracy, distance_m, client_time, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$e, (int) $row['id'], (int) $s['user_id'], $s['access']['name'], $kind, $lat, $lng, (int) mobile_api_in('accuracy', 0), $dist, $ct, date('Y-m-d H:i:s')]);
    mobile_api_ticket_log($row, $meta, $s, ($kind === 'arrive' ? 'arrived on site' : 'finished on site') . ($dist !== null ? ' (' . $dist . ' m from the location)' : ''));
    $row->save();
    $msg = $kind === 'arrive' ? 'Arrival recorded' : 'Completion recorded';
    if ($dist !== null) $msg .= ' · ' . $dist . ' m from the customer';
    mobile_api_out(['ok' => true, 'message' => $msg, 'distance_m' => $dist]);
}

function mobile_api_photo_add($s)
{
    list($e, $meta, $row) = mobile_api_visit_item($s);
    $b64 = (string) mobile_api_in('image');
    if ($b64 === '') mobile_api_fail('No photo received', 422);
    $bin = base64_decode(str_replace(' ', '+', $b64), true);
    if ($bin === false || strlen($bin) < 200) mobile_api_fail('The photo could not be read', 422);
    if (strlen($bin) > 4 * 1024 * 1024) mobile_api_fail('The photo is too large (max 4 MB)', 413);
    if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") mobile_api_fail('Only JPEG photos are accepted', 415);
    $n = ORM::for_table('tbl_mobile_photos')->where('entity', $e)->where('entity_id', (int) $row['id'])->count();
    if ($n >= 30) mobile_api_fail('This item already has 30 photos', 422);
    $file = bin2hex(random_bytes(12)) . '.jpg';
    if (@file_put_contents(mobile_api_photo_dir() . '/' . $file, $bin) === false) {
        mobile_api_fail('The server could not store the photo (check that system/uploads is writable)', 500);
    }
    $lat = mobile_api_geo(mobile_api_in('lat'), 90);
    $lng = mobile_api_geo(mobile_api_in('lng'), 180);
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_photos (entity, entity_id, user_id, user_name, file, caption, lat, lng, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$e, (int) $row['id'], (int) $s['user_id'], $s['access']['name'], $file, mb_substr(trim((string) mobile_api_in('caption')), 0, 200), $lat, $lng, date('Y-m-d H:i:s')]);
    mobile_api_ticket_log($row, $meta, $s, 'added a photo');
    $row->save();
    mobile_api_out(['ok' => true, 'message' => 'Photo saved']);
}

function mobile_api_photo_get($s)
{
    $p = ORM::for_table('tbl_mobile_photos')->find_one((int) mobile_api_in('photo_id'));
    if (!$p) mobile_api_fail('Photo not found', 404);
    $meta = mobile_api_ops_meta($p['entity']);
    mobile_api_ops_gate($s, $p['entity']);
    $uid = mobile_api_scope_uid($s);
    if ($uid && $meta) {
        $row = ORM::for_table($meta['table'])->find_one((int) $p['entity_id']);
        if (!$row || !mobile_api_ops_is_mine($meta, $row->as_array(), $uid)) mobile_api_fail('Not allowed', 403);
    }
    $path = mobile_api_photo_dir() . '/' . basename($p['file']);
    if (!is_file($path)) mobile_api_fail('The photo file is missing on the server', 404);
    $data = file_get_contents($path);
    if (function_exists('imagecreatefromstring') && (int) mobile_api_in('thumb', 0) === 1) {
        $im = @imagecreatefromstring($data);
        if ($im) {
            $w = imagesx($im);
            $h = imagesy($im);
            $sc = min(1, 320 / max($w, $h));
            $t = imagescale($im, max(1, (int) ($w * $sc)), max(1, (int) ($h * $sc)));
            if ($t) {
                ob_start();
                imagejpeg($t, null, 70);
                $data = ob_get_clean();
            }
        }
    }
    mobile_api_out(['ok' => true, 'image' => base64_encode($data)]);
}

function mobile_api_visit_info($s)
{
    list($e, $meta, $row) = mobile_api_visit_item($s);
    $photos = [];
    foreach (mobile_api_rows('SELECT id, user_name, caption, lat, lng, created_at FROM tbl_mobile_photos WHERE entity = ? AND entity_id = ? ORDER BY id DESC', [$e, (int) $row['id']]) as $p) {
        $photos[] = ['id' => (int) $p['id'], 'by' => $p['user_name'], 'caption' => $p['caption'], 'time' => $p['created_at'], 'has_gps' => $p['lat'] !== null];
    }
    $checks = [];
    foreach (mobile_api_rows('SELECT user_name, kind, lat, lng, accuracy, distance_m, client_time, created_at FROM tbl_mobile_checkins WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 20', [$e, (int) $row['id']]) as $c) {
        $checks[] = ['by' => $c['user_name'], 'kind' => $c['kind'], 'lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'distance_m' => $c['distance_m'] === null ? null : (int) $c['distance_m'],
            'time' => $c['client_time'] ?: $c['created_at']];
    }
    mobile_api_out(['ok' => true, 'photos' => $photos, 'checkins' => $checks]);
}


/* ------------------------------------------------------------------ */
/* SLA: installation 3 days, support ticket 1 day, relocation 2 days    */
/* ------------------------------------------------------------------ */

function mobile_api_sla_cfg($key, $default)
{
    global $config;
    return isset($config[$key]) && (int) $config[$key] > 0 ? (int) $config[$key] : $default;
}

function mobile_api_sla_cat_name($id)
{
    static $names = null;
    if ($names === null) {
        $names = [];
        try {
            foreach (mobile_api_rows('SELECT id, name FROM tbl_support_categories') as $c) {
                $names[(int) $c['id']] = strtolower($c['name']);
            }
        } catch (Throwable $e) {
        }
    }
    return isset($names[(int) $id]) ? $names[(int) $id] : '';
}

/** SLA state for a ticket or installation row, or null when no clock applies (leads, finished work). */
function mobile_api_sla($meta, $a)
{
    $e = $meta['entity'];
    if ($e !== 'ticket' && $e !== 'install') return null;
    $m = $meta['map'];
    $status = ($m['status'] && isset($a[$m['status']])) ? strtolower(trim((string) $a[$m['status']])) : '';
    $done = $e === 'ticket'
        ? ['resolved', 'closed']
        : ['completed', 'complete', 'cancelled', 'canceled', 'failed', 'awaiting_payment'];
    if (in_array($status, $done, true)) return null;
    $startRaw = ($m['created'] && isset($a[$m['created']])) ? $a[$m['created']] : '';
    $start = $startRaw ? strtotime($startRaw) : 0;
    if ($start <= 0) return null;

    $text = strtolower(implode(' ', array_filter([
        isset($a['title']) ? $a['title'] : '', isset($a['notes']) ? $a['notes'] : '', isset($a['description']) ? $a['description'] : '',
        isset($a['plan_name']) ? $a['plan_name'] : '', isset($a['job_type']) ? $a['job_type'] : '',
        isset($a['category_id']) ? mobile_api_sla_cat_name($a['category_id']) : '',
    ])));
    $kind = $e === 'install' ? 'installation' : 'support';
    $hours = $e === 'install' ? mobile_api_sla_cfg('mobile_sla_install_h', 72) : mobile_api_sla_cfg('mobile_sla_ticket_h', 24);
    if (strpos($text, 'relocat') !== false) {
        $kind = 'relocation';
        $hours = mobile_api_sla_cfg('mobile_sla_relocation_h', 48);
    }
    $due = $start + $hours * 3600;
    $left = (int) floor(($due - time()) / 60);
    $abs = abs($left);
    $d = intdiv($abs, 1440);
    $h = intdiv($abs % 1440, 60);
    $txt = $d > 0 ? $d . 'd ' . $h . 'h' : ($h > 0 ? $h . 'h ' . ($abs % 60) . 'm' : ($abs % 60) . 'm');
    return [
        'kind' => $kind, 'hours' => $hours, 'due' => date('Y-m-d H:i', $due), 'overdue' => $left < 0, 'left_min' => $left,
        'text' => $left < 0 ? 'Overdue by ' . $txt : 'Due in ' . $txt,
    ];
}

/** Tell the assignee (or a manager who sees everything) once when something goes past its SLA. */
function mobile_api_sla_scan($s)
{
    try {
        $uid = (int) $s['user_id'];
        $sees = ($s['access']['scope'] === 'all' && !empty($s['access']['assign']));
        $have = [];
        foreach (mobile_api_rows("SELECT entity, entity_id FROM tbl_mobile_notify WHERE user_id = ? AND kind = 'sla'", [$uid]) as $r) {
            $have[$r['entity'] . ':' . $r['entity_id']] = true;
        }
        foreach (['ticket', 'install'] as $e) {
            if (!in_array($e, mobile_api_ops_allowed($s), true)) continue;
            $meta = mobile_api_ops_meta($e);
            if (!$meta) continue;
            $orm = ORM::for_table($meta['table'])->order_by_desc('id')->limit(200);
            if (!$sees) {
                if (!$uid) continue;
                mobile_api_ops_scope($orm, $meta, $uid);
            }
            foreach ($orm->find_many() as $row) {
                $sla = mobile_api_sla($meta, $row->as_array());
                if (!$sla || !$sla['overdue'] || isset($have[$e . ':' . $row['id']])) continue;
                $it = mobile_api_ops_item($meta, $row->as_array());
                if (!$sees && !mobile_api_ops_is_mine($meta, $row->as_array(), $uid)) continue;
                mobile_api_notify($uid, 'sla', ucfirst($sla['kind']) . ' past its SLA', $it['title'] . ' (' . $sla['hours'] / 24 . ' day SLA, ' . $sla['text'] . ')', $e, (int) $row['id']);
            }
        }
    } catch (Throwable $e) {
    }
}


/* ------------------------------------------------------------------ */
/* store: stock is the sum of the movements in tbl_store_moves          */
/* (holder 0 = warehouse, otherwise the technician's user id)           */
/* ------------------------------------------------------------------ */

function mobile_api_store_manager($s)
{
    return !empty($s['access']['assign']) || !empty($s['access']['super']);
}

function mobile_api_store_need_manager($s)
{
    if (!mobile_api_store_manager($s)) {
        mobile_api_fail('Only people who can assign work may do this in the store', 403);
    }
}

function mobile_api_store_qty($itemId, $holder)
{
    $r = mobile_api_rows('SELECT COALESCE(SUM(qty),0) q FROM tbl_store_moves WHERE item_id = ? AND holder_id = ?', [(int) $itemId, (int) $holder]);
    return (float) $r[0]['q'];
}

function mobile_api_store_num($v)
{
    $f = round((float) $v, 2);
    return $f == floor($f) ? (string) (int) $f : (string) $f;
}

function mobile_api_store_overview($s)
{
    $uid = (int) $s['user_id'];
    $mgr = mobile_api_store_manager($s);
    $items = mobile_api_rows('SELECT id, sku, name, category, unit, min_stock FROM tbl_store_items WHERE active = 1 ORDER BY name');
    $sums = [];
    foreach (mobile_api_rows('SELECT item_id, holder_id, SUM(qty) q FROM tbl_store_moves GROUP BY item_id, holder_id') as $r) {
        $sums[(int) $r['item_id']][(int) $r['holder_id']] = (float) $r['q'];
    }
    $names = mobile_api_user_names();
    $out = [];
    $low = 0;
    foreach ($items as $i) {
        $id = (int) $i['id'];
        $h = isset($sums[$id]) ? $sums[$id] : [];
        $wh = isset($h[0]) ? $h[0] : 0.0;
        $mine = isset($h[$uid]) ? $h[$uid] : 0.0;
        $isLow = $wh < (float) $i['min_stock'];
        if ($isLow) $low++;
        $row = ['id' => $id, 'sku' => $i['sku'], 'name' => $i['name'], 'category' => $i['category'], 'unit' => $i['unit'],
            'min' => mobile_api_store_num($i['min_stock']), 'warehouse' => mobile_api_store_num($wh), 'mine' => mobile_api_store_num($mine), 'low' => $isLow];
        if ($mgr) {
            $held = [];
            foreach ($h as $hid => $q) {
                if ($hid > 0 && $q > 0.0001) $held[] = ['id' => $hid, 'name' => isset($names[$hid]) ? $names[$hid] : '#' . $hid, 'qty' => mobile_api_store_num($q)];
            }
            $row['held'] = $held;
        }
        $out[] = $row;
    }
    $pending = mobile_api_rows("SELECT COUNT(*) n FROM tbl_store_requests WHERE status NOT IN ('approved','rejected','cancelled')" . ($mgr ? '' : ' AND tech_id = ' . $uid));
    mobile_api_out(['ok' => true, 'manager' => $mgr, 'items' => $out, 'stats' => ['items' => count($out), 'low' => $low, 'pending' => (int) $pending[0]['n']]]);
}

function mobile_api_store_meta($s)
{
    $items = [];
    foreach (mobile_api_rows('SELECT id, name, unit FROM tbl_store_items WHERE active = 1 ORDER BY name') as $i) {
        $items[] = ['id' => (int) $i['id'], 'name' => $i['name'], 'unit' => $i['unit']];
    }
    $kits = [];
    foreach (mobile_api_rows('SELECT id, name FROM tbl_store_kits ORDER BY name') as $k) {
        $lines = [];
        foreach (mobile_api_rows('SELECT item_id, qty FROM tbl_store_kit_items WHERE kit_id = ?', [(int) $k['id']]) as $l) {
            $lines[] = ['item_id' => (int) $l['item_id'], 'qty' => mobile_api_store_num($l['qty'])];
        }
        $kits[] = ['id' => (int) $k['id'], 'name' => $k['name'], 'lines' => $lines];
    }
    $jobs = [];
    try {
        $meta = mobile_api_ops_meta('install');
        if ($meta && in_array('installations', $s['access']['modules'], true)) {
            $orm = ORM::for_table($meta['table'])->order_by_desc('id')->limit(40);
            $uid = mobile_api_scope_uid($s);
            if ($uid) mobile_api_ops_scope($orm, $meta, $uid);
            foreach ($orm->find_many() as $r) {
                $it = mobile_api_ops_item($meta, $r->as_array());
                if (in_array(strtolower($it['status']), ['completed', 'complete', 'cancelled', 'canceled', 'failed'], true)) continue;
                $jobs[] = ['id' => $it['id'], 'title' => $it['title'], 'status' => $it['status']];
            }
        }
    } catch (Throwable $e) {
    }
    mobile_api_out(['ok' => true, 'items' => $items, 'kits' => $kits, 'jobs' => $jobs, 'techs' => mobile_api_store_manager($s) ? mobile_api_ops_techs() : []]);
}

function mobile_api_store_requests($s)
{
    $mgr = mobile_api_store_manager($s);
    $uid = (int) $s['user_id'];
    $rows = mobile_api_rows('SELECT * FROM tbl_store_requests' . ($mgr ? '' : ' WHERE tech_id = ' . $uid) . ' ORDER BY id DESC LIMIT 60');
    $names = mobile_api_user_names();
    $itemNames = [];
    foreach (mobile_api_rows('SELECT id, name, unit FROM tbl_store_items') as $i) {
        $itemNames[(int) $i['id']] = $i;
    }
    $out = [];
    foreach ($rows as $r) {
        $lines = [];
        foreach (mobile_api_rows('SELECT item_id, qty_req, qty_ok FROM tbl_store_request_items WHERE request_id = ?', [(int) $r['id']]) as $l) {
            $iid = (int) $l['item_id'];
            $lines[] = ['item_id' => $iid, 'name' => isset($itemNames[$iid]) ? $itemNames[$iid]['name'] : '#' . $iid,
                'unit' => isset($itemNames[$iid]) ? $itemNames[$iid]['unit'] : '',
                'qty_req' => mobile_api_store_num($l['qty_req']), 'qty_ok' => mobile_api_store_num($l['qty_ok'])];
        }
        $tid = (int) $r['tech_id'];
        $out[] = ['id' => (int) $r['id'], 'req_no' => $r['req_no'], 'tech_id' => $tid, 'tech' => isset($names[$tid]) ? $names[$tid] : '#' . $tid,
            'status' => $r['status'], 'note' => (string) $r['note'], 'admin_note' => (string) $r['admin_note'], 'created' => $r['created_at'],
            'decided' => $r['decided_at'], 'items' => $lines,
            'pending' => !in_array($r['status'], ['approved', 'rejected', 'cancelled'], true)];
    }
    mobile_api_out(['ok' => true, 'manager' => $mgr, 'requests' => $out]);
}

/** "3:2,5:10" -> [[item_id, qty], ...] with every id checked against the active items. */
function mobile_api_store_parse_lines($raw)
{
    $out = [];
    foreach (explode(',', (string) $raw) as $part) {
        $bits = explode(':', trim($part));
        if (count($bits) !== 2) continue;
        $id = (int) $bits[0];
        $q = round((float) $bits[1], 2);
        if ($id > 0 && $q > 0 && $q <= 100000) $out[$id] = (isset($out[$id]) ? $out[$id] : 0) + $q;
    }
    if (!$out) mobile_api_fail('Choose at least one item and a quantity', 422);
    $ids = array_keys($out);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $found = mobile_api_rows('SELECT id FROM tbl_store_items WHERE active = 1 AND id IN (' . $in . ')', $ids);
    if (count($found) !== count($ids)) mobile_api_fail('One of the items is not in the store any more', 422);
    return $out;
}

function mobile_api_store_request_create($s)
{
    $lines = mobile_api_store_parse_lines(mobile_api_in('items'));
    $tech = (int) $s['user_id'];
    $forId = (int) mobile_api_in('tech_id', 0);
    if ($forId > 0 && $forId !== $tech) {
        mobile_api_store_need_manager($s);
        $tech = $forId;
    }
    $db = ORM::get_db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO tbl_store_requests (req_no, tech_id, status, note, admin_note, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute(['', $tech, 'pending', mb_substr(trim((string) mobile_api_in('note')), 0, 255), '', (int) $s['user_id'], date('Y-m-d H:i:s')]);
        $id = (int) $db->lastInsertId();
        $no = sprintf('REQ%05d', $id);
        $db->prepare('UPDATE tbl_store_requests SET req_no = ? WHERE id = ?')->execute([$no, $id]);
        foreach ($lines as $iid => $q) {
            $db->prepare('INSERT INTO tbl_store_request_items (request_id, item_id, qty_req, qty_ok) VALUES (?,?,?,0)')->execute([$id, $iid, $q]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    mobile_api_notify_managers('store', true, 'stock_request', 'Stock request ' . $no, $s['access']['name'] . ' asked for ' . count($lines) . ' item(s)', 'store', $id, (int) $s['user_id']);
    mobile_api_out(['ok' => true, 'id' => $id, 'message' => 'Request ' . $no . ' sent']);
}

function mobile_api_store_request_decide($s)
{
    mobile_api_store_need_manager($s);
    $id = (int) mobile_api_in('id');
    $d = (string) mobile_api_in('decision');
    if (!in_array($d, ['approve', 'reject'], true)) mobile_api_fail('Choose approve or reject', 422);
    $db = ORM::get_db();
    $fail = function ($m, $c) use ($db) {
        if ($db->inTransaction()) $db->rollBack();
        mobile_api_fail($m, $c);
    };
    $db->beginTransaction();
    try {
        $r = mobile_api_rows('SELECT * FROM tbl_store_requests WHERE id = ? FOR UPDATE', [$id]);
        if (!$r) $fail('Request not found', 404);
        $r = $r[0];
        if (in_array($r['status'], ['approved', 'rejected', 'cancelled'], true)) {
            $fail('This request was already ' . $r['status'], 409);
        }
        $now = date('Y-m-d H:i:s');
        $note = mb_substr(trim((string) mobile_api_in('note')), 0, 255);
        $summary = [];
        if ($d === 'approve') {
            $over = [];
            foreach (explode(',', (string) mobile_api_in('qty')) as $p) {
                $b = explode(':', trim($p));
                if (count($b) === 2) $over[(int) $b[0]] = max(0, round((float) $b[1], 2));
            }
            foreach (mobile_api_rows('SELECT id, item_id, qty_req FROM tbl_store_request_items WHERE request_id = ?', [$id]) as $l) {
                $iid = (int) $l['item_id'];
                $q = isset($over[$iid]) ? $over[$iid] : (float) $l['qty_req'];
                if ($q <= 0) continue;
                $avail = mobile_api_store_qty($iid, 0);
                if ($q > $avail + 0.0001) {
                    $nm = mobile_api_rows('SELECT name FROM tbl_store_items WHERE id = ?', [$iid]);
                    $fail('Not enough ' . ($nm ? $nm[0]['name'] : 'stock') . ' in the warehouse (' . mobile_api_store_num($avail) . ' left). Lower the quantity.', 422);
                }
                $ins = $db->prepare('INSERT INTO tbl_store_moves (item_id, holder_id, qty, kind, ref_type, ref_id, note, by_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->execute([$iid, 0, -$q, 'issue', 'request', $id, $r['req_no'], (int) $s['user_id'], $now]);
                $ins->execute([$iid, (int) $r['tech_id'], $q, 'issue', 'request', $id, $r['req_no'], (int) $s['user_id'], $now]);
                $db->prepare('UPDATE tbl_store_request_items SET qty_ok = ? WHERE id = ?')->execute([$q, (int) $l['id']]);
                $summary[] = mobile_api_store_num($q);
            }
        }
        $db->prepare('UPDATE tbl_store_requests SET status = ?, admin_note = ?, decided_by = ?, decided_at = ? WHERE id = ?')
            ->execute([$d === 'approve' ? 'approved' : 'rejected', $note, (int) $s['user_id'], $now, $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    mobile_api_notify((int) $r['tech_id'], 'stock_' . $d, 'Stock request ' . ($d === 'approve' ? 'approved' : 'rejected'), $r['req_no'] . ($note !== '' ? ': ' . $note : ''), 'store', $id);
    mobile_api_out(['ok' => true, 'message' => $r['req_no'] . ($d === 'approve' ? ' approved and issued' : ' rejected')]);
}

function mobile_api_store_receive($s)
{
    mobile_api_store_need_manager($s);
    $iid = (int) mobile_api_in('item_id');
    $q = round((float) mobile_api_in('qty'), 2);
    if ($q <= 0 || $q > 1000000) mobile_api_fail('Enter a quantity', 422);
    if (!mobile_api_rows('SELECT id FROM tbl_store_items WHERE id = ? AND active = 1', [$iid])) mobile_api_fail('Item not found', 404);
    ORM::get_db()->prepare('INSERT INTO tbl_store_moves (item_id, holder_id, qty, kind, serial, note, by_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$iid, 0, $q, 'receive', mb_substr(trim((string) mobile_api_in('serial')), 0, 80) ?: null, mb_substr(trim((string) mobile_api_in('note')), 0, 255) ?: null, (int) $s['user_id'], date('Y-m-d H:i:s')]);
    mobile_api_out(['ok' => true, 'message' => 'Stock received', 'warehouse' => mobile_api_store_num(mobile_api_store_qty($iid, 0))]);
}

/** A technician records items used on a job; it comes out of what they hold. */
function mobile_api_store_use($s)
{
    $uid = (int) $s['user_id'];
    $iid = (int) mobile_api_in('item_id');
    $q = round((float) mobile_api_in('qty'), 2);
    if ($q <= 0 || $q > 100000) mobile_api_fail('Enter a quantity', 422);
    $item = mobile_api_rows('SELECT id, name FROM tbl_store_items WHERE id = ? AND active = 1', [$iid]);
    if (!$item) mobile_api_fail('Item not found', 404);
    $have = mobile_api_store_qty($iid, $uid);
    if ($q > $have + 0.0001) mobile_api_fail('You only hold ' . mobile_api_store_num($have) . ' of ' . $item[0]['name'], 422);
    $job = (int) mobile_api_in('job_id', 0);
    $serial = mb_substr(trim((string) mobile_api_in('serial')), 0, 80);
    $db = ORM::get_db();
    if ($job > 0) {
        $meta = mobile_api_ops_meta('install');
        $row = $meta ? ORM::for_table($meta['table'])->find_one($job) : null;
        if (!$row) mobile_api_fail('Installation not found', 404);
        $su = mobile_api_scope_uid($s);
        if ($su && !mobile_api_ops_is_mine($meta, $row->as_array(), $su)) mobile_api_fail('This installation is not assigned to you', 403);
    }
    $db->prepare('INSERT INTO tbl_store_moves (item_id, holder_id, qty, kind, ref_type, ref_id, serial, note, by_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$iid, $uid, -$q, 'use', $job > 0 ? 'job' : null, $job > 0 ? $job : null, $serial ?: null, mb_substr(trim((string) mobile_api_in('note')), 0, 255) ?: null, $uid, date('Y-m-d H:i:s')]);
    if ($job > 0) {
        try {
            $db->prepare('INSERT INTO tbl_installation_items (job_id, item, serial, qty, store_item_id, store_holder) VALUES (?,?,?,?,?,?)')
                ->execute([$job, $item[0]['name'], $serial, $q, $iid, $uid]);
        } catch (Throwable $e) {
        }
    }
    mobile_api_out(['ok' => true, 'message' => 'Recorded. You now hold ' . mobile_api_store_num($have - $q) . ' of ' . $item[0]['name']]);
}


/* ------------------------------------------------------------------ */
/* installation payments (same tables as the website Installations plugin) */
/* Completing the job and activating the plan stay in that plugin.       */
/* ------------------------------------------------------------------ */

function mobile_api_instpay_manager($s)
{
    return !empty($s['access']['assign']) || !empty($s['access']['super']);
}

function mobile_api_instpay_need_manager($s)
{
    if (!mobile_api_instpay_manager($s)) {
        mobile_api_fail('Only people who can assign work may record or waive payments', 403);
    }
}

function mobile_api_instpay_row($r, $names)
{
    $due = (float) $r['amount_due'];
    $paid = (float) $r['amount_paid'];
    $tid = (int) $r['technician_id'];
    return [
        'id' => (int) $r['id'], 'job_no' => $r['job_no'], 'customer' => $r['customer_name'], 'phone' => $r['phone'], 'plan' => $r['plan_name'],
        'status' => $r['status'], 'pay_status' => $r['pay_status'], 'note' => (string) $r['pay_note'],
        'due' => mobile_api_money($due), 'paid' => mobile_api_money($paid), 'balance' => mobile_api_money(max(0, $due - $paid)),
        'balance_raw' => max(0, $due - $paid), 'requested' => $r['pay_requested_at'],
        'technician' => $tid && isset($names[$tid]) ? $names[$tid] : '',
    ];
}

function mobile_api_instpay_list($s)
{
    $names = mobile_api_user_names();
    $filter = (string) mobile_api_in('filter');
    $where = ["status NOT IN ('cancelled')"];
    if ($filter === 'unpaid') {
        $where[] = "pay_status IN ('awaiting','none','') AND amount_due > amount_paid";
    } elseif ($filter === 'paid') {
        $where[] = "pay_status IN ('paid','waived')";
    }
    $uid = mobile_api_scope_uid($s);
    if ($uid) $where[] = 'technician_id = ' . (int) $uid;
    $rows = mobile_api_rows('SELECT * FROM tbl_installations WHERE ' . implode(' AND ', $where) . ' ORDER BY (pay_status = "awaiting") DESC, id DESC LIMIT 100');
    $out = [];
    foreach ($rows as $r) $out[] = mobile_api_instpay_row($r, $names);
    $stats = mobile_api_rows("SELECT
        SUM(CASE WHEN pay_status = 'awaiting' THEN 1 ELSE 0 END) waiting,
        SUM(CASE WHEN pay_status IN ('awaiting','none','') AND status NOT IN ('cancelled','completed') THEN GREATEST(amount_due - amount_paid, 0) ELSE 0 END) owed
        FROM tbl_installations WHERE status <> 'cancelled'" . ($uid ? ' AND technician_id = ' . (int) $uid : ''));
    $today = mobile_api_rows('SELECT COALESCE(SUM(amount),0) a FROM tbl_installation_payments WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
    mobile_api_out(['ok' => true, 'manager' => mobile_api_instpay_manager($s), 'rows' => $out,
        'stats' => ['waiting' => (int) $stats[0]['waiting'], 'owed' => mobile_api_money($stats[0]['owed']), 'today' => mobile_api_money($today[0]['a'])]]);
}

function mobile_api_instpay_job($s, $forUpdate = false)
{
    $rows = mobile_api_rows('SELECT * FROM tbl_installations WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [(int) mobile_api_in('id')]);
    if (!$rows) mobile_api_fail('Installation not found', 404);
    $uid = mobile_api_scope_uid($s);
    if ($uid && (int) $rows[0]['technician_id'] !== (int) $uid) mobile_api_fail('This installation is not assigned to you', 403);
    return $rows[0];
}

function mobile_api_instpay_detail($s)
{
    $r = mobile_api_instpay_job($s);
    $names = mobile_api_user_names();
    $hist = [];
    foreach (mobile_api_rows('SELECT method, ref, amount, payer, note, created_by, created_at FROM tbl_installation_payments WHERE job_id = ? ORDER BY id DESC', [(int) $r['id']]) as $p) {
        $hist[] = ['method' => $p['method'], 'ref' => $p['ref'], 'amount' => mobile_api_money($p['amount']), 'payer' => (string) $p['payer'],
            'note' => (string) $p['note'], 'by' => $p['created_by'], 'time' => $p['created_at']];
    }
    // M-Pesa payments sent to this job number that nobody has claimed yet
    $suggest = [];
    try {
        $sql = 'SELECT TransID, TransAmount, FirstName, BillRefNumber, TransTime FROM tbl_mpesa_transactions WHERE LOWER(BillRefNumber) = LOWER(?)
                AND TransID NOT IN (SELECT ref FROM tbl_installation_payments WHERE ref IS NOT NULL) AND TransID NOT IN (SELECT trans_id FROM tbl_c2b_manual) ORDER BY id DESC LIMIT 5';
        foreach (mobile_api_rows($sql, [$r['job_no']]) as $t) {
            $suggest[] = ['code' => $t['TransID'], 'amount' => mobile_api_money($t['TransAmount']), 'name' => $t['FirstName'], 'time' => $t['TransTime']];
        }
    } catch (Throwable $e) {
    }
    mobile_api_out(['ok' => true, 'manager' => mobile_api_instpay_manager($s), 'job' => mobile_api_instpay_row($r, $names), 'history' => $hist, 'suggested' => $suggest]);
}

function mobile_api_instpay_record($s)
{
    mobile_api_instpay_need_manager($s);
    $method = (string) mobile_api_in('method');
    if (!in_array($method, ['cash', 'mpesa'], true)) mobile_api_fail('Choose cash or M-Pesa', 422);
    $db = ORM::get_db();
    $fail = function ($m, $c) use ($db) {
        if ($db->inTransaction()) $db->rollBack();
        mobile_api_fail($m, $c);
    };
    $db->beginTransaction();
    try {
        $job = mobile_api_instpay_job($s, true);
        if (in_array($job['status'], ['cancelled', 'completed'], true)) $fail('This installation is ' . $job['status'] . ', so payments cannot be added', 409);
        if (in_array($job['pay_status'], ['paid', 'waived'], true)) $fail('Payment for this installation is already ' . $job['pay_status'], 409);
        $payer = mb_substr(trim((string) mobile_api_in('payer')), 0, 120);
        $note = mb_substr(trim((string) mobile_api_in('note')), 0, 255);
        $by = $s['access']['name'];
        $now = date('Y-m-d H:i:s');
        if ($method === 'cash') {
            $amount = round((float) mobile_api_in('amount'), 2);
            if ($amount <= 0 || $amount > 1000000) $fail('Enter the amount received', 422);
            $ref = 'CASH-' . date('ymdHis') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        } else {
            $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) mobile_api_in('ref')));
            if (strlen($ref) < 8) $fail('Enter the M-Pesa transaction code', 422);
            $tx = mobile_api_rows('SELECT TransAmount, FirstName FROM tbl_mpesa_transactions WHERE TransID = ? LIMIT 1', [$ref]);
            if (!$tx) $fail('That M-Pesa code was not found. Payments appear here once Safaricom has sent them to your paybill.', 404);
            if (mobile_api_rows('SELECT id FROM tbl_installation_payments WHERE ref = ?', [$ref]) || mobile_api_rows('SELECT id FROM tbl_c2b_manual WHERE trans_id = ?', [$ref])) {
                $fail('That M-Pesa payment has already been used', 409);
            }
            $amount = (float) $tx[0]['TransAmount'];
            if ($payer === '') $payer = (string) $tx[0]['FirstName'];
            $db->prepare('INSERT INTO tbl_c2b_manual (trans_id, customer_id, amount, created_at) VALUES (?,?,?,?)')->execute([$ref, 0, $amount, $now]);
        }
        $db->prepare('INSERT INTO tbl_installation_payments (job_id, method, ref, amount, payer, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int) $job['id'], $method, $ref, $amount, $payer, $note, $by, $now]);
        $paid = (float) $job['amount_paid'] + $amount;
        $due = (float) $job['amount_due'];
        $status = ($due > 0 && $paid + 0.001 >= $due) ? 'paid' : 'awaiting';
        $db->prepare('UPDATE tbl_installations SET amount_paid = ?, pay_status = ?, pay_requested_at = COALESCE(pay_requested_at, ?), updated_at = ? WHERE id = ?')
            ->execute([$paid, $status, $now, $now, (int) $job['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ((int) $job['technician_id'] > 0 && (int) $job['technician_id'] !== (int) $s['user_id']) {
        mobile_api_notify((int) $job['technician_id'], 'paid', $status === 'paid' ? 'Installation paid' : 'Part payment received',
            $job['job_no'] . ' · ' . mobile_api_money($amount) . ($status === 'paid' ? ' - you can complete the job' : ' of ' . mobile_api_money($due)), 'install', (int) $job['id']);
    }
    mobile_api_out(['ok' => true, 'message' => ($status === 'paid' ? 'Paid in full' : 'Part payment saved') . ' (' . mobile_api_money($paid) . ' of ' . mobile_api_money($due) . ')', 'pay_status' => $status]);
}

function mobile_api_instpay_waive($s)
{
    mobile_api_instpay_need_manager($s);
    $reason = trim((string) mobile_api_in('note'));
    if (mb_strlen($reason) < 3) mobile_api_fail('Say why the payment is being skipped', 422);
    $db = ORM::get_db();
    $job = mobile_api_instpay_job($s);
    if (in_array($job['status'], ['cancelled', 'completed'], true)) mobile_api_fail('This installation is ' . $job['status'], 409);
    if ($job['pay_status'] === 'paid' || $job['pay_status'] === 'waived') mobile_api_fail('Payment is already ' . $job['pay_status'], 409);
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO tbl_installation_payments (job_id, method, ref, amount, payer, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int) $job['id'], 'waived', 'WAIVE-' . date('ymdHis') . '-' . substr(bin2hex(random_bytes(2)), 0, 4), 0, '', 'Payment skipped: ' . mb_substr($reason, 0, 200), $s['access']['name'], $now]);
    $db->prepare("UPDATE tbl_installations SET pay_status = 'waived', pay_requested_at = COALESCE(pay_requested_at, ?), updated_at = ? WHERE id = ?")->execute([$now, $now, (int) $job['id']]);
    if ((int) $job['technician_id'] > 0 && (int) $job['technician_id'] !== (int) $s['user_id']) {
        mobile_api_notify((int) $job['technician_id'], 'paid', 'Payment skipped', $job['job_no'] . ' - you can complete the job', 'install', (int) $job['id']);
    }
    mobile_api_out(['ok' => true, 'message' => 'Payment skipped for ' . $job['job_no'], 'pay_status' => 'waived']);
}

/** The website marks a job paid by itself when the M-Pesa payment arrives; tell the technician once. */
function mobile_api_pay_scan($s)
{
    try {
        $uid = (int) $s['user_id'];
        if (!in_array('installations', $s['access']['modules'], true)) return;
        $have = [];
        foreach (mobile_api_rows("SELECT entity_id FROM tbl_mobile_notify WHERE user_id = ? AND kind = 'paid' AND entity = 'install'", [$uid]) as $r) {
            $have[(int) $r['entity_id']] = true;
        }
        $rows = mobile_api_rows("SELECT id, job_no, amount_paid FROM tbl_installations WHERE technician_id = ? AND pay_status = 'paid' AND status NOT IN ('completed','cancelled') ORDER BY id DESC LIMIT 20", [$uid]);
        foreach ($rows as $r) {
            if (isset($have[(int) $r['id']])) continue;
            mobile_api_notify($uid, 'paid', 'Installation paid', $r['job_no'] . ' · ' . mobile_api_money($r['amount_paid']) . ' - you can complete the job', 'install', (int) $r['id']);
        }
    } catch (Throwable $e) {
    }
}


/* ------------------------------------------------------------------ */
/* reports                                                             */
/* ------------------------------------------------------------------ */

function mobile_api_report_range()
{
    $p = (string) mobile_api_in('period');
    $today = date('Y-m-d');
    switch ($p) {
        case 'today':
            return [$today, $today, 'Today'];
        case 'month':
            return [date('Y-m-01'), $today, 'This month'];
        case 'last_month':
            return [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month')), 'Last month'];
        case 'custom':
            $f = (string) mobile_api_in('from');
            $t = (string) mobile_api_in('to');
            if (mobile_api_hr_valid_date($f) && mobile_api_hr_valid_date($t) && $f <= $t) return [$f, $t, $f . ' to ' . $t];
            mobile_api_fail('Pick a valid date range', 422);
        default:
            return [date('Y-m-d', strtotime('-6 days')), $today, 'Last 7 days'];
    }
}

function mobile_api_report()
{
    list($from, $to, $label) = mobile_api_report_range();
    $f = $from . ' 00:00:00';
    $t = $to . ' 23:59:59';
    $amt = 'CAST(price AS DECIMAL(14,2))';

    // revenue
    $tot = mobile_api_rows("SELECT COUNT(*) n, COALESCE(SUM($amt),0) s FROM tbl_transactions WHERE recharged_on BETWEEN ? AND ?", [$from, $to]);
    $byDay = [];
    foreach (mobile_api_rows("SELECT recharged_on d, COUNT(*) n, SUM($amt) s FROM tbl_transactions WHERE recharged_on BETWEEN ? AND ? GROUP BY recharged_on ORDER BY recharged_on", [$from, $to]) as $r) {
        $byDay[$r['d']] = ['day' => $r['d'], 'count' => (int) $r['n'], 'total' => (float) $r['s']];
    }
    // every day in the range appears, so the chart has no gaps
    $days = [];
    for ($d = strtotime($from); $d <= strtotime($to) && count($days) < 62; $d += 86400) {
        $k = date('Y-m-d', $d);
        $days[] = isset($byDay[$k]) ? $byDay[$k] : ['day' => $k, 'count' => 0, 'total' => 0.0];
    }
    $methods = [];
    foreach (mobile_api_rows("SELECT method, COUNT(*) n, SUM($amt) s FROM tbl_transactions WHERE recharged_on BETWEEN ? AND ? GROUP BY method", [$from, $to]) as $r) {
        $name = trim(explode(' - ', (string) $r['method'])[0]);
        $name = $name === '' ? 'Other' : $name;
        if (!isset($methods[$name])) $methods[$name] = ['method' => $name, 'count' => 0, 'total' => 0.0];
        $methods[$name]['count'] += (int) $r['n'];
        $methods[$name]['total'] += (float) $r['s'];
    }
    usort($methods, function ($a, $b) { return $b['total'] <=> $a['total']; });
    $plans = [];
    foreach (mobile_api_rows("SELECT plan_name, COUNT(*) n, SUM($amt) s FROM tbl_transactions WHERE recharged_on BETWEEN ? AND ? GROUP BY plan_name ORDER BY s DESC LIMIT 10", [$from, $to]) as $r) {
        $plans[] = ['plan' => $r['plan_name'], 'count' => (int) $r['n'], 'total' => (float) $r['s']];
    }
    $inst = mobile_api_rows('SELECT COALESCE(SUM(amount),0) s, COUNT(*) n FROM tbl_installation_payments WHERE created_at BETWEEN ? AND ? AND method <> "waived"', [$f, $t]);

    // customers
    $on = mobile_api_rows("SELECT COUNT(DISTINCT customer_id) n FROM tbl_user_recharges WHERE status = 'on'");
    $off = mobile_api_rows("SELECT COUNT(DISTINCT customer_id) n FROM tbl_user_recharges WHERE status <> 'on' AND customer_id NOT IN (SELECT customer_id FROM tbl_user_recharges WHERE status = 'on')");
    $new = mobile_api_rows('SELECT COUNT(*) n FROM tbl_customers WHERE created_at BETWEEN ? AND ?', [$f, $t]);
    $lapsed = mobile_api_rows("SELECT COUNT(DISTINCT customer_id) n FROM tbl_user_recharges WHERE status <> 'on' AND expiration BETWEEN ? AND ? AND customer_id NOT IN (SELECT customer_id FROM tbl_user_recharges WHERE status = 'on')", [$from, $to]);
    $expiring = [];
    foreach (mobile_api_rows("SELECT r.username, r.namebp, r.expiration, r.time, c.fullname, c.phonenumber FROM tbl_user_recharges r LEFT JOIN tbl_customers c ON c.id = r.customer_id
            WHERE r.status = 'on' AND r.expiration BETWEEN ? AND ? ORDER BY r.expiration, r.time LIMIT 100", [date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))]) as $r) {
        $expiring[] = ['username' => $r['username'], 'name' => $r['fullname'] ?: $r['username'], 'phone' => $r['phonenumber'], 'plan' => $r['namebp'], 'expires' => trim($r['expiration'] . ' ' . $r['time'])];
    }

    // team
    $team = [];
    $touch = function ($id) use (&$team) {
        $id = (int) $id;
        if ($id > 0 && !isset($team[$id])) {
            $team[$id] = ['id' => $id, 'tickets_new' => 0, 'tickets_done' => 0, 'ticket_hours' => null, 'installs_done' => 0, 'install_hours' => null, 'overdue_now' => 0];
        }
        return $id;
    };
    try {
        foreach (mobile_api_rows('SELECT assigned_to a, COUNT(*) n FROM tbl_support_tickets WHERE assigned_to > 0 AND created_at BETWEEN ? AND ? GROUP BY assigned_to', [$f, $t]) as $r) {
            $team[$touch($r['a'])]['tickets_new'] = (int) $r['n'];
        }
        foreach (mobile_api_rows('SELECT assigned_to a, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))/60 h FROM tbl_support_tickets WHERE assigned_to > 0 AND resolved_at BETWEEN ? AND ? GROUP BY assigned_to', [$f, $t]) as $r) {
            $id = $touch($r['a']);
            $team[$id]['tickets_done'] = (int) $r['n'];
            $team[$id]['ticket_hours'] = $r['h'] === null ? null : round((float) $r['h'], 1);
        }
        foreach (mobile_api_rows("SELECT technician_id a, COUNT(*) n, AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at))/60 h FROM tbl_installations WHERE technician_id > 0 AND status IN ('completed','complete') AND completed_at BETWEEN ? AND ? GROUP BY technician_id", [$f, $t]) as $r) {
            $id = $touch($r['a']);
            $team[$id]['installs_done'] = (int) $r['n'];
            $team[$id]['install_hours'] = $r['h'] === null ? null : round((float) $r['h'], 1);
        }
        foreach (['ticket', 'install'] as $e) {
            $meta = mobile_api_ops_meta($e);
            if (!$meta || !$meta['map']['assignee'] || !$meta['assignee_int']) continue;
            foreach (ORM::for_table($meta['table'])->order_by_desc('id')->limit(500)->find_many() as $row) {
                $sla = mobile_api_sla($meta, $row->as_array());
                if ($sla && $sla['overdue']) {
                    $aid = (int) $row[$meta['map']['assignee']];
                    if ($aid > 0) $team[$touch($aid)]['overdue_now']++;
                }
            }
        }
    } catch (Throwable $e) {
    }
    $names = mobile_api_user_names();
    $teamOut = [];
    foreach ($team as $id => $t2) {
        $t2['name'] = isset($names[$id]) ? $names[$id] : '#' . $id;
        $teamOut[] = $t2;
    }
    usort($teamOut, function ($a, $b) { return ($b['tickets_done'] + $b['installs_done']) <=> ($a['tickets_done'] + $a['installs_done']); });

    mobile_api_out(['ok' => true, 'range' => ['from' => $from, 'to' => $to, 'label' => $label],
        'revenue' => ['total' => (float) $tot[0]['s'], 'count' => (int) $tot[0]['n'], 'days' => $days, 'methods' => array_values($methods), 'plans' => $plans,
            'install_collected' => (float) $inst[0]['s'], 'install_payments' => (int) $inst[0]['n']],
        'customers' => ['active' => (int) $on[0]['n'], 'expired' => (int) $off[0]['n'], 'new' => (int) $new[0]['n'], 'lapsed' => (int) $lapsed[0]['n'], 'expiring' => $expiring],
        'team' => $teamOut]);
}


function mobile_api_csv($rows)
{
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(function ($v) {
            $v = (string) $v;
            // a cell that starts with = + - @ would run as a formula in Excel: make it text
            return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
        }, $r));
    }
    rewind($fh);
    $out = stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . $out;
}

function mobile_api_report_export()
{
    list($from, $to) = mobile_api_report_range();
    $kind = (string) mobile_api_in('kind');
    $f0 = $from . ' 00:00:00';
    $t1 = $to . ' 23:59:59';
    $rows = [];
    if ($kind === 'revenue') {
        $rows[] = ['Invoice', 'Date', 'Time', 'Username', 'Plan', 'Amount', 'Method', 'Type'];
        foreach (mobile_api_rows('SELECT invoice, recharged_on, recharged_time, username, plan_name, price, method, type FROM tbl_transactions WHERE recharged_on BETWEEN ? AND ? ORDER BY id', [$from, $to]) as $t) {
            $rows[] = [$t['invoice'], $t['recharged_on'], $t['recharged_time'], $t['username'], $t['plan_name'], $t['price'], $t['method'], $t['type']];
        }
    } elseif ($kind === 'expiring') {
        $rows[] = ['Username', 'Name', 'Phone', 'Plan', 'Expires'];
        foreach (mobile_api_rows("SELECT r.username, r.namebp, r.expiration, c.fullname, c.phonenumber FROM tbl_user_recharges r LEFT JOIN tbl_customers c ON c.id = r.customer_id
                WHERE r.status = 'on' AND r.expiration BETWEEN ? AND ? ORDER BY r.expiration", [date('Y-m-d'), date('Y-m-d', strtotime('+14 days'))]) as $r) {
            $rows[] = [$r['username'], $r['fullname'], $r['phonenumber'], $r['namebp'], $r['expiration']];
        }
    } elseif ($kind === 'installs') {
        $rows[] = ['Job', 'Customer', 'Phone', 'Plan', 'Status', 'Payment', 'Due', 'Paid', 'Created', 'Completed'];
        foreach (mobile_api_rows('SELECT job_no, customer_name, phone, plan_name, status, pay_status, amount_due, amount_paid, created_at, completed_at FROM tbl_installations WHERE created_at BETWEEN ? AND ? ORDER BY id', [$f0, $t1]) as $i) {
            $rows[] = [$i['job_no'], $i['customer_name'], $i['phone'], $i['plan_name'], $i['status'], $i['pay_status'], $i['amount_due'], $i['amount_paid'], $i['created_at'], $i['completed_at']];
        }
    } elseif ($kind === 'tickets') {
        $names = mobile_api_user_names();
        $rows[] = ['No', 'Title', 'Status', 'Priority', 'Assigned to', 'Created', 'Resolved'];
        foreach (mobile_api_rows('SELECT id, title, status, priority, assigned_to, created_at, resolved_at FROM tbl_support_tickets WHERE created_at BETWEEN ? AND ? ORDER BY id', [$f0, $t1]) as $t) {
            $a = (int) $t['assigned_to'];
            $rows[] = [$t['id'], $t['title'], $t['status'], $t['priority'], isset($names[$a]) ? $names[$a] : '', $t['created_at'], $t['resolved_at']];
        }
    } else {
        mobile_api_fail('Choose what to export', 422);
    }
    mobile_api_out(['ok' => true, 'filename' => 'eazyradius-' . $kind . '-' . $from . '_' . $to . '.csv', 'rows' => count($rows) - 1, 'csv' => mobile_api_csv($rows)]);
}


/* ------------------------------------------------------------------ */
/* security: sessions and remote logout                                */
/* ------------------------------------------------------------------ */

function mobile_api_token_roles($s)
{
    return $s['role'] === 'client' ? ['client'] : ['admin', 'technician', 'staff'];
}

function mobile_api_sessions_list($s)
{
    $in = implode(',', array_fill(0, count(mobile_api_token_roles($s)), '?'));
    $rows = mobile_api_rows('SELECT t.id, t.created_at, t.last_used, i.device, i.ip FROM tbl_mobile_tokens t LEFT JOIN tbl_mobile_token_info i ON i.token_id = t.id
        WHERE t.user_id = ? AND t.role IN (' . $in . ') ORDER BY t.last_used DESC LIMIT 20', array_merge([(int) $s['user_id']], mobile_api_token_roles($s)));
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'device' => $r['device'] ?: 'Unknown device', 'ip' => (string) $r['ip'], 'signed_in' => $r['created_at'],
            'last_used' => $r['last_used'], 'current' => (int) $r['id'] === (int) $s['token_id']];
    }
    mobile_api_out(['ok' => true, 'sessions' => $out]);
}

function mobile_api_session_revoke($s)
{
    $roles = mobile_api_token_roles($s);
    $in = implode(',', array_fill(0, count($roles), '?'));
    if ((string) mobile_api_in('others') === '1') {
        $st = ORM::get_db()->prepare('DELETE FROM tbl_mobile_tokens WHERE user_id = ? AND role IN (' . $in . ') AND id <> ?');
        $st->execute(array_merge([(int) $s['user_id']], $roles, [(int) $s['token_id']]));
        mobile_api_out(['ok' => true, 'message' => 'Signed out of ' . $st->rowCount() . ' other device(s)']);
    }
    $id = (int) mobile_api_in('id');
    if ($id === (int) $s['token_id']) mobile_api_fail('Use Logout to sign out of this phone', 422);
    $st = ORM::get_db()->prepare('DELETE FROM tbl_mobile_tokens WHERE id = ? AND user_id = ? AND role IN (' . $in . ')');
    $st->execute(array_merge([$id, (int) $s['user_id']], $roles));
    if ($st->rowCount() === 0) mobile_api_fail('Session not found', 404);
    mobile_api_out(['ok' => true, 'message' => 'Device signed out']);
}

/** An admin signs a user out of every phone (a lost phone, a leaver). */
function mobile_api_admin_user_logout($s)
{
    $actor = mobile_api_actor($s);
    mobile_api_need_manager($actor);
    $id = (int) mobile_api_in('id');
    $u = ORM::for_table('tbl_users')->find_one($id);
    if (!$u) mobile_api_fail('User not found', 404);
    if ($id !== (int) $actor['id'] && !mobile_api_can_manage($actor, $u['user_type'])) mobile_api_fail('You cannot sign this user out', 403);
    $n = count(mobile_api_rows("SELECT id FROM tbl_mobile_tokens WHERE user_id = ? AND role IN ('admin','technician','staff')", [$id]));
    mobile_api_revoke_staff_tokens($id);
    mobile_api_out(['ok' => true, 'message' => ($u['fullname'] ?: $u['username']) . ' signed out of ' . $n . ' device(s)']);
}

/* ------------------------------------------------------------------ */
/* ticket comments                                                     */
/* ------------------------------------------------------------------ */

function mobile_api_ticket_row_for_staff($s)
{
    mobile_api_staff($s);
    mobile_api_ops_gate($s, 'ticket');
    $meta = mobile_api_ops_meta('ticket');
    if (!$meta) mobile_api_fail('Table not found', 404);
    $row = ORM::for_table($meta['table'])->find_one((int) mobile_api_in('id'));
    if (!$row) mobile_api_fail('Ticket not found', 404);
    $uid = mobile_api_scope_uid($s);
    if ($uid && !mobile_api_ops_is_mine($meta, $row->as_array(), $uid)) mobile_api_fail('This ticket is not assigned to you', 403);
    return [$meta, $row];
}

function mobile_api_comment_rows($ticketId, $withInternal)
{
    $out = [];
    foreach (mobile_api_rows('SELECT id, author_type, author_name, body, internal, created_at FROM tbl_mobile_comments WHERE ticket_id = ?' . ($withInternal ? '' : ' AND internal = 0') . ' ORDER BY id', [(int) $ticketId]) as $c) {
        $out[] = ['id' => (int) $c['id'], 'by' => $c['author_name'], 'type' => $c['author_type'], 'text' => $c['body'], 'internal' => (bool) $c['internal'], 'time' => $c['created_at']];
    }
    return $out;
}

function mobile_api_ticket_customer_username($desc)
{
    return preg_match('/^Username:\s*(\S+)/mi', (string) $desc, $m) ? $m[1] : '';
}

function mobile_api_ticket_comments($s)
{
    list($meta, $row) = mobile_api_ticket_row_for_staff($s);
    $it = mobile_api_ops_item($meta, $row->as_array());
    mobile_api_out(['ok' => true, 'ticket' => ['id' => $it['id'], 'title' => $it['title'], 'status' => $it['status'], 'customer' => $it['customer_username']], 'comments' => mobile_api_comment_rows($row['id'], true)]);
}

function mobile_api_ticket_comment_add($s)
{
    list($meta, $row) = mobile_api_ticket_row_for_staff($s);
    $body = trim((string) mobile_api_in('body'));
    if ($body === '') mobile_api_fail('Write a comment first', 422);
    if (mb_strlen($body) > 1000) mobile_api_fail('That comment is too long (1000 characters)', 422);
    $internal = (string) mobile_api_in('internal') === '1' ? 1 : 0;
    $now = date('Y-m-d H:i:s');
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_comments (ticket_id, author_type, author_id, author_name, body, internal, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int) $row['id'], 'staff', (int) $s['user_id'], $s['access']['name'], $body, $internal, $now]);
    if ($meta['map']['updated']) {
        $row->set($meta['map']['updated'], $now);
        $row->save();
    }
    $it = mobile_api_ops_item($meta, $row->as_array());
    if ($it['assignee_id'] > 0 && $it['assignee_id'] !== (int) $s['user_id']) {
        mobile_api_notify($it['assignee_id'], 'comment', 'New comment on a ticket', $s['access']['name'] . ': ' . mb_substr($body, 0, 120), 'ticket', (int) $row['id']);
    }
    if (!$internal && $it['customer_username'] !== '') {
        $c = ORM::for_table('tbl_customers')->where('username', $it['customer_username'])->find_one();
        if ($c) mobile_api_notify(-(int) $c['id'], 'reply', 'Reply on your ticket', mb_substr($body, 0, 150), 'ticket', (int) $row['id']);
    }
    mobile_api_out(['ok' => true, 'message' => $internal ? 'Internal note saved' : 'Comment sent']);
}

/* ------------------------------------------------------------------ */
/* outage notices                                                      */
/* ------------------------------------------------------------------ */

function mobile_api_notice_list()
{
    $out = [];
    foreach (mobile_api_rows('SELECT id, title, body, severity, active, ends_at, created_by_name, created_at FROM tbl_mobile_notices ORDER BY id DESC LIMIT 40') as $n) {
        $live = (int) $n['active'] === 1 && ($n['ends_at'] === null || strtotime($n['ends_at']) > time());
        $out[] = ['id' => (int) $n['id'], 'title' => $n['title'], 'body' => (string) $n['body'], 'severity' => $n['severity'], 'live' => $live,
            'ends_at' => $n['ends_at'], 'by' => $n['created_by_name'], 'created' => $n['created_at']];
    }
    mobile_api_out(['ok' => true, 'notices' => $out]);
}

function mobile_api_notice_save($s)
{
    $title = trim((string) mobile_api_in('title'));
    $body = trim((string) mobile_api_in('body'));
    $sev = (string) mobile_api_in('severity');
    if (!in_array($sev, ['info', 'warning', 'outage'], true)) $sev = 'info';
    if ($title === '') mobile_api_fail('Enter a title', 422);
    $hours = (int) mobile_api_in('hours', 0);
    $ends = $hours > 0 ? date('Y-m-d H:i:s', time() + min($hours, 24 * 30) * 3600) : null;
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_notices (title, body, severity, active, ends_at, created_by, created_by_name, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([mb_substr($title, 0, 120), mb_substr($body, 0, 500), $sev, 1, $ends, (int) $s['user_id'], $s['access']['name'], date('Y-m-d H:i:s')]);
    mobile_api_out(['ok' => true, 'id' => (int) ORM::get_db()->lastInsertId(), 'message' => 'Notice published to customers']);
}

function mobile_api_notice_end($s)
{
    $st = ORM::get_db()->prepare('UPDATE tbl_mobile_notices SET active = 0 WHERE id = ?');
    $st->execute([(int) mobile_api_in('id')]);
    mobile_api_out(['ok' => true, 'message' => 'Notice ended']);
}

function mobile_api_live_notices()
{
    $out = [];
    foreach (mobile_api_rows('SELECT id, title, body, severity, created_at FROM tbl_mobile_notices WHERE active = 1 AND (ends_at IS NULL OR ends_at > ?) ORDER BY id DESC LIMIT 5', [date('Y-m-d H:i:s')]) as $n) {
        $out[] = ['id' => (int) $n['id'], 'title' => $n['title'], 'body' => (string) $n['body'], 'severity' => $n['severity'], 'time' => $n['created_at']];
    }
    return $out;
}

function mobile_api_client_notices()
{
    mobile_api_out(['ok' => true, 'notices' => mobile_api_live_notices()]);
}

/** Customers are alerted to new notices and to replies on their tickets (their notify rows use the negative customer id). */
function mobile_api_client_poll($s, $now)
{
    $cid = (int) $s['user_id'];
    $sinceId = (int) mobile_api_in('since_id', 0);
    $sinceTs = (int) mobile_api_in('since_ts', 0);
    $last = mobile_api_rows('SELECT MAX(id) m FROM tbl_mobile_notify WHERE user_id = ?', [-$cid]);
    $maxId = $last && $last[0]['m'] ? (int) $last[0]['m'] : 0;
    $items = [];
    if (!($sinceId === 0 && $sinceTs === 0)) {
        foreach (mobile_api_rows('SELECT id, kind, title, body, entity, entity_id, created_at FROM tbl_mobile_notify WHERE user_id = ? AND id > ? ORDER BY id LIMIT 20', [-$cid, $sinceId]) as $n) {
            $items[] = ['key' => 'n' . $n['id'], 'kind' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'entity' => $n['entity'], 'id' => $n['entity_id'], 'time' => $n['created_at']];
        }
        foreach (mobile_api_rows('SELECT id, title, body, severity, created_at FROM tbl_mobile_notices WHERE active = 1 AND created_at > ? ORDER BY id LIMIT 5', [date('Y-m-d H:i:s', $sinceTs)]) as $n) {
            $items[] = ['key' => 'x' . $n['id'], 'kind' => 'notice', 'title' => ($n['severity'] === 'outage' ? 'Network outage: ' : 'Notice: ') . $n['title'], 'body' => $n['body'], 'entity' => 'notice', 'id' => (int) $n['id'], 'time' => $n['created_at']];
        }
    }
    mobile_api_out(['ok' => true, 'items' => $items, 'max_id' => $maxId, 'now' => $now]);
}

/* ------------------------------------------------------------------ */
/* client: tickets                                                     */
/* ------------------------------------------------------------------ */

function mobile_api_client_row($s)
{
    $c = ORM::for_table('tbl_customers')->find_one($s['user_id']);
    if (!$c) mobile_api_fail('Account not found', 404);
    return $c;
}

function mobile_api_client_tickets($s)
{
    $c = mobile_api_client_row($s);
    $tm = mobile_api_ops_meta('ticket');
    $out = [];
    if ($tm && $tm['map']['notes']) {
        $orm = ORM::for_table($tm['table'])->where_raw('`' . $tm['map']['notes'] . '` LIKE ?', ['%Username: ' . $c['username'] . '%'])->order_by_desc('id')->limit(30);
        foreach ($orm->find_many() as $t) {
            if (strcasecmp(mobile_api_ticket_customer_username($t[$tm['map']['notes']]), $c['username']) !== 0) continue;
            $it = mobile_api_ops_item($tm, $t->as_array());
            $n = mobile_api_rows('SELECT COUNT(*) n FROM tbl_mobile_comments WHERE ticket_id = ? AND internal = 0', [(int) $t['id']]);
            $out[] = ['id' => $it['id'], 'title' => $it['title'], 'status' => $it['status'], 'created' => $it['created'], 'comments' => (int) $n[0]['n']];
        }
    }
    mobile_api_out(['ok' => true, 'tickets' => $out]);
}

function mobile_api_client_ticket_create($s)
{
    $c = mobile_api_client_row($s);
    $tm = mobile_api_ops_meta('ticket');
    if (!$tm || $tm['fallback'] || !$tm['map']['notes']) mobile_api_fail('Support tickets are not available right now', 503);
    $title = trim((string) mobile_api_in('title'));
    $notes = trim((string) mobile_api_in('notes'));
    if ($title === '') mobile_api_fail('Tell us what the problem is', 422);
    $recent = mobile_api_rows('SELECT COUNT(*) n FROM `' . $tm['table'] . '` WHERE `' . $tm['map']['notes'] . '` LIKE ? AND `' . $tm['map']['created'] . '` > ?', ['%Username: ' . $c['username'] . '%', date('Y-m-d H:i:s', time() - 3600)]);
    if ((int) $recent[0]['n'] >= 3) mobile_api_fail('You have sent several tickets just now. Please wait, we are on it.', 429);
    $now = date('Y-m-d H:i:s');
    $desc = 'Customer: ' . ($c['fullname'] ?: $c['username']) . ' (' . $c['phonenumber'] . ')';
    if (trim((string) $c['address']) !== '') $desc .= "\nLocation: " . trim($c['address']);
    $desc .= "\nUsername: " . $c['username'] . "\nRaised from the customer app";
    if ($notes !== '') $desc .= "\n\n" . mb_substr($notes, 0, 1000);
    $m = $tm['map'];
    $r = ORM::for_table($tm['table'])->create();
    $r->set($m['title'], mb_substr($title, 0, 190));
    $r->set($m['notes'], $desc);
    if ($m['status']) $r->set($m['status'], 'open');
    if ($m['priority']) $r->set($m['priority'], 'Medium');
    if ($m['created']) $r->set($m['created'], $now);
    if ($m['updated']) $r->set($m['updated'], $now);
    $cat = (int) mobile_api_in('category_id', 0);
    if ($cat > 0 && isset($tm['cols']['category_id']) && mobile_api_rows('SELECT id FROM tbl_support_categories WHERE id = ?', [$cat])) $r->set('category_id', $cat);
    if (isset($tm['cols']['created_by'])) $r->set('created_by', 0);
    if (isset($tm['cols']['log'])) $r->set('log', date('Y-m-d H:i') . ' - ' . $c['username'] . ' raised the ticket from the customer app');
    $data = $r->as_array();
    foreach ($tm['cols'] as $col) {
        $f = $col['Field'];
        if ($col['Null'] === 'NO' && $col['Default'] === null && strpos($col['Extra'], 'auto_increment') === false && !array_key_exists($f, $data)) {
            if (stripos($col['Type'], 'int') !== false) $r->set($f, 0);
            elseif (stripos($col['Type'], 'date') !== false || stripos($col['Type'], 'timestamp') !== false) $r->set($f, $now);
            else $r->set($f, '');
        }
    }
    $r->save();
    mobile_api_notify_managers('support', true, 'new_ticket', 'New customer ticket needs assigning', ($c['fullname'] ?: $c['username']) . ': ' . mb_substr($title, 0, 100), 'ticket', (int) $r['id'], 0);
    mobile_api_out(['ok' => true, 'id' => (int) $r['id'], 'message' => 'Ticket sent. We will get back to you.']);
}

function mobile_api_client_ticket_row($s)
{
    $c = mobile_api_client_row($s);
    $tm = mobile_api_ops_meta('ticket');
    if (!$tm || !$tm['map']['notes']) mobile_api_fail('Not found', 404);
    $row = ORM::for_table($tm['table'])->find_one((int) mobile_api_in('id'));
    if (!$row || strcasecmp(mobile_api_ticket_customer_username($row[$tm['map']['notes']]), $c['username']) !== 0) mobile_api_fail('Ticket not found', 404);
    return [$c, $tm, $row];
}

function mobile_api_client_ticket_comments($s)
{
    list($c, $tm, $row) = mobile_api_client_ticket_row($s);
    $it = mobile_api_ops_item($tm, $row->as_array());
    mobile_api_out(['ok' => true, 'ticket' => ['id' => $it['id'], 'title' => $it['title'], 'status' => $it['status']], 'comments' => mobile_api_comment_rows($row['id'], false)]);
}

function mobile_api_client_ticket_comment_add($s)
{
    list($c, $tm, $row) = mobile_api_client_ticket_row($s);
    $body = trim((string) mobile_api_in('body'));
    if ($body === '') mobile_api_fail('Write a message first', 422);
    if (mb_strlen($body) > 1000) mobile_api_fail('That message is too long (1000 characters)', 422);
    $recent = mobile_api_rows('SELECT COUNT(*) n FROM tbl_mobile_comments WHERE author_type = "client" AND author_id = ? AND created_at > ?', [(int) $c['id'], date('Y-m-d H:i:s', time() - 600)]);
    if ((int) $recent[0]['n'] >= 10) mobile_api_fail('Too many messages in a short time. Please wait a few minutes.', 429);
    $now = date('Y-m-d H:i:s');
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_comments (ticket_id, author_type, author_id, author_name, body, internal, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int) $row['id'], 'client', (int) $c['id'], $c['fullname'] ?: $c['username'], $body, 0, $now]);
    if ($tm['map']['updated']) {
        $row->set($tm['map']['updated'], $now);
        $row->save();
    }
    $it = mobile_api_ops_item($tm, $row->as_array());
    $text = ($c['fullname'] ?: $c['username']) . ': ' . mb_substr($body, 0, 120);
    if ($it['assignee_id'] > 0) mobile_api_notify($it['assignee_id'], 'comment', 'Customer replied on a ticket', $text, 'ticket', (int) $row['id']);
    else mobile_api_notify_managers('support', true, 'comment', 'Customer replied on an unassigned ticket', $text, 'ticket', (int) $row['id'], 0);
    mobile_api_out(['ok' => true, 'message' => 'Sent']);
}

/* ------------------------------------------------------------------ */
/* client: Wi-Fi name and password through TR-069                       */
/* ------------------------------------------------------------------ */

function mobile_api_client_device($s)
{
    $rows = mobile_api_rows('SELECT device_id FROM tbl_tr069_links WHERE customer_id = ? LIMIT 1', [(int) $s['user_id']]);
    return $rows ? $rows[0]['device_id'] : null;
}

/** The Wi-Fi parameter paths this router uses, found from its own data model. */
function mobile_api_wifi_paths($doc)
{
    if (isset($doc['InternetGatewayDevice'])) {
        $base = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1';
        $key = mobile_api_nbi_val($doc, $base . '.PreSharedKey.1.KeyPassphrase') !== null ? $base . '.PreSharedKey.1.KeyPassphrase' : $base . '.PreSharedKey.1.PreSharedKey';
        return ['ssid' => $base . '.SSID', 'key' => $key];
    }
    if (isset($doc['Device'])) {
        return ['ssid' => 'Device.WiFi.SSID.1.SSID', 'key' => 'Device.WiFi.AccessPoint.1.Security.KeyPassphrase'];
    }
    return null;
}

function mobile_api_wifi_doc($id)
{
    $proj = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1,Device.WiFi.SSID.1,Device.WiFi.AccessPoint.1.Security';
    $docs = mobile_api_nbi('/devices?query=' . rawurlencode(json_encode(['_id' => $id])) . '&projection=' . rawurlencode($proj));
    return (is_array($docs) && isset($docs[0])) ? $docs[0] : null;
}

function mobile_api_client_wifi_get($s)
{
    $id = mobile_api_client_device($s);
    if (!$id) mobile_api_out(['ok' => true, 'linked' => false, 'message' => 'Your router is not linked to your account yet. Contact support.']);
    $doc = mobile_api_wifi_doc($id);
    $paths = $doc ? mobile_api_wifi_paths($doc) : null;
    mobile_api_out(['ok' => true, 'linked' => true, 'reachable' => $doc !== null, 'supported' => $paths !== null,
        'ssid' => $paths ? (string) mobile_api_nbi_val($doc, $paths['ssid']) : '']);
}

function mobile_api_client_wifi_set($s)
{
    $id = mobile_api_client_device($s);
    if (!$id) mobile_api_fail('Your router is not linked to your account yet. Contact support.', 404);
    $ssid = trim((string) mobile_api_in('ssid'));
    $pass = (string) mobile_api_in('password');
    if ($ssid === '' && $pass === '') mobile_api_fail('Enter a new Wi-Fi name or password', 422);
    if ($ssid !== '' && (mb_strlen($ssid) > 32 || preg_match('/[\x00-\x1f\x7f]/', $ssid))) mobile_api_fail('The Wi-Fi name must be 1 to 32 characters', 422);
    if ($pass !== '' && (strlen($pass) < 8 || strlen($pass) > 63 || preg_match('/[\x00-\x1f\x7f]/', $pass))) mobile_api_fail('The password must be 8 to 63 characters', 422);
    // one change at a time: a router reconnects its clients every time this runs
    $last = mobile_api_rows("SELECT created_at FROM tbl_mobile_audit WHERE user_id = ? AND action = 'wifi_change' AND details NOT LIKE 'FAILED%' ORDER BY id DESC LIMIT 1", [(int) $s['user_id']]);
    if ($last && strtotime($last[0]['created_at']) > time() - 300) mobile_api_fail('You changed your Wi-Fi a moment ago. Wait 5 minutes before changing it again.', 429);
    $doc = mobile_api_wifi_doc($id);
    if ($doc === null) mobile_api_fail('We could not reach your router right now. Try again later.', 502);
    $paths = mobile_api_wifi_paths($doc);
    if (!$paths) mobile_api_fail('This router model cannot be changed from the app. Contact support.', 422);
    $vals = [];
    if ($ssid !== '') $vals[] = [$paths['ssid'], $ssid, 'xsd:string'];
    if ($pass !== '') $vals[] = [$paths['key'], $pass, 'xsd:string'];
    $res = mobile_api_nbi('/devices/' . rawurlencode($id) . '/tasks?connection_request', 'POST', ['name' => 'setParameterValues', 'parameterValues' => $vals]);
    if ($res === null) mobile_api_fail('The router did not accept the change. Try again later.', 502);
    try {
        ORM::get_db()->prepare('INSERT INTO tbl_tr069_log (device_id, task, status, detail, created_at) VALUES (?,?,?,?,?)')
            ->execute([$id, 'wifi', 'sent', 'Customer changed ' . ($ssid !== '' ? 'name' : '') . ($ssid !== '' && $pass !== '' ? ' and ' : '') . ($pass !== '' ? 'password' : '') . ' from the app', date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
    }
    mobile_api_out(['ok' => true, 'message' => 'Sent to your router. Devices on Wi-Fi will disconnect for a moment and need the new ' . ($pass !== '' ? 'password' : 'name') . '.']);
}

/* ------------------------------------------------------------------ */
/* client: M-Pesa STK push to the paybill (the website's C2B flow credits it) */
/* ------------------------------------------------------------------ */

function mobile_api_phone254($p)
{
    $d = preg_replace('/\D+/', '', (string) $p);
    if (strpos($d, '254') === 0 && strlen($d) === 12) return $d;
    if (strpos($d, '0') === 0 && strlen($d) === 10) return '254' . substr($d, 1);
    if (strlen($d) === 9 && ($d[0] === '7' || $d[0] === '1')) return '254' . $d;
    return null;
}

function mobile_api_mpesa_base()
{
    global $config;
    if (!empty($config['mobile_mpesa_base'])) return rtrim($config['mobile_mpesa_base'], '/');
    return (isset($config['mpesa_env']) && $config['mpesa_env'] === 'live') ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

function mobile_api_mpesa_call($path, $body, $token = null, $basic = null)
{
    $ch = curl_init(mobile_api_mpesa_base() . $path);
    $h = ['Content-Type: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h];
    if ($basic) $opt[CURLOPT_USERPWD] = $basic;
    if ($body !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    return [$code, is_array($j) ? $j : []];
}

function mobile_api_mpesa_token()
{
    global $config;
    if (empty($config['mpesa_consumer_key']) || empty($config['mpesa_consumer_secret']) || empty($config['mpesa_business_code']) || empty($config['mpesa_pass_key'])) {
        mobile_api_fail('Paying with M-Pesa from the app is not set up yet. Please pay to the paybill.', 503);
    }
    list($code, $j) = mobile_api_mpesa_call('/oauth/v1/generate?grant_type=client_credentials', null, null, $config['mpesa_consumer_key'] . ':' . $config['mpesa_consumer_secret']);
    if ($code !== 200 || empty($j['access_token'])) mobile_api_fail('M-Pesa is not reachable right now. Please try again.', 502);
    return $j['access_token'];
}

function mobile_api_client_stk($s)
{
    global $config;
    $c = mobile_api_client_row($s);
    $amount = (int) mobile_api_in('amount');
    if ($amount < 1 || $amount > 150000) mobile_api_fail('Enter an amount between 1 and 150,000', 422);
    $phone = mobile_api_phone254(mobile_api_in('phone') !== '' ? mobile_api_in('phone') : $c['phonenumber']);
    if (!$phone) mobile_api_fail('Enter a Safaricom number like 0712345678', 422);
    $recent = mobile_api_rows("SELECT COUNT(*) n FROM tbl_mobile_stk WHERE customer_id = ? AND created_at > ?", [(int) $c['id'], date('Y-m-d H:i:s', time() - 60)]);
    if ((int) $recent[0]['n'] > 0) mobile_api_fail('A payment request was just sent. Check your phone, or wait a minute.', 429);
    $day = mobile_api_rows("SELECT COUNT(*) n FROM tbl_mobile_stk WHERE customer_id = ? AND created_at > ?", [(int) $c['id'], date('Y-m-d H:i:s', time() - 86400)]);
    if ((int) $day[0]['n'] >= 10) mobile_api_fail('Daily limit reached. Please pay to the paybill instead.', 429);

    $tok = mobile_api_mpesa_token();
    $ts = date('YmdHis');
    $short = (string) $config['mpesa_business_code'];
    $till = isset($config['mpesa_shortcode_type']) && stripos($config['mpesa_shortcode_type'], 'till') !== false;
    $base = defined('APP_URL') ? APP_URL : ((isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://example.invalid'));
    list($code, $j) = mobile_api_mpesa_call('/mpesa/stkpush/v1/processrequest', [
        'BusinessShortCode' => $short,
        'Password' => base64_encode($short . $config['mpesa_pass_key'] . $ts),
        'Timestamp' => $ts,
        'TransactionType' => $till ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $short,
        'PhoneNumber' => $phone,
        'CallBackURL' => rtrim($base, '/') . '/?_route=plugin/mobile_api&a=mpesa_cb',
        'AccountReference' => mb_substr($c['username'], 0, 12),
        'TransactionDesc' => 'Internet',
    ], $tok);
    if ($code !== 200 || (isset($j['ResponseCode']) && (string) $j['ResponseCode'] !== '0') || empty($j['CheckoutRequestID'])) {
        mobile_api_fail('M-Pesa did not accept the request' . (!empty($j['errorMessage']) ? ': ' . $j['errorMessage'] : '') . '. Please try again.', 502);
    }
    $now = date('Y-m-d H:i:s');
    ORM::get_db()->prepare('INSERT INTO tbl_mobile_stk (customer_id, phone, amount, checkout_id, status, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([(int) $c['id'], $phone, $amount, $j['CheckoutRequestID'], 'pending', $now]);
    mobile_api_out(['ok' => true, 'id' => (int) ORM::get_db()->lastInsertId(), 'message' => 'Check your phone and enter your M-Pesa PIN.']);
}

function mobile_api_client_stk_status($s)
{
    $rows = mobile_api_rows('SELECT * FROM tbl_mobile_stk WHERE id = ? AND customer_id = ?', [(int) mobile_api_in('id'), (int) $s['user_id']]);
    if (!$rows) mobile_api_fail('Payment not found', 404);
    $r = $rows[0];
    if ($r['status'] === 'pending' && strtotime($r['created_at']) > time() - 600) {
        // ask M-Pesa directly: the result does not depend on the callback arriving
        global $config;
        try {
            $tok = mobile_api_mpesa_token();
            $ts = date('YmdHis');
            $short = (string) $config['mpesa_business_code'];
            list($code, $j) = mobile_api_mpesa_call('/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $short, 'Password' => base64_encode($short . $config['mpesa_pass_key'] . $ts), 'Timestamp' => $ts, 'CheckoutRequestID' => $r['checkout_id'],
            ], $tok);
            if (isset($j['ResultCode'])) {
                $rc = (string) $j['ResultCode'];
                $status = $rc === '0' ? 'success' : ($rc === '1032' ? 'cancelled' : 'failed');
                ORM::get_db()->prepare('UPDATE tbl_mobile_stk SET status = ?, result_desc = ?, updated_at = ? WHERE id = ? AND status = "pending"')
                    ->execute([$status, mb_substr((string) (isset($j['ResultDesc']) ? $j['ResultDesc'] : ''), 0, 200), date('Y-m-d H:i:s'), (int) $r['id']]);
                $r['status'] = $status;
                $r['result_desc'] = isset($j['ResultDesc']) ? $j['ResultDesc'] : '';
            }
        } catch (Throwable $e) {
        }
    } elseif ($r['status'] === 'pending') {
        ORM::get_db()->prepare('UPDATE tbl_mobile_stk SET status = "failed", result_desc = "Timed out", updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $r['id']]);
        $r['status'] = 'failed';
        $r['result_desc'] = 'Timed out';
    }
    $msg = ['pending' => 'Waiting for you to enter your PIN...', 'success' => 'Payment received. Your account updates automatically in a moment.',
        'cancelled' => 'You cancelled the payment.', 'failed' => 'The payment did not go through. ' . (string) $r['result_desc']][$r['status']];
    mobile_api_out(['ok' => true, 'status' => $r['status'], 'message' => $msg]);
}

/** Safaricom calls this when the customer answers the prompt. It only records the result; the account is credited by the website's C2B flow. */
function mobile_api_mpesa_cb()
{
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    $cb = is_array($j) && isset($j['Body']['stkCallback']) ? $j['Body']['stkCallback'] : null;
    if ($cb && !empty($cb['CheckoutRequestID'])) {
        try {
            $rc = (string) (isset($cb['ResultCode']) ? $cb['ResultCode'] : '');
            $status = $rc === '0' ? 'success' : ($rc === '1032' ? 'cancelled' : 'failed');
            ORM::get_db()->prepare('UPDATE tbl_mobile_stk SET status = ?, result_desc = ?, updated_at = ? WHERE checkout_id = ? AND status = "pending"')
                ->execute([$status, mb_substr((string) (isset($cb['ResultDesc']) ? $cb['ResultDesc'] : ''), 0, 200), date('Y-m-d H:i:s'), (string) $cb['CheckoutRequestID']]);
        } catch (Throwable $e) {
        }
    }
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}
