// Owner: Dev C. Fetch wrapper (CSRF header, envelope unwrap, 401 to login) and hash view switcher.
// admin.js - Vanilla JS SPA Router
const app = document.getElementById('app');
let csrfToken = '';
let currentAdmin = null;

// --- API HELPER ---
async function apiCall(endpoint, method = 'GET', body = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (method === 'POST') headers['X-CSRF-Token'] = csrfToken;

    const res = await fetch(`api/${endpoint}`, {
        method,
        headers,
        credentials: 'include',
        body: body ? JSON.stringify(body) : null
    });
    
    const json = await res.json();
    if (!res.ok) {
        throw new Error(json.error?.message || 'API Error');
    }
    return json.data; 
}

// --- INITIALIZATION ---
async function init() {
    try {
        const data = await apiCall('admin/auth/csrf');
        csrfToken = data.csrfToken;
    } catch (e) { console.error("CSRF error", e); }

    try {
        const data = await apiCall('admin/auth/me');
        currentAdmin = data.admin;
        csrfToken = data.csrfToken;
    } catch (e) { /* Not logged in */ }

    window.addEventListener('hashchange', router);
    router();
}

// --- ROUTER ---
function router() {
    const hash = window.location.hash || '#/login';
    
    // Auth Guard
    if (!currentAdmin && hash !== '#/login') {
        window.location.hash = '#/login';
        return;
    }

    if (hash === '#/login') renderLogin();
    else if (hash === '#/dashboard') renderDashboard();
    else if (hash === '#/requests') renderRequests();
    else if (hash.startsWith('#/request/')) renderRequestDetail(hash.split('/')[2]);
    else if (hash === '#/payments') renderPayments();
    else app.innerHTML = '<h2>404 Not Found</h2>';
}

