import { get, post, download, setCsrfToken, clearPrivateCache } from './core/api.js';
import { startRouter, setAuthState, currentRoute, navigate } from './core/router.js';
import { shell, skeleton } from './components/ui.js';

const root = document.querySelector('#app');
const dialog = document.querySelector('#confirm-dialog');
let profile = null;
let lastRender = null;
let reviewDraft = null;
let requestKey = null;
let paymentKeys = new Map();
let renderVersion = 0;

async function renderRoute(route, page, context) {
  const version = ++renderVersion;
  lastRender = { route, page, context };
  root.setAttribute('aria-busy', 'true');
  if (['home', 'results', 'transcripts'].includes(route) && profile) {
    root.innerHTML = skeleton(3);
    try { const html = await page.render({ ...context, profile }); if (version !== renderVersion) return; root.innerHTML = html; }
    catch (error) { if (error.error?.code === 'navigation_cancelled' || version !== renderVersion) return; root.innerHTML = shell({ route, profile, content: `<section class="card card-pad"><h1 class="section-title">Page could not load</h1><p>${escapeText(error.message)}</p><button class="btn" data-action="retry-page">Retry</button></section>` }); }
  } else root.innerHTML = page.render(context);
  if (version !== renderVersion) return;
  root.setAttribute('aria-busy', 'false');
  document.querySelector('.sidebar')?.classList.remove('is-open');
  document.querySelector('.mobile-menu')?.setAttribute('aria-expanded', 'false');
  document.querySelector('.mobile-menu')?.setAttribute('aria-label', 'Open navigation');
  if (route === 'transcripts' && context.params.has('new')) installTranscriptFieldBehavior();
  if (route !== 'login') document.querySelector('#main-content')?.focus({ preventScroll: true });
  if (location.search.includes('payment=return')) handleGatewayReturn();
}

