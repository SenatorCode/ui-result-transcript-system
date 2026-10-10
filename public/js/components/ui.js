import { TRANSCRIPT_STATUS, GRADING_SCALE } from '../status.js';

export const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const icon = (name, size = 20) => `<svg class="icon" width="${size}" height="${size}" aria-hidden="true"><use href="#i-${name}"></use></svg>`;
export const sealMarkup = (id, size = 48, light = false) => `<svg class="seal-svg" width="${size}" height="${size}" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><defs><path id="${id}-ring" d="M50,50 m-38,0 a38,38 0 1,1 76,0 a38,38 0 1,1 -76,0"/></defs><circle cx="50" cy="50" r="48" fill="${light ? 'rgba(255,255,255,.06)' : '#fff'}" stroke="#F0C860" stroke-width="1.5"/><circle cx="50" cy="50" r="30" fill="${light ? 'rgba(255,255,255,.08)' : '#0C2C6B'}" stroke="#F0C860" stroke-width="1"/><g class="seal-orbit"><text font-size="7.2" font-weight="700" letter-spacing="1.6" fill="${light ? '#F1F4FA' : '#0C2C6B'}" font-family="Lato, sans-serif"><textPath href="#${id}-ring">UNIVERSITY OF IBADAN · RECTE SAPERE FONS · </textPath></text></g><path d="M36 47 q7 -4 14 0 v12 q-7 -4 -14 0z M64 47 q-7 -4 -14 0 v12 q7 -4 14 0z" fill="none" stroke="#F0C860" stroke-width="1.6" stroke-linejoin="round"/><path d="M50 36 l2.2 4.4 4.8 .7 -3.5 3.4 .8 4.8 -4.3 -2.3 -4.3 2.3 .8 -4.8 -3.5 -3.4 4.8 -.7z" fill="#F0C860"/></svg>`;
export const money = amount => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(amount || 0);
export const date = value => value ? new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeZone: 'Africa/Lagos' }).format(new Date(value)) : '—';
export const statusBadge = status => {
  const info = TRANSCRIPT_STATUS[status] || TRANSCRIPT_STATUS.failed;
  return `<span class="status-badge tone-${info.tone}"><span class="status-dot" aria-hidden="true"></span>${info.label}</span>`;
};
export const skeleton = (rows = 3) => `<div class="skeleton-stack" aria-label="Loading content">${Array.from({ length: rows }, (_, i) => `<div class="skeleton-card"><span class="skeleton-line w-${i % 2 ? 'short' : 'long'}"></span><span class="skeleton-line w-medium"></span></div>`).join('')}</div>`;
export const emptyState = (title, copy, action = '') => `<section class="empty-state"><div class="empty-icon">${icon('file', 22)}</div><h2>${escapeHtml(title)}</h2><p>${escapeHtml(copy)}</p>${action}</section>`;
export const alertBox = (message, tone = 'warning') => `<div class="inline-alert tone-${tone}" role="status">${icon('alert', 18)}<span>${escapeHtml(message)}</span></div>`;

export function shell({ route, profile, content }) {
  const nav = [['home', 'Overview', 'home'], ['results', 'Results', 'book'], ['transcripts', 'Transcripts', 'file']];
  const greeting = new Intl.DateTimeFormat('en', { hour: 'numeric', hour12: false, timeZone: 'Africa/Lagos' }).format(new Date());
  const greet = Number(greeting) < 12 ? 'Good morning' : Number(greeting) < 17 ? 'Good afternoon' : 'Good evening';
  const user = profile ? `<div class="profile-chip"><span class="avatar" aria-hidden="true">${escapeHtml(profile.fullName.split(' ').map(n => n[0]).join('').slice(0, 2))}</span><span><strong>${escapeHtml(profile.fullName)}</strong><small>${escapeHtml(profile.matricNo)}</small></span></div>` : '';
  return `<div class="portal-shell">
    <aside id="primary-sidebar" class="sidebar" aria-label="Main navigation">
      <a class="brand" href="#/home" aria-label="University of Ibadan student portal home">${sealMarkup('sidebar-seal', 48)}<span class="brand-name">University of Ibadan<small>Student portal</small></span></a>
      <div class="sidebar-label">STUDENT SPACE</div>
      <nav class="side-links">${nav.map(([key, label, ico]) => `<a href="#/${key}" class="nav-link ${route === key ? 'is-active' : ''}" ${route === key ? 'aria-current="page"' : ''}>${icon(ico)}<span>${label}</span></a>`).join('')}</nav>
      <div class="sidebar-bottom"><a class="admin-link" href="../admin/">Admin login ${icon('arrow', 16)}</a><button class="nav-link logout-button" data-action="logout">${icon('log-out')}<span>Sign out</span></button></div>
    </aside>
    <div class="workspace">
      <header class="topbar"><button class="mobile-menu" aria-label="Open navigation" aria-controls="primary-sidebar" aria-expanded="false" data-action="menu">${icon('menu')}</button><a class="mobile-brand" href="#/home">${sealMarkup('mobile-seal', 32)} UI Student Portal</a><div class="topbar-right">${user}<button class="icon-button signout-mobile" data-action="logout" aria-label="Sign out">${icon('log-out')}</button></div></header>
      <main id="main-content" class="main-content" tabindex="-1">${content}</main>
      <nav class="bottom-tabs" aria-label="Main navigation">${nav.map(([key, label, ico]) => `<a href="#/${key}" class="tab-link ${route === key ? 'is-active' : ''}" ${route === key ? 'aria-current="page"' : ''}>${icon(ico)}<span>${label}</span></a>`).join('')}</nav>
      <footer class="portal-footer"><span>University of Ibadan · Results &amp; Transcript Portal</span><a class="admin-link" href="../admin/">Admin login ${icon('arrow', 15)}</a></footer>
    </div>
  </div>`;
}

export function gradeLegend() {
  return `<div class="grade-legend" aria-label="Grading scale">${GRADING_SCALE.map(item => `<span><b class="grade-chip grade-${item.grade}">${item.grade}</b><small>${item.meaning} · ${item.points}</small></span>`).join('')}</div>`;
}
