import { shell, icon, emptyState, money, date, statusBadge, escapeHtml, sealMarkup } from '../components/ui.js';
import { get } from '../core/api.js';

export async function render({ profile, signal }) {
  let requests;
  try { requests = await get('transcripts/index.php', { signal }); }
  catch (error) { return shell({ route: 'home', profile, content: `<section class="welcome-banner"><div><h1>Welcome back, ${escapeHtml(profile.fullName.split(' ')[0])}</h1><p>Your academic record and transcript requests, together.</p></div><div class="banner-mark" aria-hidden="true">${sealMarkup('home-error-seal', 84, true)}</div></section><div class="inline-alert tone-error" role="alert">${icon('alert')}<span>${escapeHtml(error.message)} <button class="text-link" data-action="retry-page">Retry</button></span></div>` }); }
  const items = requests.items || [];
  const latest = [...items].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  const actionItems = latest.filter(item => item.allowedActions?.some(action => ['pay', 'retry'].includes(action)));
  const content = `<section class="welcome-banner"><div><h1>${greeting()}, ${escapeHtml(profile.fullName.split(' ')[0])}</h1><p>${escapeHtml(profile.department)} · Level ${escapeHtml(profile.level)}<br>${escapeHtml(profile.matricNo)}</p></div><div class="banner-mark" aria-hidden="true">${sealMarkup('home-seal', 84, true)}</div></section>
    <section class="metric-grid" aria-label="Student details"><article class="card metric-card"><p class="metric-label">Current level</p><p class="metric-value">${escapeHtml(profile.level)}</p><p class="metric-foot">${escapeHtml(profile.department)}</p></article><article class="card metric-card"><p class="metric-label">Items needing action</p><p class="metric-value">${actionItems.length}</p><p class="metric-foot">Payment or retry needed</p></article><article class="card metric-card"><p class="metric-label">Transcript requests</p><p class="metric-value">${items.length}</p><p class="metric-foot">All request statuses</p></article></section>
    <div class="home-grid"><section class="card card-pad"><div class="section-head"><div><h2 class="section-title">Action needed</h2><p class="section-copy">${actionItems.length ? `${actionItems.length} request${actionItems.length === 1 ? '' : 's'} need attention` : 'You are all caught up.'}</p></div><a class="text-link" href="#/transcripts">View all requests ${icon('arrow', 16)}</a></div>${actionItems.length ? actionItems.map((item, index) => { const retry = item.allowedActions.includes('retry'); return `<article class="action-item"><span class="action-count">${index + 1}</span><div class="action-item-main"><strong>${escapeHtml(item.destination?.label || item.id)} · ${money(item.feeAmount)}</strong><small>${statusBadge(item.status)} · Created ${date(item.createdAt)}</small></div><button class="btn btn-small" data-action="${retry ? 'retry-payment' : 'pay'}" data-id="${escapeHtml(item.id)}">${retry ? 'Retry payment' : 'Pay'} ${icon('arrow', 16)}</button></article>`; }).join('') : emptyState('No action needed', 'Your latest requests and updates will appear here.')}</section>
      <section class="card card-pad"><div class="section-head"><div><h2 class="section-title">Quick access</h2><p class="section-copy">Pick up where you left off.</p></div></div><div class="quick-actions"><a class="btn btn-secondary" href="#/results">${icon('book')} View results ${icon('arrow', 16)}</a><a class="btn btn-secondary" href="#/transcripts?new=1">${icon('file')} Request a transcript ${icon('arrow', 16)}</a></div><div class="status-explainer">Your transcript status and available actions are updated by Registry.</div></section></div>`;
  return shell({ route: 'home', profile, content });
}

function greeting() {
  const hour = Number(new Intl.DateTimeFormat('en', { hour: 'numeric', hour12: false, timeZone: 'Africa/Lagos' }).format(new Date()));
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}
