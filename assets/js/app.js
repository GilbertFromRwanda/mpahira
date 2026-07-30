function showToast(type, message) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position:fixed;top:70px;right:16px;z-index:2000;min-width:260px;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(container);
    }
    const div = document.createElement('div');
    div.className = 'alert alert-' + type + ' shadow-sm mb-0';
    div.textContent = message;
    container.appendChild(div);
    setTimeout(() => div.remove(), 4000);
}

function postAjax(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: body
    }).then(r => r.json());
}

function getAjax(url) {
    return fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json());
}

function setFormLoading(form, loadingText) {
    const btn = form.querySelector('button[type="submit"]');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = loadingText;
    return () => { btn.disabled = false; btn.textContent = original; };
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
}

function updateCartBadge(total) {
    const badge = document.getElementById('cartBadge');
    if (!badge) return;
    if (total > 0) {
        badge.textContent = Number(total).toLocaleString();
        badge.classList.remove('d-none');
    } else {
        badge.classList.add('d-none');
    }
}