function escapeText(text) { return String(text ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
function showToast(message, action = '') {
  const region = document.querySelector('#toast-region');
  const node = document.createElement('div'); node.className = 'toast'; node.setAttribute('role', 'status');
  node.innerHTML = `${escapeText(message)}${action}`; region.append(node);
  setTimeout(() => node.remove(), 5000);
}
function setFormError(form, message, tone = 'error') {
  const area = form.querySelector('[data-role="form-error"]');
  if (area) area.innerHTML = `<div class="inline-alert tone-${tone}" role="alert">${escapeText(message)}</div>`;
}
function setBusy(button, busy, text) {
  if (!button) return;
  button.disabled = busy;
  if (busy) { button.dataset.originalText = button.innerHTML; button.textContent = text || 'Please wait…'; }
  else if (button.dataset.originalText) { button.innerHTML = button.dataset.originalText; delete button.dataset.originalText; }
}
const key = () => crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

function restoreForm(data) {
  const area = document.querySelector('#transcript-form-area');
  for (const [name, value] of Object.entries(data)) {
    area.querySelectorAll(`[name="${name}"]`).forEach(el => {
      if (el.type === 'radio') el.checked = el.value === value; else el.value = value;
    });
  }
  document.querySelector('#institution-fields').hidden = data.destinationType !== 'institution';
}

async function init() {
  const route = location.hash.replace(/^#\/?/, '').split('?')[0];
  try { const preauth = await get('auth/csrf.php', { cache: false }); setCsrfToken(preauth.csrfToken); } catch (_) { /* Login can explain the network failure when submitted. */ }
  if (!['forgot-password', 'reset-password'].includes(route)) {
    try { const me = await get('auth/me.php', { cache: false }); profile = me.student; setCsrfToken(me.csrfToken); setAuthState(true); }
    catch (error) {
      setAuthState(false);
      if (error.status === 401 && !['login', 'forgot-password', 'reset-password'].includes(route)) {
        // A first visit (no route yet) is not an expired session: go to login without the warning.
        if (route && route !== 'home') sessionStorage.setItem('resumeRoute', location.hash.replace(/^#\/?/, ''));
        location.hash = '#/login';
      }
    }
  }
  startRouter(renderRoute);
}

function installTranscriptFieldBehavior() {
  const syncDelivery = type => {
    const delivery = document.querySelector('#deliveryMethod');
    if (!delivery) return;
    [...delivery.options].forEach(option => { option.hidden = type === 'institution' ? option.hasAttribute('data-personal-only') : option.hasAttribute('data-institution-only'); });
    if (type === 'institution' && delivery.value === 'download') delivery.value = 'email';
    if (type === 'personal' && delivery.value !== 'download') delivery.value = 'download';
  };
  document.querySelectorAll('[name="destinationType"]').forEach(input => input.addEventListener('change', () => {
    const institution = input.value === 'institution' && input.checked;
    const fields = document.querySelector('#institution-fields'); if (fields) fields.hidden = !institution;
    syncDelivery(institution ? 'institution' : 'personal');
    const status = document.querySelector('#quote-status'); if (status) status.textContent = 'Your fee will be calculated from the updated options when you review.';
  }));
  const selected = document.querySelector('[name="destinationType"]:checked');
  if (selected) syncDelivery(selected.value);
}

document.addEventListener('submit', async event => {
  const form = event.target.closest('form[data-form]'); if (!form) return;
  event.preventDefault();
  const type = form.dataset.form;
  const button = form.querySelector('[data-role="submit"]');
  if (type === 'login') {
    const data = Object.fromEntries(new FormData(form));
    if (!data.matricNo.trim() || !data.password) {
      for (const name of ['matricNo', 'password']) if (!data[name]?.trim()) {
        const field = form.elements.namedItem(name); field.setAttribute('aria-invalid', 'true');
        const error = form.querySelector(`[data-error-for="${name}"]`); if (error) error.textContent = name === 'matricNo' ? 'Enter your matric number.' : 'Enter your password.';
      }
      form.querySelector('[aria-invalid="true"]')?.focus();
      setFormError(form, 'Complete the highlighted fields to sign in.'); return;
    }
    setBusy(button, true, 'Signing in…');
    try {
      const result = await post('auth/login.php', data);
      profile = result.student; setCsrfToken(result.csrfToken); setAuthState(true); clearPrivateCache();
      const resume = sessionStorage.getItem('resumeRoute') || 'home'; sessionStorage.removeItem('resumeRoute'); sessionStorage.removeItem('sessionMessage');
      navigate(resume, true);
    } catch (error) {
      const message = error.status === 429 ? `${error.message}${error.error?.fields?.retryAfterSeconds ? ` Try again in ${error.error.fields.retryAfterSeconds} seconds.` : ''}` : error.status === 401 ? 'Matric number or password is incorrect.' : error.message;
      setFormError(form, message);
      setBusy(button, false);
      if (error.status === 429) {
        const submit = form.querySelector('[type=submit]');
        let seconds = Math.max(1, Number(error.error?.fields?.retryAfterSeconds) || 30);
        const original = submit?.innerHTML;
        if (submit) { submit.disabled = true; submit.textContent = `Try again in ${seconds}s`; }
        const timer = setInterval(() => {
          seconds -= 1;
          if (!submit?.isConnected || seconds <= 0) { clearInterval(timer); if (submit?.isConnected) { submit.disabled = false; submit.innerHTML = original; } }
          else submit.textContent = `Try again in ${seconds}s`;
        }, 1000);
      }
    }
  }
  if (type === 'forgot') {
    setBusy(button, true, 'Sending…');
    try { const result = await post('auth/password-reset-request.php', Object.fromEntries(new FormData(form))); setFormError(form, result.message, 'success'); }
    catch (error) { setFormError(form, error.message); }
    finally { setBusy(button, false); }
  }
  if (type === 'reset') {
    const data = Object.fromEntries(new FormData(form));
    if (data.newPassword !== data.confirmPassword) { setFormError(form, 'The passwords do not match.'); return; }
    setBusy(button, true, 'Updating…');
    try { await post('auth/password-reset.php', { token: data.token, newPassword: data.newPassword }); setFormError(form, 'Password updated. You can sign in now.', 'success'); }
    catch (error) { setFormError(form, error.message); }
    finally { setBusy(button, false); }
  }
});

document.addEventListener('change', event => {
  if (event.target.matches('[data-picker="session"]')) {
    const session = event.target.value;
    const semester = document.querySelector('[data-picker="semester"]');
    // Rebuild semester options from the current available pairs via the existing module data.
    get('results/options.php').then(({ pairs }) => {
      const available = pairs.filter(p => p.session === session);
      semester.innerHTML = available.map(p => `<option>${escapeText(p.semester)}</option>`).join('');
    }).catch(error => showToast(error.message));
  }
});
document.addEventListener('input', event => {
  const field = event.target.closest('input[aria-invalid="true"]');
  if (!field || !field.value.trim()) return;
  field.removeAttribute('aria-invalid');
  const message = document.querySelector(`[data-error-for="${field.name}"]`); if (message) message.textContent = '';
});

document.addEventListener('click', async event => {
  const target = event.target.closest('[data-action]'); if (!target) return;
  const action = target.dataset.action;
  if (action === 'toggle-password') {
    const input = document.querySelector('#password'); const visible = input.type === 'password'; input.type = visible ? 'text' : 'password';
    target.setAttribute('aria-pressed', String(visible)); target.setAttribute('aria-label', visible ? 'Hide password' : 'Show password'); target.innerHTML = `<svg class="icon" width="20" height="20" aria-hidden="true"><use href="#i-${visible ? 'eye-off' : 'eye'}"></use></svg>`;
  }
  if (action === 'menu') {
    const sidebar = document.querySelector('.sidebar'); const expanded = sidebar?.classList.toggle('is-open') || false;
    target.setAttribute('aria-expanded', String(expanded)); target.setAttribute('aria-label', expanded ? 'Close navigation' : 'Open navigation');
  }
  if (action === 'retry-page') { if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context); }
  if (action === 'refresh-list') { clearPrivateCache(); if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context); }
  if (action === 'load-results') {
    const session = document.querySelector('[data-picker="session"]').value;
    const semester = document.querySelector('[data-picker="semester"]').value;
    localStorage.setItem('lastResultPair', JSON.stringify({ session, semester }));
    location.hash = `#/results?session=${encodeURIComponent(session)}&semester=${encodeURIComponent(semester)}`;
    if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context);
  }
  if (action === 'review-request') {
  const button = target;
  const area = document.querySelector('#transcript-form-area');
  const formHtml = area.innerHTML; // snapshot before the button goes busy
  setBusy(button, true, 'Getting current fee…');
  try {
    const module = await import('./pages/transcripts.js');
    // FormData requires a form element; collect controls from the request step into a temporary form.
    const temp = document.createElement('form');
    area.querySelectorAll('input,select,textarea').forEach(control => {
      if (!control.name || (control.type === 'radio' && !control.checked)) return;
      const field = document.createElement('input');
      field.name = control.name;
      field.value = control.value;
      temp.append(field);
    });
    const draft = await module.reviewRequest(temp, lastRender?.context?.signal);
    if (!draft) { setBusy(button, false); return; }
    reviewDraft = { ...draft, formHtml };
    area.innerHTML = module.reviewMarkup(draft);
    document.querySelectorAll('.step-pill').forEach((pill, index) => pill.classList.toggle('is-current', index === 1));
  } catch (error) {
    const status = document.querySelector('#quote-status');
    if (status) status.textContent = error.message;
  }
  finally { if (target.isConnected) setBusy(target, false); }
}

    if (action === 'edit-request' && reviewDraft) {
    const { formHtml, data } = reviewDraft;
    document.querySelector('#transcript-form-area').innerHTML = formHtml;
    restoreForm(data);
    reviewDraft = null; requestKey = null;
    installTranscriptFieldBehavior();
    // step-pill toggle stays as is
  }
  if (action === 'submit-request' && reviewDraft) {
    setBusy(target, true, 'Creating request…'); requestKey ||= key();
    try {
      const { data, quote } = reviewDraft;
      const clean = { ...data };
      if (clean.destinationType === 'personal') {
        ['institutionName', 'destinationAddress', 'destinationEmail'].forEach(k => delete clean[k]);
      }
      // Send only what the contract asks for: no empty strings, copies as a number.
      const body = Object.fromEntries(
        Object.entries({ ...clean, copies: Number(clean.copies), quoteExpiresAt: quote.expiresAt })
          .filter(([, value]) => value !== '')
      );
      await post('transcripts/create.php', body, { idempotencyKey: requestKey });
      requestKey = null; reviewDraft = null;
      showToast('Transcript request created.'); navigate('transcripts');
    } catch (error) { setBusy(target, false); showToast(error.message); }
  }

  if (action === 'pay' || action === 'retry-payment') {
    const id = target.dataset.id; target.disabled = true;
    const approved = await confirmAction('Confirm payment', 'You are about to start payment for this transcript. We will confirm the payment with the portal before marking it paid.', 'Continue to payment');
    if (!approved) { target.disabled = false; return; }
    target.disabled = true; target.textContent = 'Starting payment…';
    try {
      const idempotencyKey = paymentKeys.get(id) || key(); paymentKeys.set(id, idempotencyKey);
      const result = await post(`transcripts/pay.php?id=${encodeURIComponent(id)}`, {}, { idempotencyKey });
      if (result.mode === 'gateway' && result.paymentUrl) { sessionStorage.setItem('pendingPaymentId', id); window.location.assign(result.paymentUrl); return; }
      const verified = await post(`payments/verify.php?id=${encodeURIComponent(id)}`, { reference: result.reference });
      paymentKeys.delete(id);
      if (verified.paymentStatus === 'success') showToast('Payment confirmed. Receipt is available in this request.');
      else showToast(verified.paymentStatus === 'failed' ? 'Payment failed. You can try again.' : 'Payment is still processing. Check back shortly.');
      clearPrivateCache(); if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context);
    } catch (error) { target.disabled = false; showToast(error.message); }
  }
  if (action === 'cancel') {
    const id = target.dataset.id;
    if (!await confirmAction('Cancel this request?', 'This cancels the unpaid transcript request. You can create a new one later.', 'Cancel request')) return;
    target.disabled = true;
    try { await post(`transcripts/cancel.php?id=${encodeURIComponent(id)}`, {}); showToast('Transcript request cancelled.'); clearPrivateCache(); if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context); }
    catch (error) { target.disabled = false; showToast(error.message); }
  }
  if (action === 'download') {
    target.disabled = true;
    try { const blob = await download(`transcripts/download.php?id=${encodeURIComponent(target.dataset.id)}`); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = `${target.dataset.id}.pdf`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); showToast('Transcript download started.'); }
    catch (error) { showToast(error.message); }
    finally { target.disabled = false; }
  }
  if (action === 'receipt') {
    try {
      const receipt = await get(`payments/receipt.php?id=${encodeURIComponent(target.dataset.id)}`, { cache: false });
      dialog.removeAttribute('aria-describedby'); dialog.onclose = null;
      dialog.innerHTML = `<div class="dialog-content receipt-content"><p class="eyebrow">Payment receipt</p><h2 id="dialog-title">${escapeText(receipt.receiptNumber)}</h2><div class="review-list"><div class="review-row"><span>Amount paid</span><strong>${new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(receipt.amount)}</strong></div><div class="review-row"><span>Paid on</span><strong>${new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Lagos' }).format(new Date(receipt.paidAt))}</strong></div><div class="review-row"><span>Payment reference</span><strong>${escapeText(receipt.reference)}</strong></div></div><div class="dialog-actions no-print"><button class="btn btn-secondary" data-action="confirm-dialog" data-value="no">Close</button><button class="btn" data-action="print-receipt">Print receipt</button></div></div>`;
      dialog.showModal();
    }
    catch (error) { showToast(error.message); }
  }
  if (action === 'print-receipt') window.print();
  if (action === 'logout') {
    target.disabled = true;
    try { await post('auth/logout.php', {}); } catch (_) { /* Clear the local view even when the connection is lost. */ }
    profile = null; setCsrfToken(''); setAuthState(false); clearPrivateCache(); sessionStorage.removeItem('portalMockAuth'); sessionStorage.removeItem('resumeRoute');
    try { const preauth = await get('auth/csrf.php', { cache: false }); setCsrfToken(preauth.csrfToken); } catch (_) { /* Login will show the connection error. */ }
    navigate('login', true); showToast('You are signed out.');
  }
  if (action === 'confirm-dialog') { dialog.close(target.dataset.value === 'yes' ? 'yes' : 'no'); }
});

