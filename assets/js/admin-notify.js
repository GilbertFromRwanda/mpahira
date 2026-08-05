// Live order-related popups + navbar bell (badge + dropdown list), for both
// admins (new order placed, etc.) and customers (e.g. pricing revealed on
// their order). Long-polls admin/notifications_poll.php — each response
// either carries this user's waiting notifications or arrives empty after
// ~25s, and either way we immediately poll again. A notification stays in
// the database (and counted in the badge / shown in the bell's dropdown)
// until the user explicitly marks it read — dismissing it from the popup
// card, the dropdown list, or following either one's "View Order" link — at
// which point it's deleted server-side. Loaded on every page for any logged-in user.
(function () {
    var pollBase = window.__ADMIN_NOTIFY_BASE || '';
    var orderBase = window.__NOTIFY_ORDER_BASE || '';
    var siteBase = window.__SITE_BASE || '';
    var pollUrl = pollBase + 'notifications_poll.php';
    var ordersUrl = orderBase + 'orders';
    function orderUrl(n) { return n.order_id ? orderBase + 'order?id=' + n.order_id : ordersUrl; }

    var style = document.createElement('style');
    style.textContent = [
        '#anStack{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:320px;}',
        '.anCard{background:#1f2421;color:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 8px 24px rgba(0,0,0,.25);font-family:Inter,system-ui,-apple-system,sans-serif;font-size:13px;line-height:1.5;animation:anIn .2s ease-out;}',
        '@keyframes anIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}',
        '.anCard a{display:inline-block;margin-top:8px;color:#8fd19e;font-weight:700;text-decoration:none;font-size:12px;}',
        '.anCard a:hover{text-decoration:underline;}',
        '.anCard .anClose{float:right;cursor:pointer;color:#9ca3af;margin-left:10px;}',
        '.nav-notif-panel-hdr{display:flex;align-items:center;justify-content:space-between;gap:8px;}',
        '.anMuteBtn{border:none;background:none;cursor:pointer;font-size:14px;line-height:1;padding:2px;color:inherit;}',
    ].join('\n');
    document.head.appendChild(style);

    var stack = document.createElement('div');
    stack.id = 'anStack';
    document.body.appendChild(stack);

    var badgeCount = 0;
    function renderBadge() {
        var el = document.getElementById('navNotifBadge');
        if (!el) return;
        el.textContent = badgeCount > 99 ? '99+' : badgeCount;
        el.style.display = badgeCount > 0 ? '' : 'none';
    }
    function bumpBadge(delta) {
        badgeCount = Math.max(0, badgeCount + delta);
        renderBadge();
    }

    function markRead(id) {
        bumpBadge(-1);
        var fd = new FormData();
        fd.append('mark_read', '1');
        fd.append('id', id);
        fetch(pollUrl, { method: 'POST', body: fd });
    }

    function escH(s) { return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    // Bell chime for new orders. Browsers block audio until the page has had
    // a user interaction, so the first click/keydown "unlocks" the element by
    // playing (and immediately pausing/rewinding) it — after that, playNotifySound()
    // can fire freely from the long-poll response with no further gesture needed.
    var notifyAudio = new Audio(siteBase + 'assets/audio/bell-notification-audo.wav');
    notifyAudio.preload = 'auto';
    var audioUnlocked = false;
    function unlockAudio() {
        if (audioUnlocked) return;
        notifyAudio.play().then(function () {
            notifyAudio.pause();
            notifyAudio.currentTime = 0;
            audioUnlocked = true;
        }).catch(function () {});
    }
    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);

    var MUTE_KEY = 'mpahiraAdminNotifyMuted';
    var muted = localStorage.getItem(MUTE_KEY) === '1';

    function playNotifySound() {
        if (muted) return;
        notifyAudio.currentTime = 0;
        notifyAudio.play().catch(function () {});
    }

    var bell = document.getElementById('navNotifBell');
    var panel = document.getElementById('navNotifPanel');
    var panelList = document.getElementById('navNotifList');
    var panelHdr = panel && panel.querySelector('.nav-notif-panel-hdr');

    var muteBtn = null;
    if (panelHdr) {
        var hdrLabel = document.createElement('span');
        hdrLabel.textContent = panelHdr.textContent;
        muteBtn = document.createElement('button');
        muteBtn.type = 'button';
        muteBtn.className = 'anMuteBtn';
        muteBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            muted = !muted;
            localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
            renderMuteBtn();
        });
        panelHdr.innerHTML = '';
        panelHdr.appendChild(hdrLabel);
        panelHdr.appendChild(muteBtn);
    }

    function renderMuteBtn() {
        if (!muteBtn) return;
        muteBtn.textContent = muted ? '🔕' : '🔔';
        muteBtn.title = muted ? 'Unmute order notification sound' : 'Mute order notification sound';
    }
    renderMuteBtn();

    function renderList(rows) {
        if (!rows.length) { panelList.innerHTML = '<div class="nav-notif-empty">No notifications</div>'; return; }
        panelList.innerHTML = rows.map(function (n) {
            return '<div class="nav-notif-row" id="navNotifRow' + n.id + '">'
                + '<div>' + escH(n.message || ('New order #' + n.order_id)) + '</div>'
                + '<div class="nav-notif-row-actions">'
                + '<a href="' + orderUrl(n) + '" onclick="window.__anMarkRead(' + n.id + ')">View Order</a>'
                + '<span onclick="window.__anDismiss(' + n.id + ', this)">Dismiss</span>'
                + '</div></div>';
        }).join('');
    }

    window.__anMarkRead = markRead;
    window.__anDismiss = function (id) {
        markRead(id);
        var row = document.getElementById('navNotifRow' + id);
        if (row) row.remove();
        if (!panelList.querySelector('.nav-notif-row')) panelList.innerHTML = '<div class="nav-notif-empty">No notifications</div>';
    };

    if (bell && panel) {
        bell.addEventListener('click', function (e) {
            e.stopPropagation();
            var opening = !panel.classList.contains('open');
            panel.classList.toggle('open', opening);
            if (opening) {
                panelList.innerHTML = '<div class="nav-notif-empty">Loading…</div>';
                fetch(pollUrl + '?action=list')
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (rows) { renderList(rows || []); })
                    .catch(function () { panelList.innerHTML = '<div class="nav-notif-empty">Failed to load.</div>'; });
            }
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#navNotifWrap')) panel.classList.remove('open');
        });
    }

    function showNotification(n) {
        var card = document.createElement('div');
        card.className = 'anCard';
        var closeBtn = document.createElement('span');
        closeBtn.className = 'anClose';
        closeBtn.title = 'Mark as read';
        closeBtn.textContent = '×';
        closeBtn.onclick = function () { markRead(n.id); card.remove(); };
        card.appendChild(closeBtn);
        card.appendChild(document.createTextNode(n.message || ('New order #' + n.order_id)));
        var link = document.createElement('a');
        link.href = orderUrl(n);
        link.textContent = 'View Order →';
        link.onclick = function () { markRead(n.id); };
        card.appendChild(document.createElement('br'));
        card.appendChild(link);
        stack.appendChild(card);
    }

    function poll() {
        fetch(pollUrl)
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) {
                list = list || [];
                if (list.length) {
                    bumpBadge(list.length);
                    playNotifySound();
                }
                list.forEach(showNotification);
                poll();
            })
            .catch(function () { setTimeout(poll, 3000); });
    }

    // Seed the badge with whatever's already unread before the long-poll
    // starts picking up brand-new ones.
    fetch(pollUrl + '?action=count')
        .then(function (r) { return r.ok ? r.json() : { count: 0 }; })
        .then(function (res) { badgeCount = res.count || 0; renderBadge(); })
        .catch(function () {});

    poll();
})();
