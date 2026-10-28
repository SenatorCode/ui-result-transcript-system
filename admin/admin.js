// Owner: Dev C. Fetch wrapper (CSRF header, envelope unwrap, 401 to login) and hash view switcher.
const app = document.getElementById('app');
let csrfToken = '';
let currentAdmin = null;

// --- HELPERS ---
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const naira = n => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(n || 0);
const fmtDate = v => v ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeZone: 'Africa/Lagos' }).format(new Date(v)) : '—';
const badge = status => `<span class="badge ${esc(status)}">${esc(String(status).replace(/_/g, ' '))}</span>`;

// --- API HELPER ---
async function apiCall(endpoint, method = 'GET', body = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (method === 'POST') headers['X-CSRF-Token'] = csrfToken;

    // The router matches ".php" URLs; keep any query string after it.
    const [path, query] = endpoint.split('?');
    const url = `../api/${path}.php${query ? '?' + query : ''}`;

    const res = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : null
    });

    const text = await res.text();
    let json;
    try { json = JSON.parse(text); }
    catch { throw Object.assign(new Error(`Server returned non-JSON (HTTP ${res.status}). Check the API URL.`), { status: res.status }); }
    if (!res.ok) throw Object.assign(new Error(json.error?.message || 'API Error'), { status: res.status, fields: json.error?.fields || {} });
    return json.data;
}

// 401 sends the admin back to login; anything else is shown inside the layout.
function showError(err) {
    if (err.status === 401) {
        currentAdmin = null;
        window.location.hash = '#/login';
        return;
    }
    app.innerHTML = renderLayout(`<p class="error-msg">${esc(err.message)}</p>`);
}

// --- INITIALIZATION ---
async function init() {
    try {
        csrfToken = (await apiCall('admin/auth/csrf')).csrfToken;
    } catch (e) { console.error('CSRF error', e); }

    try {
        const data = await apiCall('admin/auth/me');
        currentAdmin = data.admin;
        csrfToken = data.csrfToken;
    } catch (e) { /* Not logged in */ }

    window.addEventListener('hashchange', router);
    document.addEventListener('click', onActionClick);
    router();
}

// --- ROUTER ---
function router() {
    const hash = window.location.hash || '#/login';

    if (!currentAdmin && hash !== '#/login') { window.location.hash = '#/login'; return; }
    if (currentAdmin && hash === '#/login') { window.location.hash = '#/dashboard'; return; }

    if (hash === '#/login') renderLogin();
    else if (hash === '#/dashboard') renderDashboard();
    else if (hash === '#/requests') renderRequests();
    else if (hash.startsWith('#/request/')) renderRequestDetail(decodeURIComponent(hash.split('/')[2] || ''));
    else if (hash === '#/payments') renderPayments();
    else app.innerHTML = renderLayout('<h2>404 Not Found</h2>');
}

// --- VIEW 1: LOGIN ---
function renderLogin() {
    app.innerHTML = `
        <div class="container" style="max-width: 400px; margin-top: 100px;">
            <h2 style="text-align: center; color: var(--primary);">Registry Admin</h2>
            <form id="loginForm">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" autocomplete="current-password" required>
                </div>
                <div id="error" class="error-msg" role="alert"></div>
                <button type="submit" style="width: 100%;">Sign In</button>
            </form>
        </div>`;

    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Signing in…';
        document.getElementById('error').textContent = '';
        try {
            const data = await apiCall('admin/auth/login', 'POST', {
                username: document.getElementById('username').value.trim(),
                password: document.getElementById('password').value
            });
            currentAdmin = data.admin;
            csrfToken = data.csrfToken;
            window.location.hash = '#/dashboard';
        } catch (err) {
            document.getElementById('error').textContent = err.message;
            btn.disabled = false;
            btn.textContent = 'Sign In';
        }
    });
}

// --- LAYOUT WRAPPER ---
function renderLayout(content) {
    return `
        <div class="container">
            <header>
                <h1>UI Transcripts Admin</h1>
                <nav>
                    <a href="#/dashboard">Dashboard</a>
                    <a href="#/requests">Requests</a>
                    <a href="#/payments">Payments</a>
                    <a href="#" data-act="logout" style="color: #dc3545;">Logout</a>
                </nav>
            </header>
            ${content}
        </div>`;
}

