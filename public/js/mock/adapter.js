const flag = new URLSearchParams(location.search).get('mock');
const delay = flag === 'slow' ? 1800 : 260;
let csrf = 'mock-csrf-student-2026';
let loggedIn = sessionStorage.getItem('portalMockAuth') === 'true';
if (flag === 'expired') { loggedIn = false; sessionStorage.removeItem('portalMockAuth'); }
let requestCounter = 1004;
const idempotencyLedger = new Map();
let transcripts = [
  { id: 'TR-2026-1007', destination: { type: 'institution', label: 'University of Lagos', institutionName: 'University of Lagos' }, deliveryMethod: 'courier', copies: 1, feeAmount: 22000, status: 'sent', createdAt: '2026-08-12T10:15:00Z', updatedAt: '2026-08-19T13:20:00Z', sentAt: '2026-08-19T13:20:00Z', rejectionReason: null, allowedActions: [], payment: { reference: 'PAY-2026-8821', status: 'success', paidAt: '2026-08-12T10:18:00Z' } },
  { id: 'TR-2026-1006', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'rejected', createdAt: '2026-08-27T10:15:00Z', updatedAt: '2026-08-29T13:20:00Z', rejectionReason: 'A result is still under review. Contact Registry when the result is released.', allowedActions: [], payment: { reference: 'PAY-2026-8892', status: 'success', paidAt: '2026-08-27T10:18:00Z' } },
  { id: 'TR-2026-1005', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'expired', createdAt: '2026-09-01T10:15:00Z', updatedAt: '2026-09-01T10:30:00Z', rejectionReason: null, allowedActions: [], payment: { reference: null, status: null, paidAt: null } },
  { id: 'TR-2026-1004', destination: { type: 'institution', label: 'Federal University of Technology, Akure', institutionName: 'Federal University of Technology, Akure' }, deliveryMethod: 'email', copies: 1, feeAmount: 15000, status: 'payment_processing', createdAt: '2026-09-10T10:15:00Z', updatedAt: '2026-09-10T10:20:00Z', rejectionReason: null, allowedActions: [], payment: { reference: 'PAY-2026-9002', status: 'initiated', paidAt: null } },
  { id: 'TR-2026-1009', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'failed', createdAt: '2026-09-13T10:15:00Z', updatedAt: '2026-09-13T10:20:00Z', rejectionReason: null, allowedActions: ['retry'], payment: { reference: 'PAY-2026-9012', status: 'failed', paidAt: null } },
  { id: 'TR-2026-1008', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'paid', createdAt: '2026-09-14T10:15:00Z', updatedAt: '2026-09-14T10:20:00Z', rejectionReason: null, allowedActions: ['receipt'], payment: { reference: 'PAY-2026-9021', status: 'success', paidAt: '2026-09-14T10:20:00Z' } },
  { id: 'TR-2026-1010', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'cancelled', createdAt: '2026-09-15T10:15:00Z', updatedAt: '2026-09-15T10:20:00Z', rejectionReason: null, allowedActions: [], payment: { reference: null, status: null, paidAt: null } },
  { id: 'TR-2026-1003', destination: { type: 'personal', label: 'Personal copy' }, deliveryMethod: 'download', copies: 1, feeAmount: 15000, status: 'ready', createdAt: '2026-09-20T10:15:00Z', updatedAt: '2026-09-27T13:20:00Z', rejectionReason: null, allowedActions: ['download', 'receipt'], payment: { reference: 'PAY-2026-9081', status: 'success', paidAt: '2026-09-20T10:18:00Z' } },
  { id: 'TR-2026-1002', destination: { type: 'institution', label: 'University of Lagos', institutionName: 'University of Lagos', destinationAddress: 'Registry, Akoka, Lagos' }, deliveryMethod: 'courier', copies: 1, feeAmount: 22000, status: 'processing', createdAt: '2026-10-02T09:00:00Z', updatedAt: '2026-10-03T11:00:00Z', rejectionReason: null, allowedActions: [], payment: { reference: 'PAY-2026-9113', status: 'success', paidAt: '2026-10-02T09:05:00Z' } },
  { id: 'TR-2026-1001', destination: { type: 'institution', label: 'Federal University of Technology, Minna', institutionName: 'Federal University of Technology, Minna', destinationEmail: 'registry@example.edu.ng' }, deliveryMethod: 'email', copies: 1, feeAmount: 15000, status: 'pending_payment', createdAt: '2026-10-08T08:30:00Z', updatedAt: '2026-10-08T08:30:00Z', rejectionReason: null, allowedActions: ['pay', 'cancel'], payment: { reference: null, status: null, paidAt: null } }
];
const profile = { id: 'st_01', fullName: 'Ada Okafor', matricNo: 'CSC/2023/001', department: 'Computer Science', level: 300 };
const pairs = [
  { session: '2025/2026', semester: 'First Semester', released: true },
  { session: '2025/2026', semester: 'Second Semester', released: false, availability: 'pending', message: 'Results have not been released yet.' },
  { session: '2024/2025', semester: 'Second Semester', released: true },
  { session: '2023/2024', semester: 'Second Semester', released: false, availability: 'withheld', message: 'Contact your faculty office for guidance about this result.' },
  { session: '2024/2025', semester: 'First Semester', released: true }
];
const courseRows = [
  { code: 'CSC 301', title: 'Data Structures', units: 3, unitsEarned: 3, grade: 'A', gradePoint: 5, attempt: 1, carryOver: false },
  { code: 'CSC 303', title: 'Operating Systems', units: 3, unitsEarned: 3, grade: 'B', gradePoint: 4, attempt: 1, carryOver: false },
  { code: 'CSC 305', title: 'Database Systems', units: 3, unitsEarned: 3, grade: 'B', gradePoint: 4, attempt: 1, carryOver: false },
  { code: 'MTH 301', title: 'Numerical Analysis', units: 2, unitsEarned: 2, grade: 'C', gradePoint: 3, attempt: 1, carryOver: false },
  { code: 'GST 301', title: 'Entrepreneurship', units: 2, unitsEarned: 2, grade: 'D', gradePoint: 2, attempt: 1, carryOver: false },
  { code: 'CSC 312', title: 'Software Engineering', units: 3, unitsEarned: 0, grade: 'F', gradePoint: 0, attempt: 1, carryOver: true }
];
function fail(code, message, status = 422, fields = {}) { throw Object.assign(new Error(message), { status, error: { code, message, fields } }); }
const copy = x => structuredClone(x);
const readPath = path => path.replace(/^\.\.\/api\//, '').split('?');
const headersObject = h => Object.fromEntries([...new Headers(h || {}).entries()].map(([k, v]) => [k.toLowerCase(), v]));
const response = data => ({ data });
function mockPdf() {
  const stream = 'BT /F1 16 Tf 72 720 Td (University of Ibadan - Mock Transcript) Tj 0 -28 Td (Preview only) Tj ET';
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`
  ];
  let pdf = '%PDF-1.4\n'; const offsets = [0];
  objects.forEach((object, index) => { offsets.push(pdf.length); pdf += `${index + 1} 0 obj\n${object}\nendobj\n`; });
  const xrefOffset = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  offsets.slice(1).forEach(offset => { pdf += `${String(offset).padStart(10, '0')} 00000 n \n`; });
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;
  return new Blob([pdf], { type: 'application/pdf' });
}

async function performRequest(path, options = {}) {
  await new Promise((resolve, reject) => {
    const id = setTimeout(resolve, delay);
    options.signal?.addEventListener('abort', () => { clearTimeout(id); reject(new DOMException('Aborted', 'AbortError')); }, { once: true });
  });
  if (options.signal?.aborted) throw new DOMException('Aborted', 'AbortError');
  const [endpoint, query = ''] = readPath(path);
  const params = new URLSearchParams(query);
  const method = (options.method || 'GET').toUpperCase();
  const headers = headersObject(options.headers);
  const body = options.body || {};
  if (flag === 'offline' && !['auth/csrf.php', 'auth/login.php', 'auth/me.php'].includes(endpoint)) fail('offline', 'You appear to be offline. Reconnect and retry.', 0);
  if (flag === 'error' && !['auth/csrf.php', 'auth/login.php', 'auth/me.php'].includes(endpoint)) fail('service_unavailable', 'The portal is temporarily unavailable. Try again in a moment.', 503);
  if (flag === 'expired' && !loggedIn && endpoint !== 'auth/csrf.php' && endpoint !== 'auth/login.php' && endpoint !== 'auth/password-reset.php' && endpoint !== 'auth/password-reset-request.php') fail('session_expired', 'Your session has expired. Sign in to continue.', 401);
  if (method === 'POST') {
    if (headers['x-csrf-token'] !== csrf) fail('csrf_invalid', 'Your session needs refreshing. Sign in again.', 403);
    if (['transcripts/create.php', 'transcripts/pay.php'].includes(endpoint) && !headers['idempotency-key']) fail('validation_failed', 'Try again to submit this request.', 422);
  }
  if (endpoint === 'auth/login.php' && method === 'POST') {
    if (body.matricNo === 'LOCKED') fail('rate_limited', 'Too many sign-in attempts. Try again in 30 seconds.', 429);
    if (body.matricNo !== 'CSC/2023/001' || !String(body.password || '').trim()) fail('invalid_credentials', 'Matric number or password is incorrect.', 401);
    loggedIn = true; sessionStorage.setItem('portalMockAuth', 'true');
    return response({ student: copy(profile), csrfToken: csrf });
  }
  if (endpoint === 'auth/csrf.php' && method === 'GET') return response({ csrfToken: csrf });
  if (endpoint === 'auth/password-reset-request.php') return response({ message: 'If the account can be found, reset instructions will be sent.' });
  if (endpoint === 'auth/password-reset.php') {
    if (!body.token || String(body.newPassword || '').length < 8) fail('validation_failed', 'Use a valid reset link and a password with at least 8 characters.', 422, { newPassword: 'Enter at least 8 characters.' });
    return response({ reset: true });
  }
  if (endpoint === 'auth/me.php') {
    if (!loggedIn) fail('unauthenticated', 'Sign in to continue.', 401);
    return response({ student: copy(profile), csrfToken: csrf });
  }
  if (endpoint === 'auth/logout.php' && method === 'POST') { loggedIn = false; sessionStorage.removeItem('portalMockAuth'); return response({ loggedOut: true }); }
  if (!loggedIn && endpoint !== 'auth/me.php') fail('session_expired', 'Your session has expired. Sign in to continue.', 401);
  if (endpoint === 'results/options.php') return response({ pairs: flag === 'empty' ? [] : copy(pairs) });
  if (endpoint === 'results/index.php') {
    const pair = pairs.find(p => p.session === params.get('session') && p.semester === params.get('semester'));
    if (!pair) fail('not_found', 'That session and semester are not available.', 404);
    if (!pair.released) return response({ session: pair.session, semester: pair.semester, availability: pair.availability, message: pair.message, courses: [], summary: null });
    if (flag === 'empty' || pair.session === '2024/2025' && pair.semester === 'First Semester') return response({ session: pair.session, semester: pair.semester, availability: 'empty', courses: [], summary: null });
    const courses = pair.session === '2025/2026' ? copy(courseRows) : copy(courseRows.slice(0, 4).map(c => ({ ...c, grade: c.code === 'CSC 312' ? 'B' : c.grade, carryOver: c.code === 'CSC 312' })));
    return response({ session: pair.session, semester: pair.semester, availability: 'released', courses, summary: { gpa: pair.session === '2025/2026' ? 3.71 : 4.12, cgpa: 3.68, unitsRegistered: courses.reduce((n, c) => n + c.units, 0), unitsEarned: courses.reduce((n, c) => n + c.unitsEarned, 0), degreeClass: 'Second Class Honours (Upper Division)' } });
  }
  if (endpoint === 'transcripts/index.php') return response({ items: flag === 'empty' ? [] : copy(transcripts).sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)) });
  if (endpoint === 'transcripts/quote.php') {
    const type = params.get('destinationType') || 'personal';
    const methodName = params.get('deliveryMethod') || 'download';
    const copies = Number(params.get('copies') || 1);
    const supported = type === 'personal' ? methodName === 'download' : type === 'institution' && ['email', 'courier'].includes(methodName);
    if (!supported || copies < 1 || copies > 3) fail('validation_failed', 'Choose a supported delivery method and 1 to 3 copies.', 422, { deliveryMethod: 'Choose a delivery method for this destination.', copies: 'Choose 1 to 3 copies.' });
    const amount = (type === 'personal' ? 15000 : 12000) + (methodName === 'courier' ? 10000 : methodName === 'email' ? 3000 : 0) + (copies - 1) * 5000;
    return response({ feeAmount: amount, currency: 'NGN', expiresAt: new Date(Date.now() + 15 * 60 * 1000).toISOString(), destinationType: type, deliveryMethod: methodName, copies });
  }
  if (endpoint === 'transcripts/create.php' && method === 'POST') {
    const destinationType = body.destinationType || 'personal';
    const copies = Number(body.copies || 1);
    const supported = destinationType === 'personal' ? body.deliveryMethod === 'download' : destinationType === 'institution' && ['email', 'courier'].includes(body.deliveryMethod);
    if (!supported || copies < 1 || copies > 3) fail('validation_failed', 'Choose a supported delivery method and 1 to 3 copies.', 422, { deliveryMethod: 'Choose a delivery method for this destination.', copies: 'Choose 1 to 3 copies.' });
    if (!body.quoteExpiresAt || Number.isNaN(Date.parse(body.quoteExpiresAt)) || Date.parse(body.quoteExpiresAt) <= Date.now()) fail('validation_failed', 'The fee quote expired. Request a new quote and review again.', 422, { quoteExpiresAt: 'Get a current fee quote.' });
    const destKey = destinationType === 'personal' ? 'personal' : String(body.institutionName || '').toLowerCase();
    if (destinationType === 'institution' && (!body.institutionName || body.deliveryMethod === 'courier' && !body.destinationAddress || body.deliveryMethod === 'email' && !body.destinationEmail)) fail('validation_failed', 'Add the institution name and the destination required by the delivery method.', 422, { institutionName: 'Enter the institution name.', destinationAddress: 'Enter the destination address or email.' });
    const dup = transcripts.find(t => t.status === 'pending_payment' && (t.destination.type === 'personal' ? 'personal' : t.destination.institutionName.toLowerCase()) === destKey);
    if (dup) fail('duplicate_open_request', 'There is already an unpaid request for this destination. Pay or cancel it first.', 409);
    const quoteAmount = (destinationType === 'personal' ? 15000 : 12000) + (body.deliveryMethod === 'courier' ? 10000 : body.deliveryMethod === 'email' ? 3000 : 0) + (copies - 1) * 5000;
    const item = { id: `TR-2026-${requestCounter++}`, destination: { type: destinationType, label: destinationType === 'personal' ? 'Personal copy' : body.institutionName, institutionName: body.institutionName || null, destinationAddress: body.destinationAddress || null, destinationEmail: body.destinationEmail || null }, deliveryMethod: body.deliveryMethod || 'download', copies, feeAmount: quoteAmount, status: 'pending_payment', createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(), rejectionReason: null, allowedActions: ['pay', 'cancel'], payment: { reference: null, status: null, paidAt: null } };
    transcripts.unshift(item);
    return response({ transcript: copy(item) });
  }
  if (endpoint === 'transcripts/pay.php' && method === 'POST') {
    const item = transcripts.find(t => t.id === params.get('id'));
    if (!item) fail('not_found', 'Transcript request not found.', 404);
    if (!item.allowedActions.some(action => ['pay', 'retry'].includes(action))) fail('invalid_transition', 'This request is not waiting for payment.', 409);
    item.status = 'payment_processing'; item.allowedActions = []; item.payment.reference = `PAY-MOCK-${Date.now()}`;
    return response({ transcriptId: item.id, mode: 'simulated', paymentUrl: null, reference: item.payment.reference, status: 'payment_processing' });
  }
  if (endpoint === 'payments/verify.php' && method === 'POST') {
    const item = transcripts.find(t => t.id === params.get('id'));
    if (!item) fail('not_found', 'Transcript request not found.', 404);
    if (!body.reference || body.reference !== item.payment.reference) fail('validation_failed', 'The payment reference does not match this request.', 422, { reference: 'Check the payment reference and try again.' });
    if (item.status === 'paid' && item.payment.status === 'success') return response({ transcriptId: item.id, paymentStatus: 'success', transcriptStatus: 'paid', allowedActions: item.allowedActions, verifiedAt: item.payment.paidAt, receiptAvailable: true });
    if (item.status !== 'payment_processing') fail('invalid_transition', 'This payment is not waiting for verification.', 409);
    item.status = 'paid'; item.updatedAt = new Date().toISOString(); item.allowedActions = ['receipt']; item.payment.status = 'success'; item.payment.paidAt = item.updatedAt;
    return response({ transcriptId: item.id, paymentStatus: 'success', transcriptStatus: 'paid', allowedActions: ['receipt'], verifiedAt: item.updatedAt, receiptAvailable: true });
  }
  if (endpoint === 'transcripts/cancel.php' && method === 'POST') {
    const item = transcripts.find(t => t.id === params.get('id'));
    if (!item) fail('not_found', 'Transcript request not found.', 404);
    if (!item.allowedActions.includes('cancel')) fail('invalid_transition', 'This request can no longer be cancelled.', 409);
    item.status = 'cancelled'; item.allowedActions = []; item.updatedAt = new Date().toISOString();
    return response({ transcriptId: item.id, status: item.status, allowedActions: [] });
  }
  if (endpoint === 'payments/receipt.php') {
    const item = transcripts.find(t => t.id === params.get('id'));
    if (!item || item.payment?.status !== 'success') fail('not_found', 'A verified receipt is not available.', 404);
    return response({ receiptNumber: `RCPT-${item.id}`, amount: item.feeAmount, paidAt: item.payment.paidAt, reference: item.payment.reference });
  }
  if (endpoint === 'transcripts/download.php' && options.responseType === 'blob') {
    const item = transcripts.find(t => t.id === params.get('id'));
    if (!item) fail('not_found', 'Transcript request not found.', 404);
    if (!['ready', 'sent'].includes(item.status) || !item.allowedActions.includes('download')) fail('invalid_transition', 'This transcript is not available to download yet.', 409);
    return mockPdf();
  }
  fail('not_found', 'This portal action is not available.', 404);
}

export async function request(path, options = {}) {
  const [endpoint] = readPath(path);
  const method = (options.method || 'GET').toUpperCase();
  const headers = headersObject(options.headers);
  const idempotencyKey = headers['idempotency-key'];
  if (method !== 'POST' || !idempotencyKey || !['transcripts/create.php', 'transcripts/pay.php'].includes(endpoint)) return performRequest(path, options);
  const ledgerKey = `${endpoint}:${idempotencyKey}`;
  const fingerprint = JSON.stringify(options.body || {});
  const existing = idempotencyLedger.get(ledgerKey);
  if (existing) {
    if (existing.fingerprint !== fingerprint) fail('idempotency_conflict', 'This request key was already used with different details.', 409);
    if (existing.promise) return existing.promise;
    if (existing.error) throw existing.error;
    return copy(existing.result);
  }
  const record = { fingerprint, promise: null, result: null, error: null };
  record.promise = performRequest(path, options).then(result => {
    record.result = result; record.promise = null; return result;
  }).catch(error => {
    record.promise = null;
    if (error.status === 0 || error.name === 'AbortError') idempotencyLedger.delete(ledgerKey);
    else record.error = error;
    throw error;
  });
  idempotencyLedger.set(ledgerKey, record);
  return record.promise;
}