function confirmAction(title, message, confirmText) {
  return new Promise(resolve => {
    dialog.setAttribute('aria-describedby', 'dialog-description');
    dialog.innerHTML = `<div class="dialog-content"><h2 id="dialog-title">${escapeText(title)}</h2><p id="dialog-description">${escapeText(message)}</p><div class="dialog-actions"><button class="btn btn-secondary" data-action="confirm-dialog" data-value="no">Go back</button><button class="btn" data-action="confirm-dialog" data-value="yes">${escapeText(confirmText)}</button></div></div>`;
    dialog.returnValue = 'no'; dialog.onclose = () => resolve(dialog.returnValue === 'yes'); dialog.showModal();
  });
}

async function handleGatewayReturn() {
  const params = new URLSearchParams(location.search); const id = params.get('id') || sessionStorage.getItem('pendingPaymentId');
  if (!id || params.get('payment') !== 'return') return;
  history.replaceState(null, '', `${location.pathname}${location.hash}`);
  try { const result = await post(`payments/verify.php?id=${encodeURIComponent(id)}`, { reference: params.get('reference') || '' }); showToast(result.paymentStatus === 'success' ? 'Payment confirmed. Receipt is available in this request.' : 'Payment is still processing.'); sessionStorage.removeItem('pendingPaymentId'); clearPrivateCache(); if (lastRender) await renderRoute(lastRender.route, lastRender.page, lastRender.context); }
  catch (error) { showToast(error.message); }
}

window.addEventListener('api:refresh', () => {
  if (lastRender && currentRoute() === lastRender.route && !(lastRender.route === 'transcripts' && lastRender.context.params.has('new'))) renderRoute(lastRender.route, lastRender.page, lastRender.context);
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const sidebar = document.querySelector('.sidebar');
  if (sidebar?.classList.contains('is-open')) {
    sidebar.classList.remove('is-open'); const menu = document.querySelector('.mobile-menu');
    menu?.setAttribute('aria-expanded', 'false'); menu?.setAttribute('aria-label', 'Open navigation'); menu?.focus();
  }
});
window.addEventListener('portal:session-expired', () => {
  profile = null; clearPrivateCache(); setCsrfToken('');
});
init();