// --- VIEW 2: DASHBOARD ---
async function renderDashboard() {
    app.innerHTML = renderLayout('<p>Loading dashboard…</p>');
    try {
        const data = await apiCall('admin/dashboard');
        const rows = data.recent.map(r => `
            <tr>
                <td><a href="#/request/${esc(r.id)}"><strong>${esc(r.requestNo)}</strong></a></td>
                <td>${esc(r.student.fullName)}</td>
                <td>${badge(r.status)}</td>
                <td>${fmtDate(r.createdAt)}</td>
            </tr>`).join('');

        app.innerHTML = renderLayout(`
            <div class="metrics-grid">
                <div class="card"><h3>Total Requests</h3><p>${data.requests.total}</p></div>
                <div class="card action-needed"><h3>Action Needed</h3><p>${data.requests.awaitingAction}</p></div>
                <div class="card"><h3>Verified Payments</h3><p>${naira(data.payments.verifiedAmount)}</p></div>
                <div class="card"><h3>Ready / Sent</h3><p>${data.requests.byStatus.ready + data.requests.byStatus.sent}</p></div>
            </div>
            <h2>Recent Requests</h2>
            <table>
                <thead><tr><th>Req No</th><th>Student</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="4">No requests yet.</td></tr>'}</tbody>
            </table>`);
    } catch (err) { showError(err); }
}

// --- VIEW 3: REQUESTS LIST ---
async function renderRequests() {
    app.innerHTML = renderLayout('<p>Loading requests…</p>');
    try {
        const data = await apiCall('admin/transcripts/index');
        const rows = data.items.map(r => `
            <tr>
                <td><strong>${esc(r.requestNo)}</strong></td>
                <td>${esc(r.student.fullName)} (${esc(r.student.matricNo)})</td>
                <td>${esc(r.destination.label)}</td>
                <td>${naira(r.feeAmount)}</td>
                <td>${badge(r.status)}</td>
                <td class="text-right"><a href="#/request/${esc(r.id)}"><button type="button">Manage</button></a></td>
            </tr>`).join('');

        app.innerHTML = renderLayout(`
            <h2>Transcript Requests</h2>
            <table>
                <thead><tr><th>Req No</th><th>Student</th><th>Destination</th><th>Fee</th><th>Status</th><th></th></tr></thead>
                <tbody>${rows || '<tr><td colspan="6">No requests found.</td></tr>'}</tbody>
            </table>
            <p class="mb-20">Showing ${data.items.length} of ${data.page.totalItems}.</p>`);
    } catch (err) { showError(err); }
}

// --- VIEW 4: REQUEST DETAIL ---
const ACTION_LABELS = {
    start_processing: 'Start Processing',
    mark_ready: 'Mark Ready',
    mark_sent: 'Mark Sent',
    reject: 'Reject'
};