// --- VIEW 1: LOGIN ---
function renderLogin() {
    app.innerHTML = `
        <div class="container" style="max-width: 400px; margin-top: 100px;">
            <h2 style="text-align: center; color: var(--primary);">Registry Admin</h2>
            <form id="loginForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="password" required>
                </div>
                <div id="error" class="error-msg"></div>
                <button type="submit" style="width: 100%;">Sign In</button>
            </form>
        </div>
    `;

    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button');
        btn.textContent = 'Loading...';
        document.getElementById('error').textContent = '';

        try {
            const data = await apiCall('admin/auth/login', 'POST', {
                username: document.getElementById('username').value,
                password: document.getElementById('password').value
            });
            currentAdmin = data.admin;
            csrfToken = data.csrfToken;
            window.location.hash = '#/dashboard';
        } catch (err) {
            document.getElementById('error').textContent = err.message;
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
                    <a href="#" onclick="logout(event)" style="color: #dc3545;">Logout</a>
                </nav>
            </header>
            ${content}
        </div>
    `;
}

// --- VIEW 2: DASHBOARD ---
async function renderDashboard() {
    app.innerHTML = renderLayout('<p>Loading dashboard...</p>');
    try {
        const data = await apiCall('admin/dashboard');
        
        let recentRows = data.recent.map(r => `
            <tr>
                <td><strong>${r.requestNo}</strong></td>
                <td>${r.student.fullName}</td>
                <td><span class="badge ${r.status}">${r.status}</span></td>
                <td>${new Date(r.createdAt).toLocaleDateString()}</td>
            </tr>
        `).join('');

        const content = `
            <div class="metrics-grid">
                <div class="card"><h3>Total Requests</h3><p>${data.requests.total}</p></div>
                <div class="card action-needed"><h3>Action Needed</h3><p>${data.requests.awaitingAction}</p></div>
                <div class="card"><h3>Verified Payments</h3><p>${data.payments.verifiedAmount}</p></div>
                <div class="card"><h3>Ready / Sent</h3><p>${data.requests.byStatus.ready + data.requests.byStatus.sent}</p></div>
            </div>
            <h2>Recent Requests</h2>
            <table>
                <thead><tr><th>Req No</th><th>Student</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>${recentRows}</tbody>
            </table>
        `;
        app.innerHTML = renderLayout(content);
    } catch (err) {
        app.innerHTML = renderLayout(`<p class="error-msg">${err.message}</p>`);
    }
}

// --- VIEW 3: REQUESTS LIST ---
async function renderRequests() {
    app.innerHTML = renderLayout('<p>Loading requests...</p>');
    try {
        const data = await apiCall('admin/transcripts/index');
        
        let rows = data.items.map(r => `
            <tr>
                <td><strong>${r.requestNo}</strong></td>
                <td>${r.student.fullName} (${r.student.matricNo})</td>
                <td>${r.destination.label}</td>
                <td>${r.feeAmount}</td>
                <td><span class="badge ${r.status}">${r.status}</span></td>
                <td class="text-right">
                    <a href="#/request/${r.id}"><button>Manage</button></a>
                </td>
            </tr>
        `).join('');

        app.innerHTML = renderLayout(`
            <h2>Transcript Requests</h2>
            <table>
                <thead><tr><th>Req No</th><th>Student</th><th>Destination</th><th>Fee</th><th>Status</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `);
    } catch (err) {
        app.innerHTML = renderLayout(`<p class="error-msg">${err.message}</p>`);
    }
}

// --- VIEW 4: REQUEST DETAIL ---
async function renderRequestDetail(encodedId) {
    app.innerHTML = renderLayout('<p>Loading details...</p>');
    try {
        const data = await apiCall(`admin/transcripts/show?id=${encodedId}`);
        const t = data.transcript;
        
        let actionBtns = '';
        if (t.status === 'paid') {
            actionBtns += `<button onclick="updateStatus('${encodedId}', 'start_processing')">Start Processing</button>`;
            actionBtns += `<button class="secondary" onclick="rejectRequest('${encodedId}')" style="background: #dc3545;">Reject</button>`;
        } else if (t.status === 'processing') {
            if (t.destination.type === 'personal') {
                actionBtns += `<button onclick="updateStatus('${encodedId}', 'mark_ready')">Mark Ready</button>`;
            } else {
                actionBtns += `<button onclick="updateStatus('${encodedId}', 'mark_sent')">Mark Sent</button>`;
            }
            actionBtns += `<button class="secondary" onclick="rejectRequest('${encodedId}')" style="background: #dc3545;">Reject</button>`;
        } else if (t.status === 'ready' || t.status === 'sent') {
            actionBtns += `<button onclick="downloadPdf('${encodedId}')">Download PDF</button>`;
        }

        const content = `
            <h2>Request: ${t.requestNo}</h2>
            <div class="card" style="text-align: left; margin-bottom: 20px;">
                <p><strong>Student:</strong> ${data.student.fullName} (${data.student.matricNo})</p>
                <p><strong>Status:</strong> <span class="badge ${t.status}">${t.status}</span></p>
                <p><strong>Destination:</strong> ${t.destination.label}</p>
                ${t.institution ? `<p><strong>Institution:</strong> ${t.institution.name} (${t.institution.address})</p>` : ''}
                <p><strong>Purpose:</strong> ${t.purpose}</p>
                <p><strong>Fee:</strong> ${t.feeAmount}</p>
            </div>
            <div>${actionBtns}</div>
            <div style="margin-top: 20px;"><a href="#/requests">← Back to Requests</a></div>
        `;
        app.innerHTML = renderLayout(content);
    } catch (err) {
        app.innerHTML = renderLayout(`<p class="error-msg">${err.message}</p>`);
    }
}

// --- VIEW 5: PAYMENTS ---
async function renderPayments() {
    app.innerHTML = renderLayout('<p>Loading payments...</p>');
    try {
        const data = await apiCall('admin/payments/index');
        
        let rows = data.items.map(p => `
            <tr>
                <td>${p.reference}</td>
                <td>${p.student.fullName}</td>
                <td>${p.amount}</td>
                <td>${p.gateway}</td>
                <td><span class="badge ${p.status === 'success' ? 'paid' : 'rejected'}">${p.status}</span></td>
                <td>${new Date(p.initiatedAt).toLocaleDateString()}</td>
            </tr>
        `).join('');

        app.innerHTML = renderLayout(`
            <h2>Payments</h2>
            <table>
                <thead><tr><th>Reference</th><th>Student</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `);
    } catch (err) {
        app.innerHTML = renderLayout(`<p class="error-msg">${err.message}</p>`);
    }
}

// --- GLOBAL ACTIONS ---
async function updateStatus(encodedId, action) {
    try {
        // ID goes in the URL now!
        await apiCall(`admin/transcripts/update-status?id=${encodedId}`, 'POST', {
            action: action
        });
        router(); 
    } catch (err) {
        alert(err.message);
    }
}

async function rejectRequest(encodedId) {
    const reason = prompt("Enter rejection reason (min 3 chars):");
    if (!reason) return;
    try {
        // ID goes in the URL now!
        await apiCall(`admin/transcripts/update-status?id=${encodedId}`, 'POST', {
            action: 'reject',
            reason: reason
        });
        router();
    } catch (err) {
        alert(err.message);
    }
}

function downloadPdf(encodedId) {
    window.open(`api/admin/transcripts/document?id=${encodedId}`, '_blank');
}

async function logout(e) {
    e.preventDefault();
    await apiCall('admin/auth/logout', 'POST');
    currentAdmin = null;
    window.location.hash = '#/login';
}

// Start the app
init();
