<?php
/**
 * Live Chat for PHPNuxBill (website side of Eazyradius CRM live chat)
 *
 * - A chat bubble appears on the customer portal and the login page. Logged-in customers
 *   chat as themselves; visitors give a name first.
 * - Staff answer from Services > Live chat in the admin panel.
 * - Uses the same tables as the Eazyradius CRM app (tbl_mobile_chat, tbl_mobile_chat_msg,
 *   tbl_mobile_settings), so a chat started on the website can be answered in the app and back.
 *   It also works alone: the tables are created on first use.
 *
 * Install: copy this file to system/plugin/livechat.php and livechat.tpl to system/plugin/ui/livechat.tpl.
 */

register_menu('Live chat', true, 'livechat', 'SERVICES', 'ion ion-chatbubbles', '', '', ['Admin', 'SuperAdmin', 'Agent', 'Sales']);

/* ---------------------------------------------------------------- helpers */

function lc_db()
{
    return ORM::get_db();
}

function lc_rows($sql, $args = [])
{
    $st = lc_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function lc_ready()
{
    if (!empty($_SESSION['lc_ready'])) return;
    $db = lc_db();
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_settings (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_chat (
        id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL DEFAULT 0, guest_name VARCHAR(80) NULL, guest_contact VARCHAR(80) NULL,
        guest_token VARCHAR(64) NULL, source VARCHAR(10) NOT NULL DEFAULT 'app', status VARCHAR(10) NOT NULL DEFAULT 'open',
        assigned_to INT NOT NULL DEFAULT 0, assigned_name VARCHAR(100) NULL, last_msg_at DATETIME NOT NULL, last_sender VARCHAR(10) NULL,
        last_preview VARCHAR(160) NULL, staff_unread INT NOT NULL DEFAULT 0, client_unread INT NOT NULL DEFAULT 0, rating TINYINT NULL,
        created_at DATETIME NOT NULL, closed_at DATETIME NULL,
        KEY idx_cust (customer_id, status), KEY idx_status (status, last_msg_at), KEY idx_guest (guest_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS tbl_mobile_chat_msg (
        id INT AUTO_INCREMENT PRIMARY KEY, chat_id INT NOT NULL, sender VARCHAR(10) NOT NULL, sender_id INT NOT NULL DEFAULT 0,
        sender_name VARCHAR(100) NULL, body VARCHAR(1000) NOT NULL, source VARCHAR(10) NOT NULL DEFAULT 'app', created_at DATETIME NOT NULL,
        KEY idx_chat (chat_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $db->exec('ALTER TABLE tbl_mobile_settings MODIFY v TEXT NOT NULL');
    } catch (Throwable $e) {
    }
    $_SESSION['lc_ready'] = 1;
}

function lc_setting($k, $d = '')
{
    $r = lc_rows('SELECT v FROM tbl_mobile_settings WHERE k = ?', [$k]);
    return $r ? $r[0]['v'] : $d;
}

function lc_setting_set($k, $v)
{
    lc_db()->prepare('INSERT INTO tbl_mobile_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, (string) $v]);
}

function lc_cfg()
{
    return [
        'on' => (int) lc_setting('chat_on', 1) === 1,
        'web' => (int) lc_setting('chat_web', 1) === 1,
        'guests' => (int) lc_setting('chat_guests', 1) === 1,
        'welcome' => (string) (lc_setting('chat_welcome', '') ?: 'Hi! How can we help you today? A member of our team will reply shortly.'),
        'hours' => (string) lc_setting('chat_hours', ''),
        'offline' => (string) (lc_setting('chat_offline', '') ?: 'We are away at the moment. Leave your message and we will reply as soon as we are back.'),
        'canned' => (string) lc_setting('chat_canned', "Thanks for contacting us, one moment please.\nCould you share your username or phone number?\nIs there anything else I can help you with?"),
        'close_hours' => max(1, (int) lc_setting('chat_close_hours', 24)),
    ];
}

function lc_json($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function lc_fail($msg, $code = 400)
{
    lc_json(['ok' => false, 'error' => $msg], $code);
}

function lc_clean($v, $max = 1000)
{
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v));
    return mb_substr($v, 0, $max);
}

function lc_in($k, $d = '')
{
    return isset($_POST[$k]) ? $_POST[$k] : (isset($_GET[$k]) ? $_GET[$k] : $d);
}

function lc_csrf()
{
    if (empty($_SESSION['lc_csrf'])) $_SESSION['lc_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['lc_csrf'];
}

function lc_check_csrf()
{
    $t = (string) lc_in('csrf');
    if ($t === '' || empty($_SESSION['lc_csrf']) || !hash_equals($_SESSION['lc_csrf'], $t)) lc_fail('Session expired. Reload the page.', 403);
}

function lc_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** Who is asking: staff, a logged-in customer, or an anonymous visitor (identified by a cookie). */
function lc_who()
{
    $aid = Admin::getID();
    if ($aid) {
        $a = ORM::for_table('tbl_users')->find_one($aid);
        if ($a && in_array($a['user_type'], ['Admin', 'SuperAdmin', 'Agent', 'Sales'], true)) {
            return ['role' => 'staff', 'id' => (int) $a['id'], 'name' => $a['fullname'] ?: $a['username']];
        }
    }
    $uid = User::getID();
    if ($uid) {
        $c = ORM::for_table('tbl_customers')->find_one($uid);
        if ($c && $c['status'] !== 'Banned') return ['role' => 'customer', 'id' => (int) $c['id'], 'name' => $c['fullname'] ?: $c['username']];
    }
    $tok = isset($_COOKIE['lc_guest']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['lc_guest']) ? $_COOKIE['lc_guest'] : '';
    return ['role' => 'guest', 'id' => 0, 'name' => 'Visitor', 'token' => $tok];
}

function lc_guest_token()
{
    if (!empty($_COOKIE['lc_guest']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['lc_guest'])) return $_COOKIE['lc_guest'];
    $t = bin2hex(random_bytes(16));
    setcookie('lc_guest', $t, ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['lc_guest'] = $t;
    return $t;
}

function lc_add($chatId, $sender, $senderId, $name, $body)
{
    lc_db()->prepare("INSERT INTO tbl_mobile_chat_msg (chat_id, sender, sender_id, sender_name, body, source, created_at) VALUES (?,?,?,?,?,'web',?)")
        ->execute([(int) $chatId, $sender, (int) $senderId, mb_substr((string) $name, 0, 100), $body, date('Y-m-d H:i:s')]);
}

function lc_msgs($chatId, $after)
{
    $out = [];
    foreach (lc_rows('SELECT id, sender, sender_name, body, created_at FROM tbl_mobile_chat_msg WHERE chat_id = ? AND id > ? ORDER BY id LIMIT 200', [(int) $chatId, (int) $after]) as $m) {
        $out[] = ['id' => (int) $m['id'], 'sender' => $m['sender'], 'name' => (string) $m['sender_name'], 'body' => $m['body'], 'at' => $m['created_at']];
    }
    return $out;
}

function lc_mine($who)
{
    $since = date('Y-m-d H:i:s', time() - 86400);
    if ($who['role'] === 'customer') {
        $r = lc_rows("SELECT * FROM tbl_mobile_chat WHERE customer_id = ? AND (status = 'open' OR (rating IS NULL AND closed_at > ?)) ORDER BY id DESC LIMIT 1", [$who['id'], $since]);
    } elseif (!empty($who['token'])) {
        $r = lc_rows("SELECT * FROM tbl_mobile_chat WHERE customer_id = 0 AND guest_token = ? AND (status = 'open' OR (rating IS NULL AND closed_at > ?)) ORDER BY id DESC LIMIT 1", [$who['token'], $since]);
    } else {
        $r = [];
    }
    return $r ? $r[0] : null;
}

/** Tell the Eazyradius CRM app about it too, when that plugin is installed. */
function lc_notify_staff($chat, $title, $body)
{
    try {
        if ((int) $chat['assigned_to'] > 0 && function_exists('mobile_api_notify')) {
            mobile_api_notify((int) $chat['assigned_to'], 'chat', $title, $body, 'chat', (int) $chat['id']);
        } elseif (function_exists('mobile_api_notify_managers')) {
            mobile_api_notify_managers('chat', false, 'chat', $title, $body, 'chat', (int) $chat['id'], 0);
        }
    } catch (Throwable $e) {
    }
}

function lc_sweep()
{
    $h = lc_cfg()['close_hours'];
    foreach (lc_rows("SELECT id FROM tbl_mobile_chat WHERE status = 'open' AND last_msg_at < ? LIMIT 50", [date('Y-m-d H:i:s', time() - $h * 3600)]) as $c) {
        lc_db()->prepare("UPDATE tbl_mobile_chat SET status = 'closed', closed_at = ? WHERE id = ? AND status = 'open'")->execute([date('Y-m-d H:i:s'), (int) $c['id']]);
        lc_add($c['id'], 'system', 0, '', 'Chat closed automatically after ' . $h . ' hours without activity.');
    }
}

/* ---------------------------------------------------------------- admin page */

function livechat()
{
    global $ui;
    _admin();
    $admin = Admin::_info();
    if (!in_array($admin['user_type'], ['Admin', 'SuperAdmin', 'Agent', 'Sales'], true)) {
        r2(getUrl('dashboard'), 'e', 'You do not have access to live chat');
    }
    lc_ready();
    $ui->assign('_title', 'Live chat');
    $ui->assign('_system_menu', 'plugin/livechat');
    $ui->assign('_admin', $admin);
    $ui->assign('lc_api', getUrl('plugin/livechat_api'));
    $ui->assign('lc_csrf', lc_csrf());
    $ui->assign('lc_can_edit', in_array($admin['user_type'], ['Admin', 'SuperAdmin'], true));
    $ui->display('livechat.tpl');
}

/* ---------------------------------------------------------------- JSON endpoint */

function livechat_api()
{
    lc_ready();
    $who = lc_who();
    $act = (string) lc_in('act');
    $cfg = lc_cfg();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') lc_check_csrf();

    if ($act === 'whoami') {
        lc_json(['ok' => true, 'role' => $who['role'], 'name' => $who['name'], 'csrf' => lc_csrf(), 'enabled' => $cfg['on'] && $cfg['web'] && ($who['role'] !== 'guest' || $cfg['guests']),
            'welcome' => $cfg['welcome'], 'hours' => $cfg['hours']]);
    }
    if ($who['role'] === 'staff') {
        livechat_staff($who, $act, $cfg);
    }
    if (!$cfg['on'] || !$cfg['web']) lc_fail('Live chat is switched off right now', 403);
    if ($who['role'] === 'guest' && !$cfg['guests']) lc_fail('Please log in to chat with us', 403);
    livechat_visitor($who, $act, $cfg);
}

function livechat_visitor($who, $act, $cfg)
{
    $chat = lc_mine($who);
    $after = (int) lc_in('after', 0);
    if ($act === 'fetch') {
        $out = ['ok' => true, 'chat' => null, 'messages' => [], 'unread' => 0];
        if ($chat) {
            $out['chat'] = ['id' => (int) $chat['id'], 'status' => $chat['status'], 'agent' => (string) $chat['assigned_name'], 'rated' => $chat['rating'] !== null];
            $out['messages'] = lc_msgs($chat['id'], $after);
            $out['unread'] = (int) $chat['client_unread'];
            if ((int) $chat['client_unread'] > 0) lc_db()->prepare('UPDATE tbl_mobile_chat SET client_unread = 0 WHERE id = ?')->execute([(int) $chat['id']]);
        }
        lc_json($out);
    }
    if ($act === 'send') {
        $body = lc_clean(lc_in('body'), $who['role'] === 'guest' ? 600 : 1000);
        if ($body === '') lc_fail('Type a message first', 422);
        $name = $who['name'];
        $now = date('Y-m-d H:i:s');
        $ip = lc_ip();
        if ($chat && $chat['status'] !== 'open') $chat = null;
        if ($who['role'] === 'guest') {
            $gname = lc_clean(lc_in('name'), 80);
            $gcontact = lc_clean(lc_in('contact'), 80);
            if (!$chat && mb_strlen($gname) < 2) lc_fail('Please tell us your name first', 422);
            $tok = lc_guest_token();
            $who['token'] = $tok;
            $recent = lc_rows("SELECT COUNT(*) n FROM tbl_mobile_chat_msg m JOIN tbl_mobile_chat c ON c.id = m.chat_id WHERE c.guest_token = ? AND m.sender = 'client' AND m.created_at > ?", [$tok, date('Y-m-d H:i:s', time() - 60)]);
            if ((int) $recent[0]['n'] >= 8) lc_fail('You are sending messages too fast. Please wait a moment.', 429);
            if (!$chat) {
                $ipn = lc_rows("SELECT COUNT(*) n FROM tbl_mobile_chat WHERE customer_id = 0 AND source = 'web' AND guest_contact LIKE ? AND created_at > ?", ['%|' . $ip, date('Y-m-d H:i:s', time() - 3600)]);
                if ((int) $ipn[0]['n'] >= 5) lc_fail('Too many new chats from your network. Please try again later.', 429);
            }
            $name = $chat ? ($chat['guest_name'] ?: 'Visitor') : $gname;
        } else {
            $recent = lc_rows("SELECT COUNT(*) n FROM tbl_mobile_chat_msg m JOIN tbl_mobile_chat c ON c.id = m.chat_id WHERE c.customer_id = ? AND m.sender = 'client' AND m.created_at > ?", [$who['id'], date('Y-m-d H:i:s', time() - 60)]);
            if ((int) $recent[0]['n'] >= 15) lc_fail('You are sending messages too fast. Please wait a moment.', 429);
        }
        $isNew = false;
        if (!$chat) {
            lc_db()->prepare("INSERT INTO tbl_mobile_chat (customer_id, guest_name, guest_contact, guest_token, source, status, last_msg_at, created_at) VALUES (?,?,?,?, 'web', 'open', ?, ?)")
                ->execute([$who['role'] === 'customer' ? $who['id'] : 0, $who['role'] === 'guest' ? $name : null,
                    $who['role'] === 'guest' ? mb_substr($gcontact . '|' . $ip, 0, 80) : null, $who['role'] === 'guest' ? $who['token'] : null, $now, $now]);
            $chat = ['id' => (int) lc_db()->lastInsertId(), 'assigned_to' => 0, 'staff_unread' => 0];
            $isNew = true;
        }
        $before = (int) $chat['staff_unread'];
        lc_add($chat['id'], 'client', $who['id'], $name, $body);
        if ($isNew && $cfg['hours'] !== '') {
            $recentStaff = lc_rows("SELECT COUNT(*) n FROM tbl_mobile_chat_msg WHERE sender = 'staff' AND created_at > ?", [date('Y-m-d H:i:s', time() - 1800)]);
            if ((int) $recentStaff[0]['n'] === 0) lc_add($chat['id'], 'system', 0, 'Customer care', $cfg['offline']);
        }
        lc_db()->prepare("UPDATE tbl_mobile_chat SET last_msg_at = ?, last_sender = 'client', last_preview = ?, staff_unread = staff_unread + 1, status = 'open' WHERE id = ?")
            ->execute([$now, mb_substr($body, 0, 160), (int) $chat['id']]);
        if ($before === 0) lc_notify_staff($chat, ($isNew ? 'New chat: ' : 'Chat: ') . $name, $body);
        lc_json(['ok' => true, 'chat_id' => (int) $chat['id'], 'messages' => lc_msgs($chat['id'], $after)]);
    }
    if ($act === 'close') {
        if (!$chat) lc_fail('No chat to close', 404);
        $rating = (int) lc_in('rating', 0);
        $rating = $rating >= 1 && $rating <= 5 ? $rating : null;
        if ($chat['status'] === 'open') {
            lc_db()->prepare("UPDATE tbl_mobile_chat SET status = 'closed', closed_at = ?, rating = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $rating, (int) $chat['id']]);
            lc_add($chat['id'], 'system', 0, '', 'The customer ended the chat.');
        } elseif ($rating !== null && $chat['rating'] === null) {
            lc_db()->prepare('UPDATE tbl_mobile_chat SET rating = ? WHERE id = ?')->execute([$rating, (int) $chat['id']]);
        }
        lc_json(['ok' => true]);
    }
    lc_fail('Unknown action', 404);
}

function lc_title($c)
{
    if ((int) $c['customer_id'] > 0) return $c['fullname'] ?: ($c['username'] ?: 'Customer');
    return trim((string) $c['guest_name']) !== '' ? $c['guest_name'] . ' (visitor)' : 'Website visitor';
}

function lc_chat($id)
{
    $r = lc_rows('SELECT c.*, cu.fullname, cu.username, cu.phonenumber FROM tbl_mobile_chat c LEFT JOIN tbl_customers cu ON cu.id = c.customer_id AND c.customer_id > 0 WHERE c.id = ?', [(int) $id]);
    if (!$r) lc_fail('Chat not found', 404);
    return $r[0];
}

function livechat_staff($who, $act, $cfg)
{
    $me = $who['id'];
    $now = date('Y-m-d H:i:s');
    if ($act === 'list') {
        lc_sweep();
        $f = (string) lc_in('filter', 'open');
        $where = "c.status = 'open'";
        $args = [];
        if ($f === 'mine') { $where .= ' AND c.assigned_to = ?'; $args[] = $me; }
        elseif ($f === 'unassigned') $where .= ' AND c.assigned_to = 0';
        elseif ($f === 'closed') $where = "c.status = 'closed'";
        $out = [];
        foreach (lc_rows("SELECT c.*, cu.fullname, cu.username FROM tbl_mobile_chat c LEFT JOIN tbl_customers cu ON cu.id = c.customer_id AND c.customer_id > 0 WHERE $where ORDER BY c.staff_unread > 0 DESC, c.last_msg_at DESC LIMIT 80", $args) as $c) {
            $out[] = ['id' => (int) $c['id'], 'name' => lc_title($c), 'source' => $c['source'], 'agent' => (string) $c['assigned_name'], 'assigned_to' => (int) $c['assigned_to'], 'mine' => (int) $c['assigned_to'] === $me,
                'unread' => (int) $c['staff_unread'], 'preview' => (string) $c['last_preview'], 'at' => $c['last_msg_at'], 'rating' => $c['rating'] === null ? null : (int) $c['rating']];
        }
        $cnt = lc_rows("SELECT SUM(status='open') o, SUM(status='open' AND assigned_to=0) u, SUM(status='open' AND assigned_to = ?) m FROM tbl_mobile_chat", [$me]);
        lc_json(['ok' => true, 'chats' => $out, 'counts' => ['open' => (int) $cnt[0]['o'], 'unassigned' => (int) $cnt[0]['u'], 'mine' => (int) $cnt[0]['m']], 'settings' => $cfg]);
    }
    if ($act === 'thread') {
        $c = lc_chat(lc_in('id'));
        if ((int) $c['staff_unread'] > 0) lc_db()->prepare('UPDATE tbl_mobile_chat SET staff_unread = 0 WHERE id = ?')->execute([(int) $c['id']]);
        lc_json(['ok' => true, 'chat' => ['id' => (int) $c['id'], 'name' => lc_title($c), 'username' => (string) $c['username'],
            'phone' => (int) $c['customer_id'] > 0 ? (string) $c['phonenumber'] : (string) preg_replace('/\|.*$/', '', (string) $c['guest_contact']),
            'status' => $c['status'], 'mine' => (int) $c['assigned_to'] === $me, 'agent' => (string) $c['assigned_name'], 'rating' => $c['rating'] === null ? null : (int) $c['rating']],
            'messages' => lc_msgs($c['id'], (int) lc_in('after', 0))]);
    }
    if ($act === 'send') {
        $c = lc_chat(lc_in('id'));
        if ($c['status'] !== 'open') lc_fail('This chat is closed', 409);
        $body = lc_clean(lc_in('body'));
        if ($body === '') lc_fail('Type a message first', 422);
        lc_add($c['id'], 'staff', $me, $who['name'], $body);
        $claim = (int) $c['assigned_to'] === 0;
        lc_db()->prepare("UPDATE tbl_mobile_chat SET last_msg_at = ?, last_sender = 'staff', last_preview = ?, client_unread = client_unread + 1, staff_unread = 0" . ($claim ? ', assigned_to = ?, assigned_name = ?' : '') . ' WHERE id = ?')
            ->execute(array_merge([$now, mb_substr($body, 0, 160)], $claim ? [$me, $who['name']] : [], [(int) $c['id']]));
        if ((int) $c['customer_id'] > 0 && (int) $c['client_unread'] === 0 && function_exists('mobile_api_notify')) {
            try { mobile_api_notify(-(int) $c['customer_id'], 'chat', 'Customer care replied', $body, 'chat', (int) $c['id']); } catch (Throwable $e) {}
        }
        lc_json(['ok' => true, 'messages' => lc_msgs($c['id'], (int) lc_in('after', 0))]);
    }
    if ($act === 'assign') {
        $c = lc_chat(lc_in('id'));
        if (lc_in('to', 'me') === 'none') lc_db()->prepare('UPDATE tbl_mobile_chat SET assigned_to = 0, assigned_name = NULL WHERE id = ?')->execute([(int) $c['id']]);
        else lc_db()->prepare('UPDATE tbl_mobile_chat SET assigned_to = ?, assigned_name = ? WHERE id = ?')->execute([$me, $who['name'], (int) $c['id']]);
        lc_json(['ok' => true]);
    }
    if ($act === 'close') {
        $c = lc_chat(lc_in('id'));
        if ($c['status'] === 'open') {
            lc_db()->prepare("UPDATE tbl_mobile_chat SET status = 'closed', closed_at = ?, staff_unread = 0 WHERE id = ?")->execute([$now, (int) $c['id']]);
            lc_add($c['id'], 'system', 0, '', 'Chat closed by ' . $who['name'] . '.');
            if ((int) $c['customer_id'] > 0 && function_exists('mobile_api_notify')) {
                try { mobile_api_notify(-(int) $c['customer_id'], 'chat', 'Chat closed', 'Please rate your conversation.', 'chat', (int) $c['id']); } catch (Throwable $e) {}
            }
        }
        lc_json(['ok' => true]);
    }
    if ($act === 'settings_save') {
        $a = ORM::for_table('tbl_users')->find_one($me);
        if (!$a || !in_array($a['user_type'], ['Admin', 'SuperAdmin'], true)) lc_fail('Only an administrator can change chat settings', 403);
        lc_setting_set('chat_on', lc_in('on') ? 1 : 0);
        lc_setting_set('chat_web', lc_in('web') ? 1 : 0);
        lc_setting_set('chat_guests', lc_in('guests') ? 1 : 0);
        lc_setting_set('chat_welcome', lc_clean(lc_in('welcome'), 300));
        lc_setting_set('chat_hours', lc_clean(lc_in('hours'), 100));
        lc_setting_set('chat_offline', lc_clean(lc_in('offline'), 300));
        lc_setting_set('chat_canned', lc_clean(lc_in('canned'), 1500));
        lc_setting_set('chat_close_hours', max(1, min(240, (int) lc_in('close_hours', 24))));
        lc_json(['ok' => true, 'settings' => lc_cfg()]);
    }
    lc_fail('Unknown action', 404);
}

/* ---------------------------------------------------------------- the pop-up on the website */

function livechat_widget()
{
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    $api = json_encode(getUrl('plugin/livechat_api'));
    echo "(function(){var API=" . $api . ";\n";
    echo <<<'JS'
if(window.__lcLoaded)return;window.__lcLoaded=true;
var csrf='',role='',open=false,chatId=0,lastId=0,status='',rated=false,unread=0,timer=null,dismissed=0,hours='',welcome='',agent='';
function q(p){var s=[];for(var k in p)s.push(encodeURIComponent(k)+'='+encodeURIComponent(p[k]));return s.join('&');}
function call(act,p,post){p=p||{};p.act=act;var url=API+(API.indexOf('?')>-1?'&':'?')+(post?'act='+act:q(p));
 var o={credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}};
 if(post){p.csrf=csrf;o.method='POST';o.headers['Content-Type']='application/x-www-form-urlencoded';o.body=q(p);}
 return fetch(url,o).then(function(r){return r.json();});}
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!==undefined)e.textContent=x;return e;}
var css='#lc-b{position:fixed;right:18px;bottom:18px;z-index:99998;background:#2563eb;color:#fff;border:0;border-radius:28px;padding:13px 20px;font:600 15px system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer}'+
'#lc-n{position:absolute;top:-6px;right:-4px;background:#dc2626;color:#fff;border-radius:10px;min-width:20px;height:20px;font:700 12px/20px system-ui;text-align:center;display:none}'+
'#lc-w{position:fixed;right:18px;bottom:18px;z-index:99999;width:360px;max-width:calc(100vw - 24px);height:min(540px,calc(100vh - 36px));background:#fff;color:#111;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;font:14px system-ui,sans-serif}'+
'#lc-h{background:#2563eb;color:#fff;padding:10px 12px;display:flex;align-items:center;gap:8px}#lc-h div{flex:1}#lc-h b{display:block;font-size:15px}#lc-h small{opacity:.9}#lc-h button{background:transparent;border:0;color:#fff;cursor:pointer;font:600 13px system-ui}'+
'#lc-m{flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:6px;background:#f5f7fb}.lc-r{max-width:80%;padding:7px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}.lc-c{align-self:flex-end;background:#dbeafe}.lc-s{align-self:flex-start;background:#fff;border:1px solid #e5e7eb}.lc-y{align-self:center;font-size:12px;color:#6b7280;text-align:center}.lc-r small{display:block;font-size:11px;color:#6b7280}'+
'#lc-f{display:flex;gap:6px;padding:8px;border-top:1px solid #e5e7eb;background:#fff}#lc-f input,#lc-f textarea{flex:1;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:14px system-ui;resize:none}#lc-f button,.lc-btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:8px 14px;cursor:pointer;font:600 14px system-ui}'+
'#lc-g{padding:8px;border-top:1px solid #e5e7eb;background:#fff;display:none;flex-direction:column;gap:6px}#lc-g input{border:1px solid #d1d5db;border-radius:8px;padding:8px;font:14px system-ui}#lc-e{padding:10px;text-align:center;background:#fff;border-top:1px solid #e5e7eb;display:none}#lc-e button{margin:2px}';
var st=el('style');st.textContent=css;document.head.appendChild(st);
var b=el('button');b.id='lc-b';b.textContent='Chat with us';var nb=el('span');nb.id='lc-n';b.appendChild(nb);
var w=el('div');w.id='lc-w';
var h=el('div');h.id='lc-h';var ht=el('div');var t1=el('b',0,'Customer care');var t2=el('small',0,'');ht.appendChild(t1);ht.appendChild(t2);
var endB=el('button',0,'End');var hideB=el('button',0,'Hide');h.appendChild(ht);h.appendChild(endB);h.appendChild(hideB);
var m=el('div');m.id='lc-m';
var gb=el('div');gb.id='lc-g';var gn=el('input');gn.placeholder='Your name';gn.maxLength=80;var gc=el('input');gc.placeholder='Phone or email (optional)';gc.maxLength=60;gb.appendChild(gn);gb.appendChild(gc);
var f=el('div');f.id='lc-f';var ta=el('textarea');ta.rows=1;ta.placeholder='Type a message';ta.maxLength=1000;var sb=el('button',0,'Send');f.appendChild(ta);f.appendChild(sb);
var ed=el('div');ed.id='lc-e';
w.appendChild(h);w.appendChild(m);w.appendChild(gb);w.appendChild(f);w.appendChild(ed);
function mount(){document.body.appendChild(b);document.body.appendChild(w);}
function hhmm(a){return a&&a.length>=16?a.substr(11,5):'';}
function addMsg(x){var d;if(x.sender==='system'){d=el('div','lc-y',x.body);}else{d=el('div','lc-r '+(x.sender==='client'?'lc-c':'lc-s'));if(x.sender!=='client'&&x.name)d.appendChild(el('small',0,x.name));d.appendChild(document.createTextNode(x.body));d.appendChild(el('small',0,hhmm(x.at)));}
 m.appendChild(d);if(x.id>lastId)lastId=x.id;}
function render(all){m.innerHTML='';if(!all.length){m.appendChild(el('div','lc-y',welcome));}all.forEach(addMsg);m.scrollTop=m.scrollHeight;}
var shown=[];
function apply(j){
 var ch=j.chat;if(ch&&ch.id===dismissed)ch=null;
 if(!ch){if(chatId){chatId=0;lastId=0;shown=[];render([]);}status='';agent='';ui();return;}
 if(ch.id!==chatId){chatId=ch.id;lastId=0;shown=[];}
 status=ch.status;agent=ch.agent||'';rated=ch.rated;
 var fresh=(j.messages||[]).filter(function(x){return !shown.some(function(s){return s.id===x.id;});});
 if(fresh.length){shown=shown.concat(fresh).sort(function(a,b){return a.id-b.id;});lastId=shown[shown.length-1].id;render(shown);}
 else if(!shown.length)render([]);
 if(!open)unread+=j.unread||0;
 ui();}
function ui(){
 t2.textContent=agent?('You are talking to '+agent):(hours?('Hours: '+hours):'We usually reply within minutes');
 endB.style.display=status==='open'?'':'none';
 var closed=status==='closed';f.style.display=closed?'none':'flex';
 gb.style.display=(role==='guest'&&!chatId&&open)?'flex':'none';
 ed.style.display=closed?'block':'none';
 if(closed){ed.innerHTML='';if(!rated){ed.appendChild(el('div',0,'How was our service?'));for(var i=1;i<=5;i++){(function(n){var bt=el('button','lc-btn',String(n));bt.onclick=function(){call('close',{rating:n},true).then(function(){rated=true;ui();});};ed.appendChild(bt);})(i);}}else ed.appendChild(el('div',0,'Thank you for your feedback.'));
  var nb2=el('button','lc-btn','Start a new chat');nb2.onclick=function(){dismissed=chatId;chatId=0;lastId=0;shown=[];status='';render([]);ui();};ed.appendChild(el('br'));ed.appendChild(nb2);}
 nb.style.display=unread>0&&!open?'block':'none';nb.textContent=unread;}
function poll(){call('fetch',{after:chatId?lastId:0}).then(function(j){if(j.ok)apply(j);}).catch(function(){});}
function loop(){clearInterval(timer);timer=setInterval(function(){if(!document.hidden)poll();},open?3000:30000);}
function setOpen(o){open=o;b.style.display=o?'none':'block';w.style.display=o?'flex':'none';if(o){unread=0;poll();setTimeout(function(){ta.focus();},50);}ui();loop();}
function send(){var v=ta.value.trim();if(!v)return;var p={body:v};if(role==='guest'&&!chatId){p.name=gn.value.trim();p.contact=gc.value.trim();if(p.name.length<2){alert('Please enter your name');gn.focus();return;}}
 sb.disabled=true;call('send',p,true).then(function(j){sb.disabled=false;if(!j.ok){alert(j.error||'Could not send');return;}ta.value='';dismissed=0;chatId=j.chat_id;apply({chat:{id:j.chat_id,status:'open',agent:agent,rated:false},messages:j.messages,unread:0});}).catch(function(){sb.disabled=false;alert('No connection');});}
sb.onclick=send;ta.onkeydown=function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}};
b.onclick=function(){setOpen(true);};hideB.onclick=function(){setOpen(false);};endB.onclick=function(){if(confirm('End this chat?'))call('close',{},true).then(poll);};
call('whoami').then(function(j){if(!j.ok||j.role==='staff'||!j.enabled)return;csrf=j.csrf;role=j.role;hours=j.hours;welcome=j.welcome;
 if(document.body)mount();else document.addEventListener('DOMContentLoaded',mount);render([]);poll();loop();}).catch(function(){});
})();
JS;
    exit;
}

/* Put the script on every public and customer page. Admin pages, the API and files are left alone. */
function livechat_inject($html)
{
    try {
        if (stripos($html, '</body>') === false || stripos($html, '<html') === false) return $html;
        foreach (headers_list() as $hd) {
            if (stripos($hd, 'content-disposition') === 0) return $html;
            if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'html') === false) return $html;
        }
        if (stripos($html, 'id="lc-b"') !== false) return $html;
        $route = isset($_GET['_route']) ? (string) $_GET['_route'] : ltrim((string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH), '/');
        if (preg_match('#(^|/)(admin|plugin/livechat|callback|webhook|api|printpdf)#i', $route)) return $html;
        if (!lc_widget_on()) return $html;
        $tag = '<script src="' . htmlspecialchars(getUrl('plugin/livechat_widget'), ENT_QUOTES) . '" defer></script>';
        $pos = strripos($html, '</body>');
        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    } catch (Throwable $e) {
        return $html;
    }
}

function lc_widget_on()
{
    try {
        if (!empty($_SESSION['aid'])) return false; // staff do not need the pop-up
        $r = lc_rows("SELECT v FROM tbl_mobile_settings WHERE k = 'chat_web'");
        $on = lc_rows("SELECT v FROM tbl_mobile_settings WHERE k = 'chat_on'");
        return (!$r || (int) $r[0]['v'] === 1) && (!$on || (int) $on[0]['v'] === 1);
    } catch (Throwable $e) {
        return false; // tables not created yet: nothing to show until staff open the Live chat page once
    }
}

if (PHP_SAPI !== 'cli') {
    $lc_r = isset($_GET['_route']) ? (string) $_GET['_route'] : '';
    if (strpos($lc_r, 'plugin/livechat') !== 0 && strpos($lc_r, 'admin') !== 0) {
        ob_start('livechat_inject');
    }
}
