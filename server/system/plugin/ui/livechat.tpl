{include file="sections/header.tpl"}

{literal}<style>
#lc-wrap{display:flex;gap:14px;flex-wrap:wrap}
#lc-list{flex:1 1 320px;max-width:420px}
#lc-thread{flex:2 1 420px;min-width:300px}
.lc-item{border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-bottom:8px;cursor:pointer;background:#fff}
.lc-item.active{border-color:#2563eb;background:#eff6ff}
.lc-item .n{font-weight:600}.lc-item .p{color:#374151;margin:2px 0}.lc-item .s{color:#6b7280;font-size:12px}
.lc-badge{background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:12px;float:right}
#lc-msgs{height:380px;overflow-y:auto;background:#f5f7fb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:6px}
.lc-r{max-width:78%;padding:7px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word}
.lc-staff{align-self:flex-end;background:#dbeafe}.lc-client{align-self:flex-start;background:#fff;border:1px solid #e5e7eb}
.lc-system{align-self:center;font-size:12px;color:#6b7280}.lc-r small{display:block;color:#6b7280;font-size:11px}
</style>{/literal}

<section class="content-header"><h1>Live chat</h1></section>
<section class="content">
<div class="row"><div class="col-md-12">
<div class="panel panel-primary"><div class="panel-body">
<div style="margin-bottom:10px">
  <div class="btn-group" id="lc-filters">
    <button class="btn btn-default active" data-f="open">Open <span id="c-open"></span></button>
    <button class="btn btn-default" data-f="mine">Mine <span id="c-mine"></span></button>
    <button class="btn btn-default" data-f="unassigned">New <span id="c-new"></span></button>
    <button class="btn btn-default" data-f="closed">Closed</button>
  </div>
  {if $lc_can_edit}<button class="btn btn-default pull-right" id="lc-settings-btn">Settings</button>{/if}
</div>
<div id="lc-wrap">
  <div id="lc-list"><div class="text-muted">Loading...</div></div>
  <div id="lc-thread" style="display:none">
    <div style="margin-bottom:8px"><b id="th-name"></b> <span class="text-muted" id="th-sub"></span>
      <span class="pull-right">
        <button class="btn btn-xs btn-default" id="th-take">Take it</button>
        <button class="btn btn-xs btn-default" id="th-release">Release</button>
        <button class="btn btn-xs btn-danger" id="th-close">Close chat</button>
      </span></div>
    <div id="lc-msgs"></div>
    <div id="th-box" style="margin-top:8px">
      <select id="th-canned" class="form-control" style="margin-bottom:6px"><option value="">Quick replies...</option></select>
      <div class="input-group"><textarea id="th-text" class="form-control" rows="2" maxlength="1000" placeholder="Type a reply (Enter to send)"></textarea>
        <span class="input-group-btn"><button class="btn btn-primary" id="th-send" style="height:54px">Send</button></span></div>
    </div>
  </div>
</div>
</div></div>
</div></div>
</section>

{if $lc_can_edit}
<div class="modal fade" id="lc-settings" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Live chat settings</h4></div>
<div class="modal-body">
  <label><input type="checkbox" id="s-on"> Chat switched on (app and website)</label><br>
  <label><input type="checkbox" id="s-web"> Show the pop-up on the website</label><br>
  <label><input type="checkbox" id="s-guests"> Allow visitors who are not logged in</label>
  <div class="form-group"><label>Welcome message</label><input id="s-welcome" class="form-control" maxlength="300"></div>
  <div class="form-group"><label>Support hours (shown to customers)</label><input id="s-hours" class="form-control" maxlength="100"></div>
  <div class="form-group"><label>Message when nobody is around</label><input id="s-offline" class="form-control" maxlength="300"></div>
  <div class="form-group"><label>Quick replies (one per line)</label><textarea id="s-canned" class="form-control" rows="4"></textarea></div>
  <div class="form-group"><label>Close idle chats after (hours)</label><input id="s-close" type="number" min="1" max="240" class="form-control"></div>
</div>
<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">Cancel</button><button class="btn btn-primary" id="s-save">Save</button></div>
</div></div></div>
{/if}

<div id="lc-cfg" data-api="{$lc_api|escape}" data-csrf="{$lc_csrf|escape}" style="display:none"></div>
{literal}<script>
(function () {
  var cfgEl = document.getElementById('lc-cfg');
  var API = cfgEl.getAttribute('data-api'), CSRF = cfgEl.getAttribute('data-csrf');
  var filter = 'open', cur = 0, lastId = 0, shown = [], cfg = null, busy = false;
  function q(p) { var s = []; for (var k in p) s.push(encodeURIComponent(k) + '=' + encodeURIComponent(p[k])); return s.join('&'); }
  function call(act, p, post) {
    p = p || {}; p.act = act; var sep = API.indexOf('?') > -1 ? '&' : '?';
    if (post) { p.csrf = CSRF; return fetch(API + sep + 'act=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: q(p) }).then(function (r) { return r.json(); }); }
    return fetch(API + sep + q(p), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x !== undefined) e.textContent = x; return e; }
  function $(id) { return document.getElementById(id); }

  function loadList() {
    call('list', { filter: filter }).then(function (j) {
      if (!j.ok) return;
      cfg = j.settings;
      $('c-open').textContent = j.counts.open; $('c-mine').textContent = j.counts.mine; $('c-new').textContent = j.counts.unassigned;
      var box = $('lc-list'); box.innerHTML = '';
      if (!j.chats.length) box.appendChild(el('div', 'text-muted', 'No conversations here.'));
      j.chats.forEach(function (c) {
        var d = el('div', 'lc-item' + (c.id === cur ? ' active' : ''));
        if (c.unread > 0) d.appendChild(el('span', 'lc-badge', c.unread));
        d.appendChild(el('div', 'n', c.name)); d.appendChild(el('div', 'p', c.preview || ''));
        d.appendChild(el('div', 's', (c.assigned_to ? (c.mine ? 'You' : c.agent) : 'Unassigned') + ' · ' + (c.source === 'web' ? 'website' : 'app') + ' · ' + c.at.substr(0, 16) + (c.rating ? ' · rated ' + c.rating + '/5' : '')));
        d.onclick = function () { openChat(c.id); };
        box.appendChild(d);
      });
      fillCanned();
    });
  }
  function fillCanned() {
    if (!cfg) return; var s = $('th-canned'); if (s.dataset.done === cfg.canned) return; s.dataset.done = cfg.canned;
    s.innerHTML = ''; s.appendChild(new Option('Quick replies...', ''));
    cfg.canned.split('\n').forEach(function (l) { l = l.trim(); if (l) s.appendChild(new Option(l, l)); });
  }
  function openChat(id) { cur = id; lastId = 0; shown = []; $('lc-msgs').innerHTML = ''; $('lc-thread').style.display = ''; pollThread(); loadList(); }
  function pollThread() {
    if (!cur) return;
    var id = cur;
    call('thread', { id: id, after: lastId }).then(function (j) {
      if (!j.ok || id !== cur) return;
      var c = j.chat;
      $('th-name').textContent = c.name; $('th-sub').textContent = [c.username, c.phone].filter(Boolean).join(' · ') + (c.status === 'closed' ? ' · closed' : '');
      var open = c.status === 'open';
      $('th-take').style.display = open && !c.mine ? '' : 'none'; $('th-release').style.display = open && c.mine ? '' : 'none';
      $('th-close').style.display = open ? '' : 'none'; $('th-box').style.display = open ? '' : 'none';
      var box = $('lc-msgs'), add = j.messages.filter(function (x) { return !shown.some(function (s) { return s.id === x.id; }); });
      add.forEach(function (x) {
        shown.push(x); if (x.id > lastId) lastId = x.id;
        var d = el('div', 'lc-r lc-' + x.sender);
        if (x.sender === 'system') d.textContent = x.body;
        else { if (x.sender === 'staff' || x.name) d.appendChild(el('small', 0, x.name)); d.appendChild(document.createTextNode(x.body)); d.appendChild(el('small', 0, x.at.substr(11, 5))); }
        box.appendChild(d);
      });
      if (add.length) box.scrollTop = box.scrollHeight;
    });
  }
  function act(a) { if (!cur) return; call(a, { id: cur, to: a === 'assign' ? 'me' : '' }, true).then(function () { pollThread(); loadList(); }); }
  function send() {
    var t = $('th-text').value.trim(); if (!t || busy) return; busy = true;
    call('send', { id: cur, body: t, after: lastId }, true).then(function (j) { busy = false; if (!j.ok) { alert(j.error); return; } $('th-text').value = ''; pollThread(); loadList(); }).catch(function () { busy = false; });
  }
  document.querySelectorAll('#lc-filters button').forEach(function (b) {
    b.onclick = function () { document.querySelectorAll('#lc-filters button').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active'); filter = b.dataset.f; loadList(); };
  });
  $('th-send').onclick = send;
  $('th-text').onkeydown = function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } };
  $('th-canned').onchange = function () { if (this.value) { $('th-text').value = this.value; this.value = ''; $('th-text').focus(); } };
  $('th-take').onclick = function () { act('assign'); };
  $('th-release').onclick = function () { call('assign', { id: cur, to: 'none' }, true).then(function () { pollThread(); loadList(); }); };
  $('th-close').onclick = function () { if (confirm('Close this chat?')) act('close'); };

  var sb = $('lc-settings-btn');
  if (sb) {
    sb.onclick = function () {
      if (!cfg) return;
      $('s-on').checked = !!cfg.on; $('s-web').checked = !!cfg.web; $('s-guests').checked = !!cfg.guests;
      $('s-welcome').value = cfg.welcome; $('s-hours').value = cfg.hours; $('s-offline').value = cfg.offline; $('s-canned').value = cfg.canned; $('s-close').value = cfg.close_hours;
      $('lc-settings').style.display = 'block'; $('lc-settings').className = 'modal fade in';
    };
    var hide = function () { $('lc-settings').style.display = 'none'; $('lc-settings').className = 'modal fade'; };
    document.querySelectorAll('#lc-settings [data-dismiss]').forEach(function (b) { b.onclick = hide; });
    $('s-save').onclick = function () {
      call('settings_save', { on: $('s-on').checked ? 1 : 0, web: $('s-web').checked ? 1 : 0, guests: $('s-guests').checked ? 1 : 0, welcome: $('s-welcome').value, hours: $('s-hours').value,
        offline: $('s-offline').value, canned: $('s-canned').value, close_hours: $('s-close').value }, true).then(function (j) { if (!j.ok) { alert(j.error); return; } hide(); loadList(); });
    };
  }
  loadList();
  setInterval(function () { if (!document.hidden) { loadList(); pollThread(); } }, 4000);
})();
</script>{/literal}

{include file="sections/footer.tpl"}