async function renderRequestDetail(id) {
    app.innerHTML = renderLayout('<p>Loading details…</p>');
    try {
        const data = await apiCall(`admin/transcripts/show?id=${encodeURIComponent(id)}`);
        const t = data.transcript;

        // Buttons come only from the server's adminActions list.
        const buttons = t.adminActions.map(a =>
            `<button type="button" data-act="${esc(a)}" data-id="${esc(t.id)}"${a === 'reject' ? ' style="background:#dc3545;"' : ''}>${esc(ACTION_LABELS[a] || a)}</button>`
        ).join(' ');
        const pdf = ['processing', 'ready', 'sent'].includes(t.status)
            ? `<button type="button" class="secondary" data-act="pdf" data-id="${esc(t.id)}">Download PDF</button>` : '';

        const payments = data.payments.map(p => `
            <tr>
                <td>${esc(p.reference)}</td>
                <td>${naira(p.amount)}</td>
                <td>${esc(p.gateway)}</td>
                <td>${esc(p.status)}${p.refundedAt ? ' (refunded)' : ''}</td>
                <td>${fmtDate(p.initiatedAt)}</td>
            </tr>`).join('');

        app.innerHTML = renderLayout(`
            <h2>Request: ${esc(t.requestNo)}</h2>
            <div class="card" style="text-align: left; margin-bottom: 20px;">
                <p><strong>Student:</strong> ${esc(data.student.fullName)} (${esc(data.student.matricNo)})</p>
                <p><strong>Status:</strong> ${badge(t.status)}</p>
                <p><strong>Destination:</strong> ${esc(t.destination.label)} · ${esc(t.deliveryMethod)} · ${t.copies} cop${t.copies === 1 ? 'y' : 'ies'}</p>
                ${t.institution ? `<p><strong>Institution:</strong> ${esc(t.institution.name)}${t.institution.address ? ', ' + esc(t.institution.address) : ''}${t.institution.email ? ' · ' + esc(t.institution.email) : ''}</p>` : ''}
                <p><strong>Purpose:</strong> ${esc(t.purpose || '—')}</p>
                <p><strong>Fee:</strong> ${naira(t.feeAmount)}</p>
                ${t.rejectionReason ? `<p><strong>Rejection reason:</strong> ${esc(t.rejectionReason)}</p>` : ''}
                ${t.issued ? `<p><strong>Transcript no:</strong> ${esc(t.issued.transcriptNo)} · ${t.issued.totalUnits} units · CGPA ${Number(t.issued.cgpa).toFixed(2)}</p>` : ''}
            </div>
            <div id="actionBar">${buttons} ${pdf}</div>
            <h3>Payments</h3>
            <table>
                <thead><tr><th>Reference</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>${payments || '<tr><td colspan="5">No payment attempts.</td></tr>'}</tbody>
            </table>
            <div style="margin-top: 20px;"><a href="#/requests">&larr; Back to Requests</a></div>`);
    } catch (err) { showError(err); }
}

// --- VIEW 5: PAYMENTS ---
async function renderPayments() {
    app.innerHTML = renderLayout('<p>Loading payments…</p>');
    try {
        const data = await apiCall('admin/payments/index');
        const rows = data.items.map(p => `
            <tr>
                <td>${esc(p.reference)}</td>
                <td>${esc(p.student.fullName)}<br><small>${esc(p.requestNo)}</small></td>
                <td>${naira(p.amount)}</td>
                <td>${esc(p.gateway)}</td>
                <td><span class="badge ${p.status === 'success' ? 'paid' : 'rejected'}">${esc(p.status)}</span></td>
                <td>${fmtDate(p.initiatedAt)}</td>
            </tr>`).join('');

        app.innerHTML = renderLayout(`
            <h2>Payments</h2>
            <p>${data.summary.count} payments · verified total ${naira(data.summary.verifiedAmount)}</p>
            <table>
                <thead><tr><th>Reference</th><th>Student</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>${rows || '<tr><td colspan="6">No payments found.</td></tr>'}</tbody>
            </table>`);
    } catch (err) { showError(err); }
}

// --- ACTIONS (one delegated handler; buttons lock while a request runs) ---
async function onActionClick(e) {
    const el = e.target.closest('[data-act]');
    if (!el) return;
    e.preventDefault();
    const act = el.dataset.act;
    const id = el.dataset.id;

    if (act === 'logout') {
        try { await apiCall('admin/auth/logout', 'POST'); } catch (_) { /* leave anyway */ }
        currentAdmin = null;
        window.location.hash = '#/login';
        return;
    }
    if (act === 'pdf') {
        window.open(`../api/admin/transcripts/document.php?id=${encodeURIComponent(id)}`, '_blank');
        return;
    }

    let body = { action: act };
    if (act === 'reject') {
        const reason = (prompt('Enter a rejection reason (3 to 255 characters):') || '').trim();
        if (!reason) return;
        if (!confirm('Reject this request? A verified payment will be flagged for refund.')) return;
        body.reason = reason;
    }

    document.querySelectorAll('#actionBar button').forEach(b => { b.disabled = true; });
    try {
        const result = await apiCall(`admin/transcripts/update-status?id=${encodeURIComponent(id)}`, 'POST', body);
        if (result.refundRequired) alert('Request rejected. The payment has been flagged for refund.');
    } catch (err) {
        if (err.status === 401) { showError(err); return; }
        alert(err.fields?.reason || err.message);
    }
    router(); // reload the request so buttons match the new status
}

init();