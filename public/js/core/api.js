const base = '../api/';
const pending = new Map();
const cache = new Map();
let navSignal = null;
let csrfToken = '';
let mockAdapter;
const config = window.PORTAL_CONFIG || { USE_MOCKS: true, API_TIMEOUT_MS: 12000, CACHE_TTL_MS: 20000 };

export function setNavigationSignal(signal) { navSignal = signal; }
export function setCsrfToken(token) { csrfToken = token || ''; }
export function clearPrivateCache() { cache.clear(); }

function urlFor(path) { return new URL(path.replace(/^\//, ''), new URL(base, location.href)).href; }
function keyFor(path, options) { return `${(options.method || 'GET').toUpperCase()}:${path}:${JSON.stringify(options.body || null)}`; }
function errorShape(code, message, fields = {}, status = 0) {
  return Object.assign(new Error(message), { error: { code, message, fields }, status });
}
function publicAuthPath(path) {
  return /auth\/(csrf|login|password-reset|password-reset-request)\.php/.test(path);
}

async function adapter() {
  if (!mockAdapter) mockAdapter = await import('../mock/adapter.js');
  return mockAdapter;
}

async function request(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort('timeout'), config.API_TIMEOUT_MS);
  const onNavAbort = () => controller.abort('navigation');
  navSignal?.addEventListener('abort', onNavAbort, { once: true });
  const headers = new Headers(options.headers || {});
  headers.set('Accept', 'application/json');
  if (options.responseType === 'blob') headers.set('Accept', 'application/pdf');
  if (options.body !== undefined) headers.set('Content-Type', 'application/json');
  if (method === 'POST') {
    headers.set('X-CSRF-Token', csrfToken);
    if (options.idempotencyKey) headers.set('Idempotency-Key', options.idempotencyKey);
  }
  try {
    if (config.USE_MOCKS) {
      const result = await (await adapter()).request(path, { ...options, method, headers, signal: controller.signal });
      return options.responseType === 'blob' ? result : result.data;
    }
    const response = await fetch(urlFor(path), {
      method, headers, credentials: 'same-origin', signal: controller.signal,
      body: options.body === undefined ? undefined : JSON.stringify(options.body)
    });
    if (!response.ok) {
      const payload = await response.json().catch(() => null);
      const err = payload?.error || { code: 'request_failed', message: response.status === 429 ? 'Too many attempts. Wait a moment and try again.' : 'We could not complete that request. Try again.', fields: {} };
      throw errorShape(err.code, err.message, err.fields || {}, response.status);
    }
    if (options.responseType === 'blob') return await response.blob();
    return (await response.json()).data;
  } catch (error) {
    if (error.status === 401 && !publicAuthPath(path)) window.dispatchEvent(new CustomEvent('session:expired'));
    if (error.name === 'AbortError' && controller.signal.reason === 'navigation') throw errorShape('navigation_cancelled', 'Navigation changed.', {}, 0);
    if (error.name === 'AbortError' || error.name === 'TimeoutError' || error.message === 'The operation was aborted.') {
      throw errorShape('network_timeout', 'The connection took too long. Check your network and try again.', {}, 0);
    }
    if (!navigator.onLine || error instanceof TypeError) throw errorShape('offline', 'You appear to be offline. Reconnect and retry.', {}, 0);
    throw error;
  } finally {
    clearTimeout(timeout);
    navSignal?.removeEventListener('abort', onNavAbort);
  }
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const key = keyFor(path, { ...options, method });
  if (method !== 'GET') {
    if (!csrfToken) {
      const bootstrap = await request('auth/csrf.php', { method: 'GET' });
      setCsrfToken(bootstrap.csrfToken);
    }
    return request(path, { ...options, method });
  }
  if (options.cache === false) return request(path, options);
  if (options.forceRefresh) { cache.delete(key); pending.delete(key); }
  const cached = cache.get(key);
  if (cached && Date.now() - cached.at < config.CACHE_TTL_MS) {
    if (!pending.has(key)) pending.set(key, request(path, options).then(data => {
      cache.set(key, { data, at: Date.now() });
      window.dispatchEvent(new CustomEvent('api:refresh', { detail: { path } }));
      return data;
    }).catch(() => cached.data).finally(() => pending.delete(key)));
    return cached.data;
  }
  if (pending.has(key)) return pending.get(key);
  const promise = request(path, options).then(data => { cache.set(key, { data, at: Date.now() }); return data; })
    .finally(() => pending.delete(key));
  pending.set(key, promise);
  return promise;
}

export const get = (path, options = {}) => api(path, options);
export const post = (path, body = {}, options = {}) => api(path, { ...options, method: 'POST', body });
export const download = path => request(path, { method: 'GET', responseType: 'blob' });
