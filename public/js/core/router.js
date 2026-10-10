import { setNavigationSignal } from './api.js';

let controller;
let onRoute = () => {};
let authenticated = false;
const pageModules = {
  login: () => import('../pages/login.js'), 'forgot-password': () => import('../pages/login.js'), 'reset-password': () => import('../pages/login.js'), home: () => import('../pages/home.js'),
  results: () => import('../pages/results.js'), transcripts: () => import('../pages/transcripts.js')
};
const protectedRoutes = new Set(['home', 'results', 'transcripts']);

export function setAuthState(value) { authenticated = Boolean(value); }
export function currentRoute() { return location.hash.replace(/^#\/?/, '').split('?')[0] || 'home'; }
export function navigate(route, replace = false) {
  if (replace) { history.replaceState(null, '', `#/${route}`); void resolve(); }
  else location.hash = `/${route}`;
}
export function startRouter(render) {
  onRoute = render;
  window.addEventListener('hashchange', resolve);
  window.addEventListener('session:expired', () => {
    const route = currentRoute();
    if (route !== 'login' && !['forgot-password', 'reset-password'].includes(route)) sessionStorage.setItem('resumeRoute', location.hash.replace(/^#\/?/, ''));
    setAuthState(false);
    window.dispatchEvent(new CustomEvent('portal:session-expired'));
    navigate('login?expired=1');
  });
  resolve();
}
async function resolve() {
  controller?.abort();
  controller = new AbortController();
  setNavigationSignal(controller.signal);
  let route = currentRoute();
  if (!pageModules[route]) route = 'home';
  if (protectedRoutes.has(route) && !authenticated) {
    sessionStorage.setItem('resumeRoute', location.hash.replace(/^#\/?/, '') || route);
    route = 'login';
    if (currentRoute() !== 'login') history.replaceState(null, '', '#/login');
  }
  const params = new URLSearchParams(location.hash.split('?')[1] || '');
  const page = await pageModules[route]();
  await onRoute(route, page, { params, signal: controller.signal });
}
