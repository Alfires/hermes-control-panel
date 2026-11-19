'use strict';
// Hermes Control Deck - single-page UI. No framework, no build step, no CDN.
if (location.search.includes('debug=1')) {
  const showJsError = (msg) => {
    let box = document.getElementById('js-error');
    if (!box) { box = document.createElement('pre'); box.id = 'js-error';
      box.style.cssText = 'position:fixed;z-index:9999;left:0;top:0;right:0;padding:12px;background:#b00020;color:#fff;font:13px monospace;white-space:pre-wrap';
      document.body.appendChild(box); }
    box.textContent = (box.textContent ? box.textContent + '\n' : '') + msg;
  };
  window.addEventListener('error', e => showJsError(`${e.message} @ ${e.filename}:${e.lineno}`));
  window.addEventListener('unhandledrejection', e => showJsError('promise: ' + (e.reason && e.reason.message || e.reason)));
}
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c =>
  ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const arr = (v) => {
  if (Array.isArray(v)) return v;
  if (typeof v !== 'string' || !v) return [];
  try { const x = JSON.parse(v); return Array.isArray(x) ? x : []; }
  catch (e) { return v.split(',').map(s => s.trim()).filter(Boolean); }
};
const fmt = (ts) => ts ? new Date(ts * 1000).toLocaleString() : '—';
const L = () => { try { return (S.layout && S.layout.value && JSON.parse(S.layout.value)) || {}; }
                  catch (e) { return {}; } };
const ago = (ts) => { if (!ts) return '—'; const s = Math.floor(Date.now()/1000 - ts);
  if (s < 60) return s + 's ago'; if (s < 3600) return Math.floor(s/60) + 'm ago';
  if (s < 86400) return Math.floor(s/3600) + 'h ago'; return Math.floor(s/86400) + 'd ago'; };
/** The 10s background refresh used to rebuild #content mid-typing and wipe
 *  half-filled forms. Pause it while the owner is editing anything. */
function hasUnsavedInput() {
  const active = document.activeElement;
  if (active && ['INPUT','TEXTAREA','SELECT'].includes(active.tagName)) return true;
  return $$('#content input, #content textarea, #content select').some(el => {
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked !== el.defaultChecked;
    return el.value !== el.defaultValue;
  });
}

let S = {};
let view = 'overview';
let chatAgent = null, chatSession = null;
let customStyle = null;

const NAV = [
  {id:'overview', ico:'◈', label:'Overview', group:'Core'},
  {id:'sessions', ico:'▤', label:'Sessions', group:'Core'},
  {id:'agents',    ico:'⬡', label:'Agents', group:'Core'},
  {id:'approvals', ico:'✓', label:'Approvals', group:'Core'},
  {id:'security', ico:'⚠', label:'Security', group:'Core'},
  {id:'vault',     ico:'⚿', label:'Vault', group:'Core'},
  {id:'search',    ico:'⌕', label:'Search', group:'Core'},
  {id:'themes',    ico:'◐', label:'Themes', group:'Workspace'},
  {id:'voice',     ico:'◉', label:'Voice', group:'Workspace'},
  {id:'self-test', ico:'✦', label:'Self test', group:'Workspace'},
  {id:'hermes',    ico:'▦', label:'Hermes', group:'Hermes'},
  {id:'settings', ico:'⚙', label:'Settings', group:'Core'},
  {id:'audit',     ico:'≡', label:'Audit', group:'Core'},
  {id:'setup',     ico:'⬇', label:'Setup', group:'Core'},
];
const TITLES = {
  overview:['Overview','All agents, sessions and live activity'],
  sessions:['Sessions','Full context and chat for every agent session'],
  agents:['Agents','Permissions, ranks and per-agent scopes'],
  approvals:['Approvals','One-time escalations and recorded actions'],
  security:['Security','Daily exploit watch and proposed fixes'],
  vault:['Vault','Names and policies only — values never reach an agent'],
  search:['Search','One search across pages, agents, sessions, findings and Hermes'],
  themes:['Themes','Light, dark, rounded and square presets — switch live'],
  voice:['Voice','Local speech-to-text. Nothing leaves the Deck.'],
  'self-test':['Self test','Daily AI check of every page in this panel'],
  hermes:['Hermes','Every Hermes capability, represented through safe data sources'],
  settings:['Settings','Layout the agents can propose, and owner preferences'],
  audit:['Audit','Every decision and privileged call'],
  setup:['Setup','Endpoints, brokers and operator commands'],
  // Registered Hermes pages. Each one now has a real title instead of falling
  // through to the generic Hermes screen with a blank header.
  'hermes-status':['Hermes status','Gateway health, version and uptime'],
  'hermes-sessions':['Hermes sessions','Sessions known to the Hermes gateway'],
  'hermes-files':['Files','File metadata exposed by Hermes — names and sizes only'],
  'hermes-analytics':['Analytics','Request, token and error counters'],
  'hermes-models':['Models','Models currently offered by the gateway'],
  'hermes-logs':['Logs','Recent gateway log entries'],
  'hermes-cron':['Cron','Scheduled jobs inside Hermes'],
  'hermes-skills':['Skills','Installed Hermes skills'],
  'hermes-plugins':['Plugins','Installed plugins and their state'],
  'hermes-mcp':['MCP','Model Context Protocol integrations'],
  'hermes-pairing':['Pairing','Device pairing is managed from the Hermes dashboard'],
  'hermes-channels':['Channels','Connected messaging platforms'],
  'hermes-webhooks':['Webhooks','Configured webhook endpoints'],
  'hermes-profiles':['Profiles','Saved Hermes profiles'],
  'hermes-config':['Config schema','Configuration fields exposed by Hermes'],
  'hermes-env':['Environment names','Variable names only — values are never exposed'],
  'hermes-system':['System','Gateway capabilities and version'],
  'hermes-docs':['Documentation','Where to find the Hermes manuals'],
  'hermes-chat':['Hermes chat','Open a conversation with an agent'],
};
const HERMES_AREAS = [
  ['hermes-status','Status','hermes.status'], ['hermes-sessions','Sessions','hermes.sessions.list'],
  ['hermes-models','Models','hermes.models.options'], ['hermes-tools','Tools','hermes.tools.toolsets'],
  ['hermes-skills','Skills','hermes.skills.list'], ['hermes-cron','Cron','hermes.cron.jobs'],
  ['hermes-analytics','Analytics','hermes.analytics'], ['hermes-themes','Themes','hermes.themes'],
  ['hermes-config','Config schema','hermes.config.schema'], ['hermes-env','Environment names','hermes.env.names'],
  ['hermes-messaging','Messaging','hermes.messaging.platforms'], ['hermes-webhooks','Webhooks','hermes.webhooks'],
  ['hermes-capabilities','Capabilities','hermes.gateway.capabilities'], ['hermes-logs','Logs','hermes.logs'],
  ['hermes-files','Files','hermes.files.meta'], ['hermes-git','Git','hermes.git.status'],
  ['hermes-profiles','Profiles','hermes.profiles'], ['hermes-plugins','Plugins','hermes.plugins'],
  ['hermes-mcp','MCP','hermes.mcp'],
];
// Each registered Hermes page resolves to one curated, scrubbed data source.
// Pages without a safe source get an honest explanation instead of a dead link.
const PAGE_SOURCE = {
  'hermes-status':'hermes.status', 'hermes-sessions':'hermes.sessions.list',
  'hermes-files':'hermes.files.meta', 'hermes-analytics':'hermes.analytics',
  'hermes-models':'hermes.models.options', 'hermes-logs':'hermes.logs',
  'hermes-cron':'hermes.cron.jobs', 'hermes-skills':'hermes.skills.list',
  'hermes-plugins':'hermes.plugins', 'hermes-mcp':'hermes.mcp',
  'hermes-channels':'hermes.messaging.platforms', 'hermes-webhooks':'hermes.webhooks',
  'hermes-profiles':'hermes.profiles', 'hermes-config':'hermes.config.schema',
  'hermes-env':'hermes.env.names', 'hermes-system':'hermes.gateway.capabilities',
};
const PAGE_NOTE = {
  'hermes-pairing':'Pairing tokens are intentionally not proxied. Use the Hermes dashboard to pair a device; the panel never handles its credentials.',
  'hermes-docs':'The Hermes manuals live on the gateway dashboard. This panel links to Hermes rather than caching its documentation.',
  'hermes-chat':'Chats run through this panel so the model has no tools of its own. Open an agent below to start one.',
};

const csrfToken = () => (document.cookie.match(/(?:^|;\s*)panel_csrf=([^;]+)/) || [])[1] || '';

async function api(action, data = {}) {
  const r = await fetch('/api.php?action=' + encodeURIComponent(action), {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(data),
  });
  try { return await r.json(); } catch (e) { return {ok:false, error:'bad JSON from server'}; }
}
function toast(msg) {
  const t = $('#toast'); t.textContent = msg; t.classList.remove('hidden');
  clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.add('hidden'), 3000);
}
function applyLayout() {
  const l = L();
  $('#brand-name').textContent = ((S.config && S.config.meta && S.config.meta.title) || l.title || 'HERMES').toUpperCase();
  const theme = S.config && S.config.theme;
  if (theme && theme.preset) {
    const presets = window.HCP_THEME_PRESETS || (S.theme_presets || {});
    const p = presets[theme.preset];
    document.documentElement.dataset.theme = theme.mode || (p && p.mode) || 'light';
    document.documentElement.dataset.radius = theme.radius || (p && p.radius) || 'rounded';
    const tokens = (p && p.tokens) || {};
    for (const [key, value] of Object.entries(theme.tokens || tokens)) {
      if (/^--[a-z0-9-]+$/.test(key) && /^[#0-9a-zA-Z. ()\-,]+$/.test(String(value))) {
        document.documentElement.style.setProperty(key, value);
      }
    }
  }
  // Raw custom_css is intentionally ignored: AI edits only validated tokens.
}
function sortedAgents() {
  const l = L();
  const order = l.card_order || [];
  return [...(S.agents || [])].sort((a,b) => {
    const ai = order.indexOf(a.slug), bi = order.indexOf(b.slug);
    return (ai < 0 ? 999 : ai) - (bi < 0 ? 999 : bi) || a.id - b.id;
  });
}
function pendingCount() { return (S.escalations||[]).filter(e => e.status === 'pending').length; }
function findingCount() { return (S.findings||[]).length; }

async function bootstrap(first = false) {
  const r = await api('bootstrap');
  if (r.ok === false && r.error === 'unauthorized') { showLogin(); return; }
  if (!r.ok) { toast(r.error || 'bootstrap failed'); return; }
  S = r;
  applyLayout();
  if (first) showApp();
  renderNav(); renderPills(); render(); renderDrawer();
}

function showLogin() { $('#login').classList.remove('hidden'); $('#app').classList.add('hidden'); }
function showApp() { $('#login').classList.add('hidden'); $('#app').classList.remove('hidden'); }

const collapsedGroups = new Set(['Hermes']); // Hermes starts folded; Core is always visible.
function renderNav() {
  const counts = {approvals: pendingCount(), security: findingCount()};
  let items = NAV;
  const configured = (S.config && S.config.pages) || [];
  if (configured.length) {
    items = configured.map(p => ({
      id: p.id, ico: (p.nav && p.nav.icon) || '▦', label: (p.nav && p.nav.label) || p.id,
      group: (p.nav && p.nav.group) || 'Core',
    }));
  }
  // Use the plain-language title from PAGE_META when one exists.
  items = items.map(n => ({...n, label: (TITLES[n.id] && TITLES[n.id][0]) || n.label}));
  const q = (navFilter || '').trim().toLowerCase();
  const shown = q ? items.filter(n => (n.label + ' ' + n.id).toLowerCase().includes(q)) : items;
  const groups = [...new Set(shown.map(n => n.group || 'Core'))];
  $('#nav').innerHTML = `
    <div class="nav-filter"><span>⌕</span><input id="nav-filter" placeholder="Jump to a page…" value="${esc(navFilter)}" autocomplete="off"></div>
    ${groups.map(g => {
      const folded = !q && collapsedGroups.has(g);
      return `<div class="nav-group">
        <button class="nav-group-head" data-group="${esc(g)}"><span>${esc(g)}</span>
          <span class="caret">${folded ? '▸' : '▾'}</span></button>
        ${folded ? '' : `<div class="nav-group-items">${shown.filter(n => (n.group || 'Core') === g).map(n => `
          <button class="nav-item ${view===n.id?'active':''}" data-view="${esc(n.id)}" title="${esc(n.label)}">
            <span class="ico">${esc(n.ico)}</span><span class="nav-label">${esc(n.label)}</span>
            ${counts[n.id] ? `<span class="nav-badge">${counts[n.id]}</span>` : ''}
          </button>`).join('')}</div>`}</div>`;
    }).join('')}`;
  const filter = $('#nav-filter');
  if (filter) {
    filter.oninput = () => { navFilter = filter.value; const pos = filter.selectionStart; renderNav();
      const f2 = $('#nav-filter'); if (f2) { f2.focus(); f2.setSelectionRange(pos, pos); } };
    filter.onkeydown = e => { if (e.key === 'Escape') { navFilter = ''; renderNav(); } };
  }
  $$('[data-group]').forEach(b => b.onclick = () => {
    const g = b.dataset.group;
    if (collapsedGroups.has(g)) collapsedGroups.delete(g); else collapsedGroups.add(g);
    renderNav();
  });
  $$('.nav-item').forEach(b => b.onclick = () => { view = b.dataset.view; navFilter = ''; renderNav(); render(); });
}
let navFilter = '';
function renderPills() {
  const l = L();
  const pills = [
    ['vault', S.vault?.available],
    ['host', S.host?.available],
    ['model', l.default_model || 'big-pickle'],
  ];
  $('#pills').innerHTML = pills.map(([k,v]) =>
    `<span class="pill ${v===true?'on':v===false?'off':''}">${esc(k)} ${typeof v==='boolean'?'':esc(v)}</span>`).join('');
}
function render() {
  const [title, sub] = TITLES[view] || ['', ''];
  $('#view-title').textContent = title; $('#view-sub').textContent = sub;
  const fn = {overview:renderOverview, sessions:renderSessions, agents:renderAgents,
    approvals:renderApprovals, security:renderSecurity, vault:renderVault,
    settings:renderSettings, audit:renderAudit, setup:renderSetup,
    search:renderSearch, themes:renderThemes, voice:renderVoice,
    'self-test':renderSelfTest, hermes:renderHermes}[view];
  if (fn) {
    $('#content').innerHTML = fn();
    bindContent();
    if (view === 'voice') bindVoice();
    if (view === 'self-test') loadSelfTest();
    if (view === 'search') bindSearch();
    return;
  }
  // A registered page with no bespoke renderer still gets its own titled view.
  // Before this fix every such page silently displayed the generic Hermes grid.
  renderSourcePage(view);
}

/** Shared renderer for a page backed by one curated Hermes source. */
function renderSourcePage(pageId) {
  const source = PAGE_SOURCE[pageId];
  const note = PAGE_NOTE[pageId];
  const title = (TITLES[pageId] || [pageId, ''])[0];
  const back = `<div class="row" style="margin-bottom:14px"><button class="btn ghost small" data-goto="hermes">← All Hermes areas</button>
    <span class="muted" style="font-size:11px;margin-left:auto">Curated source · nothing leaves the Deck</span></div>`;
  if (!source) {
    $('#content').innerHTML = back + `<div class="card" style="padding:22px;max-width:720px">
      <h2>${esc(title)}</h2><p class="muted">${esc(note || 'This area has no safe data source in the panel yet.')}</p>
      <div class="row" style="margin-top:16px"><button class="btn primary small" data-goto="hermes">Browse Hermes areas</button>
      <button class="btn small" data-newchat>Open an agent chat</button></div></div>`;
    bindContent();
    return;
  }
  $('#content').innerHTML = back + `<div id="source-body"><p class="muted">Loading ${esc(source)}…</p></div>`;
  bindContent();
  api('hermes.source', {source}).then(r => {
    const box = $('#source-body'); if (!box) return;
    if (!r.ok) { box.innerHTML = `<div class="card"><p>${esc(r.error || 'Unavailable')}</p>
      <p class="muted" style="font-size:11px">The gateway may be offline or this capability disabled.</p></div>`; return; }
    box.innerHTML = dataView(r.data, source, r.cached);
  }).catch(() => { const b = $('#source-body'); if (b) b.innerHTML = '<div class="card">Could not load this source.</div>'; });
}

/** Turn a source payload into a readable table, never a raw JSON wall. */
function dataView(data, source, cached) {
  const rows = Array.isArray(data) ? data : [data];
  const objects = rows.filter(r => r && typeof r === 'object' && !Array.isArray(r));
  let body = '';
  if (objects.length && objects.every(o => Object.keys(o).every(k => typeof o[k] !== 'object'))) {
    const cols = [...new Set(objects.flatMap(o => Object.keys(o)))].slice(0, 8);
    body = `<div style="overflow:auto"><table><thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>
      <tbody>${objects.map(o => `<tr>${cols.map(c => `<td>${esc(o[c] ?? '—')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  } else {
    body = rows.map(row => `<div class="data-row">${esc(typeof row === 'string' ? row : JSON.stringify(row))}</div>`).join('');
  }
  const raw = JSON.stringify(data, null, 2);
  return `<div class="card" style="padding:18px">
    <div class="row" style="justify-content:space-between"><h3>${esc(source)} ${cached?'<span class="muted" style="font-size:10px">(cached)</span>':''}</h3>
      <button class="btn ghost small" data-raw-toggle>Show raw data</button></div>
    <div style="margin-top:12px">${body || '<p class="muted">No data returned.</p>'}</div>
    <pre class="raw-data hidden">${esc(raw)}</pre></div>`;
}

/* ---------------------------------------------------------------- overview */
function renderOverview() {
  const agents = sortedAgents();
  const openSessions = (S.sessions||[]).length;
  // One glance, one action: surface the things that actually need a decision.
  const pend = pendingCount(), finds = findingCount();
  const attention = (!pend && !finds) ? '' : `<div class="attention">
    <div class="attention-title">Needs your attention</div>
    ${pend ? `<div class="attention-row"><span class="attention-dot warn"></span>
      <span><strong>${pend}</strong> approval${pend===1?'':'s'} waiting on you</span>
      <button class="btn primary small" data-goto="approvals">Review</button></div>` : ''}
    ${finds ? `<div class="attention-row"><span class="attention-dot bad"></span>
      <span><strong>${finds}</strong> open security finding${finds===1?'':'s'}</span>
      <button class="btn small" data-goto="security">Inspect</button></div>` : ''}
  </div>`;
  const hero = `
  <div class="hero">
    <div class="hero-copy">
      <div class="role">HERMES CONTROL DECK</div>
      <h1>Your digital <em>team.</em></h1>
      <p>Every agent, session and approval in one place. Set the direction — your agents work within your rules, on your own hardware.</p>
      <div class="hero-actions">
        <button class="btn primary" data-newchat>Open a chat</button>
        <button class="btn" data-goto="approvals">Review approvals ${pendingCount() ? `(${pendingCount()})` : ''}</button>
      </div>
      <div class="trust"><span>✓ On-device model</span><span>✓ Owner-only access</span><span>✓ Every action audited</span></div>
    </div>
    <div class="hero-team">${agents.slice(0,3).map(a => `
      <div class="mini"><b>${esc((a.name||'?')[0])}</b><span>${esc(a.name)}</span><span>${a.enabled?'live':'off'}</span></div>`).join('')}</div>
  </div>`;
  const kpi = `
  <div class="kpi">
    <div class="card"><div class="num">${agents.length}</div><div class="lbl">Active agents</div></div>
    <div class="card"><div class="num">${openSessions}</div><div class="lbl">Sessions</div></div>
    <div class="card"><div class="num" style="color:var(--accent2)">${pendingCount()}</div><div class="lbl">Waiting approval</div></div>
    <div class="card"><div class="num" style="color:${findingCount()?'var(--bad)':'var(--ok)'}">${findingCount()}</div><div class="lbl">Open findings</div></div>
  </div>`;
  const cards = agents.map((a,i) => {
    const tools = arr(a.tools);
    const sess = (S.sessions||[]).filter(s => s.agent_id === a.id).length;
    const medal = i < 3 ? ['gold','silver','bronze'][i] : '';
    return `<div class="card">
      <h3><span class="rank ${medal}">${i+1}</span> ${esc(a.name)}
        <span style="margin-left:auto" class="pill ${a.enabled?'on':'off'}">${a.enabled?'live':'off'}</span></h3>
      <div class="role">${esc(a.role)}</div>
      <div class="desc">${esc(a.description || 'No description.')}</div>
      <div>${tools.slice(0,5).map(t => `<span class="tag ${['host_apply','webui_edit','vault_use'].includes(t)?'priv':''}">${esc(t)}</span>`).join('')}
        ${tools.length>5?`<span class="tag">+${tools.length-5}</span>`:''}</div>
      <div class="row" style="margin-top:12px">
        <button class="btn primary small" data-chat="${a.id}">Open chat</button>
        <button class="btn small" data-newsess="${a.id}">New session</button>
        <button class="btn ghost small" data-edit="${a.id}">Edit</button>
        <span class="muted" style="font-size:11px;margin-left:auto">${sess} session${sess===1?'':'s'}</span>
      </div>
    </div>`;
  }).join('');
  const acts = (S.audit||[]).slice(0,8).map(a =>
    `<tr><td>${ago(a.created_at)}</td><td>${esc(a.actor)}</td><td>${esc(a.action)}</td><td class="muted">${esc(a.detail||'')}</td></tr>`).join('');
  return attention + hero + kpi + `<div class="section-head"><h2>Agent ranks</h2><span class="muted">ordered by layout</span></div>
    <div class="grid cards">${cards || '<div class="empty">No agents yet.</div>'}</div>
    <div class="section-head" style="margin-top:22px"><h2>Recent activity</h2></div>
    <div class="card"><table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead><tbody>${acts}</tbody></table></div>`;
}

/* --------------------------------------------------------------- sessions */
function renderSessions() {
  const rows = (S.sessions||[]).map(s => `<tr>
    <td><strong>${esc(s.title)}</strong><br><span class="muted">#${s.id}</span></td>
    <td>${esc(s.agent_name)}</td>
    <td>${ago(s.updated_at)}</td>
    <td><button class="btn small" data-open="${s.id}" data-agent="${s.agent_id}">Open</button>
        <button class="btn ghost small" data-delsess="${s.id}">Delete</button></td></tr>`).join('');
  return `<div class="section-head"><h2>All sessions</h2>
      <button class="btn small" data-newchat>Start a chat</button></div>
    <div class="card"><table><thead><tr><th>Session</th><th>Agent</th><th>Updated</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="4" class="muted">No sessions yet — open an agent chat.</td></tr>'}</tbody></table></div>`;
}

/* ----------------------------------------------------------------- agents */
function renderAgents() {
  const rows = sortedAgents().map((a,i) => `<tr>
    <td><span class="rank ${i<3?['gold','silver','bronze'][i]:''}">${i+1}</span></td>
    <td><strong>${esc(a.name)}</strong><br><span class="muted mono">${esc(a.slug)}</span></td>
    <td>${esc(a.role)}</td><td class="mono">${esc(a.model)}</td>
    <td>${arr(a.tools).length} tools<br><span class="muted">${arr(a.vault_names).length} secrets</span></td>
    <td>${esc(a.schedule || 'manual')}</td>
    <td><button class="btn small" data-edit="${a.id}">Edit</button>
      ${a.slug==='security'||a.slug==='system'||a.slug==='webui'||a.slug==='chat'
        ? '' : `<button class="btn danger small" data-delagent="${a.id}">Delete</button>`}</td></tr>`).join('');
  return `<div class="section-head"><h2>Agent roster</h2><button class="btn primary small" data-newagent>Create agent</button></div>
    <div class="card"><table><thead><tr><th>#</th><th>Agent</th><th>Role / scope</th><th>Model</th><th>Permissions</th><th>Schedule</th><th></th></tr></thead>
    <tbody>${rows}</tbody></table></div>
    <p class="muted" style="font-size:11px">New agents start with only the tools you tick. Privileged tools still need a one-time approval per use; the default four cannot be deleted.</p>`;
}

/* -------------------------------------------------------------- approvals */
function renderApprovals() {
  const esc1 = (S.escalations||[]).map(e => `<tr>
    <td>#${e.id}</td><td>${esc(e.agent_name)}</td><td class="mono">${esc(e.capability)}</td>
    <td>${esc(e.reason||'—')}</td>
    <td><span class="sev ${e.status==='pending'?'high':e.status==='approved'?'low':''}">${esc(e.status)}</span></td>
    <td>${e.status==='pending'
      ? `<button class="btn primary small" data-esc="${e.id}" data-ok="1">Approve</button>
         <button class="btn danger small" data-esc="${e.id}" data-ok="0">Deny</button>` : '—'}</td></tr>`).join('');
  const ap = (S.approval_history||S.approvals||[]).map(a => `<tr><td>#${a.id}</td><td>${esc(a.kind)}</td><td>${esc(a.summary)}</td><td>${esc(a.status)}</td><td>${fmt(a.created_at)}</td></tr>`).join('');
  return `<div class="section-head"><h2>One-time escalations</h2><span class="muted">each use expires</span></div>
    <div class="card"><table><thead><tr><th>ID</th><th>Agent</th><th>Capability</th><th>Reason</th><th>Status</th><th></th></tr></thead>
    <tbody>${esc1 || '<tr><td colspan="6" class="muted">Nothing waiting.</td></tr>'}</tbody></table></div>
    <div class="section-head" style="margin-top:20px"><h2>Recorded actions</h2></div>
    <div class="card"><table><thead><tr><th>ID</th><th>Kind</th><th>Summary</th><th>Status</th><th>When</th></tr></thead>
    <tbody>${ap || '<tr><td colspan="5" class="muted">No recorded actions.</td></tr>'}</tbody></table></div>`;
}

/* --------------------------------------------------------------- security */
function renderSecurity() {
  const rows = (S.findings||[]).map(f => `<tr>
    <td><span class="sev ${esc(f.severity)}">${esc(f.severity)}</span></td>
    <td><strong>${esc(f.title)}</strong><br><span class="muted">${esc(f.source)}</span></td>
    <td class="muted">${esc(f.detail||'')}</td><td>${ago(f.created_at)}</td></tr>`).join('');
  return `<div class="section-head"><h2>Exploit watch</h2>
      <button class="btn primary small" data-scan>Run scan now</button></div>
    <div class="card"><table><thead><tr><th>Severity</th><th>Finding</th><th>Detail</th><th>Found</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="4" class="muted">No open findings — last scan was clean.</td></tr>'}</tbody></table></div>
    <p class="muted" style="font-size:11px">Scans run daily at 03:15 and are read-only. Fixes are proposed by the Exploit Watch agent and never applied without your approval in Approvals.</p>`;
}

/* ------------------------------------------------------------------ vault */
function renderVault() {
  const secrets = S.vault?.secrets || [];
  const rows = secrets.map(s => `<tr>
    <td class="mono">${esc(s.name)}</td><td>${esc(s.label)}</td>
    <td>${esc(JSON.stringify(s.allow||{}))}</td><td>${esc(s.approval)}</td></tr>`).join('');
  const opts = secrets.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
  const noSecrets = secrets.length === 0;
  return `<div class="section-head"><h2>Stored secrets</h2>
    <span class="pill ${S.vault?.available?'on':'off'}">broker ${S.vault?.available?'online':'offline'}</span></div>
    <div class="card"><table><thead><tr><th>Name</th><th>Label</th><th>Allowed use</th><th>Approval</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="4" class="muted">No secrets stored yet.</td></tr>'}</tbody></table></div>
    <div class="section-head" style="margin-top:20px"><h2>Guarantee</h2></div>
    <div class="card"><p>There is no "read" operation — only <span class="mono">list</span> and <span class="mono">use</span>.
    Every use is approved by you at the root-owned broker, the value is injected by the broker, and the
    answer is scrubbed twice before an agent can see it. If an agent tries to print a secret it gets
    <span class="mono">***CENSORED***</span>.</p>
    <p class="muted">Add a secret on the Deck: <span class="mono">sudo /home/deck/vault/vault.py add NAME</span></p></div>
    <div class="section-head" style="margin-top:20px"><h2>Agent secret injection (test)</h2></div>
    <div class="card"><div class="field-grid">
      <label>Secret<select id="v-use-name">${opts}</select></label>
      <label>Kind<select id="v-use-kind"><option>http</option><option>file</option><option>exec</option></select></label>
      <label class="full">Action parameters (JSON, use {{secret}} as placeholder)
        <textarea id="v-use-params" rows="4">{"url":"https://example.com","method":"GET","headers":{"Authorization":"Bearer {{secret}}"}}</textarea></label>
      <label class="full">Purpose <input id="v-use-purpose" placeholder="why this use is needed"></label>
    </div><div class="row" style="margin-top:12px"><button class="btn primary small" data-vault-use ${noSecrets?'disabled':''}>Use secret</button>
      <span class="muted" style="font-size:11px">${noSecrets
        ? 'Add a secret on the Deck first — there is nothing to inject yet.'
        : 'You will still approve at the vault broker.'}</span></div>
    <pre id="v-use-result" class="hidden"></pre></div>`;
}

/* --------------------------------------------------------------- settings */
function renderSettings() {
  const c = S.config || {};
  return `<div class="grid two">
    <div class="card"><h3>AI-editable config</h3>
      <p class="muted">Agents propose JSON patches. Nothing changes until you approve. Raw CSS and HTML are rejected by the schema.</p>
      <label>Path <input id="cfg-path" value="/meta/title" class="mono"></label>
      <label style="margin-top:8px">New value (JSON or plain string)
        <input id="cfg-value" value="${esc(c.meta?.title || 'Hermes Control Deck')}"></label>
      <div class="row" style="margin-top:12px"><button class="btn primary small" id="cfg-propose">Propose change</button>
        <button class="btn small" id="cfg-history">Refresh history</button></div>
      <pre id="cfg-result" class="hidden" style="font-size:11px"></pre>
    </div>
    <div class="card"><h3>Active configuration</h3>
      <pre style="font-size:11px;max-height:260px;overflow:auto">${esc(JSON.stringify({
        revision:c.revision, theme:c.theme, feature_flags:c.feature_flags, prompts:c.prompts, schedule:c.schedule,
      }, null, 2))}</pre>
      <p class="muted" style="font-size:11px">Owners can roll back any earlier revision from the history below.</p>
    </div></div>
    <div class="card" style="margin-top:16px"><h3>Revision history</h3><div id="cfg-history-body">Loading…</div></div>`;
}
async function loadConfigHistory() {
  const box = $('#cfg-history-body'); if (!box) return;
  const r = await api('ui.config.history');
  box.innerHTML = (r.history || []).slice().reverse().map(h => `<div class="selftest-row">
    <span>rev ${esc(h.revision)} · ${esc(h.state)} · ${esc(h.author)} · ${fmt(h.created_at)}</span>
    <span>${h.state === 'active' ? 'active' : ''}
      ${h.state === 'draft' && h.proposal_id ? `<button class="btn small primary" data-approve="${h.proposal_id}">Approve</button> ` : ''}
      ${h.state !== 'active' ? `<button class="btn small" data-rollback="${h.revision}">Roll back</button>` : ''}</span></div>`).join('');
  $$('[data-rollback]').forEach(b => b.onclick = async () => {
    const out = await api('ui.config.rollback', {revision:+b.dataset.rollback});
    toast(out.ok ? 'Rolled back' : (out.error || 'Failed')); await bootstrap();
  });
  $$('[data-approve]').forEach(b => b.onclick = async () => {
    const out = await api('ui.config.approve', {id:+b.dataset.approve});
    toast(out.ok ? 'Applied' : (out.error || 'Failed')); await bootstrap();
  });
}
function bindConfig() {
  if (view !== 'settings') return;
  loadConfigHistory();
  const propose = $('#cfg-propose'); if (!propose) return;
  propose.onclick = async () => {
    const path = $('#cfg-path').value.trim();
    const raw = $('#cfg-value').value;
    let value = raw;
    try { value = JSON.parse(raw); } catch (e) { /* plain string is fine */ }
    const r = await api('ui.config.propose', {patches:[{op:'replace', path, value}]});
    const box = $('#cfg-result'); box.classList.remove('hidden');
    if (!r.ok) { box.textContent = (r.errors || [r.error || 'Rejected']).join('\n'); return; }
    box.textContent = `Proposed revision ${r.revision} (${r.hash.slice(0,12)}…)\n` + (r.diff || []).join('\n');
    toast('Proposal saved — approve it below');
    await loadConfigHistory();
  };
  const hist = $('#cfg-history'); if (hist) hist.onclick = loadConfigHistory;
  // Approve the newest pending draft revision from history when requested.
  $$('#cfg-history-body [data-rollback]').forEach(() => {});
}

/* ------------------------------------------------------------------ audit */
function renderAudit() {
  const rows = (S.audit||[]).map(a => `<tr><td>${fmt(a.created_at)}</td><td>${esc(a.actor)}</td>
    <td>${esc(a.action)}</td><td class="muted">${esc(a.detail||'')}</td></tr>`).join('');
  return `<div class="section-head"><h2>Audit trail</h2><span class="muted">last 200 events</span></div>
    <div class="card"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th></tr></thead>
    <tbody>${rows}</tbody></table></div>`;
}

/* ------------------------------------------------------------------ setup */
function renderSetup() {
  const reqs = (S.setup_requirements || []);
  return `<div class="grid two">
    <div class="card"><h3>Environment</h3><table>
      <tr><td>Panel</td><td class="mono">two-container compose</td></tr>
      <tr><td>Hermes gateway</td><td class="mono">${S.hermes?.available?'hermes:8642 online':'unreachable'}</td></tr>
      <tr><td>Vault broker</td><td class="mono">${S.vault?.available?'online':'offline'}</td></tr>
      <tr><td>Host control</td><td class="mono">${S.host?.available?'online':'offline'}</td></tr>
      <tr><td>Local STT</td><td class="mono">${S.stt?.available?'ready':'not installed'}</td></tr>
      <tr><td>Setup lock</td><td class="mono">${S.setup_complete?'complete':'pending'}</td></tr>
    </table>
    <div class="row" style="margin-top:12px"><button class="btn small" id="setup-check">Re-run checks</button></div></div>
    <div class="card"><h3>Requirements</h3>
      ${reqs.length ? reqs.map(r => `<div class="req ${r.ok?'ok':(r.level==='fail'?'fail':'warn')}">
        <span class="mark">${r.ok?'✓':(r.level==='fail'?'✕':'!')}</span><span>${esc(r.label)}</span></div>`).join('')
        : '<p class="muted">Open the first-run wizard to see requirements.</p>'}
      <p class="muted" style="font-size:11px;margin-top:12px">No systemd units are added. The panel and Hermes run in one Docker Compose file.</p>
    </div></div>`;
}

/* ------------------------------------------------------------ new views */

function renderSearch() {
  return `<div class="view-tabs"><button class="view-tab active" data-kind="">Everything</button>
    <button class="view-tab" data-kind="page">Pages</button><button class="view-tab" data-kind="agent">Agents</button>
    <button class="view-tab" data-kind="session">Sessions</button><button class="view-tab" data-kind="finding">Findings</button>
    <button class="view-tab" data-kind="hermes">Hermes</button></div>
    <div class="card" style="padding:18px"><div class="row" style="margin-bottom:14px">
      <input id="search-q" placeholder="What are you looking for?" style="max-width:420px">
      <button class="btn primary" id="search-go">Search</button></div>
      <div id="search-results" class="search-results"><p class="muted">Type a word or phrase and press Enter.</p></div></div>`;
}
function bindSearch() {
  const go = async () => {
    const q = $('#search-q')?.value.trim(); if (!q) return;
    const kind = $('.view-tab.active')?.dataset.kind || '';
    const r = await api('search.query', {q, kinds: kind ? [kind] : []});
    $('#search-results').innerHTML = (r.results || []).length
      ? r.results.map(x => `<div class="search-result" data-goto="${x.kind === 'page' ? esc(x.ref_id) : 'search'}">
          <div class="kind">${esc(x.kind)}</div><strong>${esc(x.title)}</strong>
          <div class="snippet">${esc(x.snippet)}</div></div>`).join('')
      : '<p class="muted">No matches yet.</p>';
    $$('#search-results [data-goto]').forEach(el => el.onclick = () => { view = el.dataset.goto; renderNav(); render(); });
  };
  const btn = $('#search-go'); if (btn) btn.onclick = go;
  const input = $('#search-q');
  if (input) input.onkeydown = (e) => { if (e.key === 'Enter') go(); };
  $$('.view-tab').forEach(b => b.onclick = () => {
    $$('.view-tab').forEach(x => x.classList.remove('active')); b.classList.add('active');
    if ($('#search-q')?.value.trim()) go();
  });
}

function renderThemes() {
  const presets = S.theme_presets || {};
  const active = (S.config && S.config.theme && S.config.theme.preset) || '';
  return `<p class="muted" style="margin-bottom:16px">Pick a preset. The change is proposed, validated, approved and applied atomically — then saved in config history.</p>
    <div class="theme-grid">${Object.entries(presets).map(([id, p]) => {
      const t = p.tokens || {};
      const bg = t['--subtle'] || (p.mode === 'dark' ? '#101a26' : '#f5f7fa');
      const surface = t['--surface'] || '#fff';
      const accent = t['--accent'] || '#ec8621';
      const ink = t['--ink'] || '#172b40';
      return `<div class="theme-card ${id === active ? 'active' : ''}" data-preset="${esc(id)}">
        <div class="theme-swatch" style="background:${bg}">
          <span class="bubble" style="background:${surface};border:1px solid ${t['--line'] || '#ddd'}"></span>
          <span class="bubble" style="background:${accent}"></span>
          <span class="bubble" style="background:${t['--good'] || '#1a9670'}"></span>
          <span class="bubble" style="background:${ink}"></span></div>
        <div class="label"><span>${esc(p.label || id)}</span><span class="muted">${esc(p.mode)} · ${esc(p.radius)}</span></div></div>`;
    }).join('')}</div>`;
}
function bindThemes() {
  $$('.theme-card').forEach(el => el.onclick = async () => {
    const preset = el.dataset.preset;
    const r = await api('theme.set', {preset});
    if (!r.ok) return toast(r.error || 'Theme change failed');
    toast('Theme applied'); await bootstrap();
  });
}
function bindContent() {
  bindContentBase();
  const rawBtn = $('[data-raw-toggle]');
  if (rawBtn) rawBtn.onclick = () => {
    const pre = $('.raw-data'); if (!pre) return;
    pre.classList.toggle('hidden');
    rawBtn.textContent = pre.classList.contains('hidden') ? 'Show raw data' : 'Hide raw data';
  };
  if (view === 'themes') bindThemes();
  if (view === 'hermes') bindHermes();
  if (view === 'self-test') bindSelfTest();
  bindConfig();
}

function renderVoice() {
  return `<div class="voice-wrap">
    <div class="card" style="padding:22px;text-align:center">
      <h2 style="margin-bottom:6px">Talk to your deck</h2>
      <p class="muted" style="font-size:12px">Push to talk. Whisper.cpp runs locally inside the web container.</p>
      <button class="mic-btn" id="mic-btn" title="Hold to talk">◉</button>
      <div id="voice-state" class="muted" style="font-size:12px">Ready</div>
      <div id="voice-engine" class="muted" style="font-size:11px;margin-top:8px"></div>
    </div>
    <div class="card" style="padding:22px">
      <div class="row" style="justify-content:space-between"><h2>Transcript</h2>
        <button class="btn small" id="voice-clear">Clear</button></div>
      <div id="voice-text" class="transcript" style="margin:14px 0">Nothing transcribed yet.</div>
      <div class="row"><button class="btn primary" id="voice-send">Send to Chat agent</button>
        <span class="muted" style="font-size:11px">Audio is deleted immediately after transcription; transcripts are never logged.</span></div>
    </div></div>`;
}
let mediaRecorder = null, audioChunks = [];
async function bindVoice() {
  const s = await api('stt.status');
  const engine = $('#voice-engine');
  if (engine) engine.textContent = s.available ? `Engine ready · ${(s.models || []).join(', ')}`
    : 'Engine not installed — recording disabled. Add a whisper.cpp model to the webui image to enable.';
  const btn = $('#mic-btn'); if (!btn) return;
  const state = $('#voice-state'), out = $('#voice-text');
  btn.disabled = !s.available;
  const start = async () => {
    if (!navigator.mediaDevices?.getUserMedia) return toast('Microphone unavailable in this browser');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({audio:true});
      audioChunks = []; mediaRecorder = new MediaRecorder(stream);
      mediaRecorder.ondataavailable = e => { if (e.data.size) audioChunks.push(e.data); };
      mediaRecorder.onstop = async () => {
        stream.getTracks().forEach(t => t.stop());
        const blob = new Blob(audioChunks, {type: mediaRecorder.mimeType || 'audio/webm'});
        const fd = new FormData(); fd.append('audio', blob, 'capture.webm');
        if (state) state.textContent = 'Transcribing…';
        const r = await fetch('/api.php?action=stt.transcribe', {
          method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':csrfToken()}, body:fd,
        }).then(x => x.json());
        if (r.ok) { if (out) out.textContent = r.text || '(silence)'; toast(`Transcribed in ${r.duration}s`); }
        else { if (state) state.textContent = r.error || 'Failed'; }
      };
      mediaRecorder.start(); btn.classList.add('recording');
      if (state) state.textContent = 'Recording — release to transcribe';
    } catch (e) { toast('Microphone permission denied'); }
  };
  const stop = () => { if (mediaRecorder && mediaRecorder.state !== 'inactive') { mediaRecorder.stop(); btn.classList.remove('recording'); } };
  btn.onpointerdown = (e) => { e.preventDefault(); start(); };
  btn.onpointerup = stop; btn.onpointerleave = stop;
  $('#voice-clear').onclick = () => { out.textContent = 'Nothing transcribed yet.'; };
  $('#voice-send').onclick = async () => {
    const text = out.textContent.trim(); if (!text || text === 'Nothing transcribed yet.') return;
    const agent = (S.agents || []).find(a => a.slug === 'chat');
    if (!agent) return toast('Chat agent unavailable');
    const r = await api('chat.send', {agent_id:agent.id, message:text});
    if (r.ok) toast('Sent to Chat agent'); else toast(r.error || 'Send failed');
  };
}

function renderSelfTest() {
  return `<div class="card" style="padding:22px">
    <div class="row" style="justify-content:space-between;margin-bottom:16px">
      <div><h2>Daily web UI test</h2><p class="muted" style="font-size:12px">AI checks every page and data source, then reports in plain language. It never fixes anything by itself.</p></div>
      <button class="btn primary" id="selftest-run">Run now</button></div>
    <div id="selftest-report"><p class="muted">Loading last report…</p></div></div>`;
}
async function loadSelfTest() {
  const r = await api('selftest.report');
  const box = $('#selftest-report'); if (!box) return;
  if (!r.run) { box.innerHTML = '<p class="muted">No run yet. Press “Run now”.</p>'; return; }
  const d = r.run.detail || {};
  const checks = d.checks || [];
  box.innerHTML = `<p style="font-size:12px"><strong>Status:</strong> ${esc(r.run.status)} ·
    <strong>When:</strong> ${fmt(r.run.created_at)} · <strong>Pages:</strong> ${esc(d.pages || 0)}</p>
    <div style="max-height:420px;overflow:auto;margin-top:12px">${checks.map(c =>
      `<div class="selftest-row"><span>${esc(c.name)}</span><span class="${c.ok?'ok':'bad'}">${c.ok?'PASS':'FAIL'}</span></div>`).join('')}</div>`;
}
function bindSelfTest() {
  const b = $('#selftest-run'); if (b) b.onclick = async () => {
    b.disabled = true; toast('Running self-test…');
    const r = await api('selftest.run'); b.disabled = false;
    if (r.ok) { toast(r.ok && r.failed?.length === 0 ? 'All checks passed' : 'Some checks failed'); await loadSelfTest(); }
  };
}
function renderHermes() {
  return `<div class="view-tabs"><button class="view-tab active" data-area="all">All areas</button>
    ${HERMES_AREAS.map(a => `<button class="view-tab" data-area="${esc(a[0])}">${esc(a[1])}</button>`).join('')}</div>
    <div id="hermes-body" class="hermes-grid"><p class="muted">Choose an area to load its safe data view.</p></div>`;
}
const HERMES_SOURCE_MAP = Object.fromEntries(HERMES_AREAS.map(a => [a[0], a[2]]));
async function loadHermesArea(area) {
  const box = $('#hermes-body'); if (!box) return;
  if (area === 'all') {
    box.innerHTML = HERMES_AREAS.map(([id, label, source]) => `
      <div class="hermes-tile" data-source="${esc(source)}"><h3>${esc(label)}</h3>
      <span class="meta">${esc(source)}</span><button class="btn small">Load</button></div>`).join('');
    $$('#hermes-body [data-source]').forEach(el => el.onclick = () => {
      view = 'hermes'; render(); setTimeout(() => loadHermesArea(el.dataset.source), 0);
    });
    return;
  }
  const source = HERMES_SOURCE_MAP[area] || area;
  box.innerHTML = '<p class="muted">Loading…</p>';
  const r = await api('hermes.source', {source});
  if (!r.ok) { box.innerHTML = `<div class="card"><p>${esc(r.error || 'Unavailable')}</p>
    <button class="btn" data-goto="overview">Back to overview</button></div>`; bindContent(); return; }
  box.innerHTML = dataView(r.data, source, r.cached);
  const rawBtn = $('[data-raw-toggle]');
  if (rawBtn) rawBtn.onclick = () => {
    const pre = $('.raw-data'); if (!pre) return;
    pre.classList.toggle('hidden');
    rawBtn.textContent = pre.classList.contains('hidden') ? 'Show raw data' : 'Hide raw data';
  };
}
function bindHermes() {
  $$('.view-tab[data-area]').forEach(b => b.onclick = async () => {
    $$('.view-tab').forEach(x => x.classList.remove('active')); b.classList.add('active');
    await loadHermesArea(b.dataset.area);
  });
  if (view === 'hermes') loadHermesArea('all');
}

/* ------------------------------------------------------------ first run */

function renderSetupScreen(st) {
  const steps = st.steps || [];
  const reqs = st.requirements || [];
  return `<div class="setup-stage">
    <div class="row" style="justify-content:space-between"><strong style="letter-spacing:.14em;font-size:12px">HERMES CONTROL DECK</strong>
      <span class="muted" style="font-size:11px">First launch · ${steps.filter(s=>s.done).length}/${steps.length} complete</span></div>
    <div class="setup-steps">${steps.map(s => `<div class="setup-step ${s.done?'done':''}" title="${esc(s.title)}"></div>`).join('')}</div>
    <div class="setup-card animate__animated animate__fadeInUp">
      <h1 style="font-size:30px">Your AI team, one deck.</h1>
      <p class="muted">A few checks, then everything is yours to personalise. Nothing here sends data off the Deck.</p>
      <h3>Requirements</h3>
      ${reqs.map(r => `<div class="req ${r.ok?'ok':(r.level==='fail'?'fail':'warn')}">
        <span class="mark">${r.ok?'✓':(r.level==='fail'?'✕':'!')}</span>
        <span>${esc(r.label)}${r.detail?` — <span class="muted">${esc(r.detail)}</span>`:''}</span></div>`).join('')}
      <div class="row" style="margin-top:10px">
        <button class="btn primary" id="setup-finish" ${reqs.some(r=>!r.ok&&r.level==='fail')?'disabled':''}>Finish setup</button>
        <span class="muted" style="font-size:11px">Warnings are fine — you can enable those features later.</span></div>
    </div></div>
    <div class="setup-chat"><div class="animate__animated animate__fadeIn">
      <div class="pill on">setup assistant</div>
      <h2 style="margin:14px 0 8px">Hi, owner.</h2>
      <p class="muted" style="max-width:420px">I checked the Deck for you. Approve the requirements and I’ll open your control deck. You can rename me, change colours, or add pages any time — just ask in plain language.</p>
      <p class="muted" style="font-size:11px;max-width:420px">Plain language is fine: “make it darker”, “show me what the deck is doing”, “check if anything bad happened overnight”.</p>
    </div></div>`;
}
async function showSetupIfNeeded() {
  const st = await api('setup.status');
  if (!st.ok || st.complete) return false;
  $('#setup-screen').innerHTML = renderSetupScreen(st);
  $('#setup-screen').classList.remove('hidden');
  $('#app').classList.add('hidden');
  const finish = $('#setup-finish');
  if (finish) finish.onclick = async () => {
    const r = await api('setup.complete');
    if (r.ok) { $('#setup-screen').classList.add('hidden'); toast('Setup complete'); await bootstrap(true); }
    else toast(r.error || 'Could not complete setup');
  };
  return true;
}

/* ---------------------------------------------------------------- drawer */
function renderDrawer() {
  const l = L();
  $('#drawer-body').innerHTML = `
    <section><h3>Quick actions</h3>
      <div class="row"><button class="btn primary small" data-newagent>Create agent</button>
      <button class="btn small" data-scan>Run security scan</button>
      <button class="btn small" data-refresh>Refresh data</button></div>
    </section>
    <section><h3>Agent menu</h3>
      ${(S.agents||[]).map(a=>`<button class="nav-item" data-chat="${a.id}"><span class="ico">${esc(a.name[0])}</span><span>${esc(a.name)}</span></button>`).join('')}
    </section>
    <section><h3>Message routing</h3>
      <label>From agent<select id="msg-from">${(S.agents||[]).map(a=>`<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></label>
      <label>To (slug)<input id="msg-to" placeholder="system"></label>
      <label>Message<textarea id="msg-body" rows="2"></textarea></label>
      <button class="btn small" data-sendmsg>Send message</button>
    </section>
    <section><h3>Agent-editable UI</h3>
      <label>Layout key<input id="ui-key" placeholder="welcome"></label>
      <label>Value (JSON)<textarea id="ui-value" rows="2">"Welcome to the deck"</textarea></label>
      <button class="btn small" data-ui-edit>Apply as Web Interface agent</button>
      <p class="muted" style="font-size:11px">Needs a one-time approval in Approvals; the change is recorded.</p>
    </section>
    <section><h3>Model</h3>
      <label>Default model<input id="default-model" value="${esc(l.default_model||'big-pickle')}"></label>
      <button class="btn small" data-set-model>Save default model</button>
    </section>
    <section><h3>Safe use reminder</h3>
      <p class="muted">Agents have no shell. Host changes are allowlisted, signed by the panel and approved by you. Secret values are injected by a root broker and scrubbed from every reply.</p>
    </section>`;
  bindDrawer();
}

/* ------------------------------------------------------------ chat window */
async function openChat(agentId, sessionId = null) {
  chatAgent = (S.agents||[]).find(a => a.id === agentId);
  if (!chatAgent) return;
  chatSession = sessionId;
  $('#chat').classList.remove('hidden');
  $('#drawer').classList.add('hidden'); $('#drawer-backdrop').classList.add('hidden');
  $('#chat-agent').textContent = chatAgent.name;
  await loadChat();
  setTimeout(() => $('#chat-input').focus(), 50);
}
async function loadChat() {
  if (!chatSession) { $('#chat-thread').innerHTML = '<div class="msg system">New session — send a message to begin.</div>';
    $('#chat-session').textContent = 'new session'; renderContext(); return; }
  const s = await fetch('/api.php?action=session.get&id=' + chatSession, {credentials:'same-origin'}).then(x=>x.json());
  if (!s.ok) { toast(s.error||'could not load session'); return; }
  const sess = s.session;
  $('#chat-session').textContent = sess.title + ' · #' + sess.id;
  $('#chat-thread').innerHTML = (sess.messages||[]).map(m => `
    <div class="msg ${esc(m.role)}"><div class="who">${esc(m.role)}</div>${esc(m.content)}</div>`).join('') || '<div class="msg system">Empty session.</div>';
  renderContext(sess);
  const t = $('#chat-thread'); t.scrollTop = t.scrollHeight;
}
function renderContext(sess = null) {
  const a = chatAgent || {};
  $('#chat-context-panel').innerHTML = `<h3>Session context</h3><dl>
    <dt>Agent</dt><dd>${esc(a.name||'')} <span class="mono">${esc(a.slug||'')}</span></dd>
    <dt>Model</dt><dd class="mono">${esc(a.model||'')}</dd>
    <dt>Session</dt><dd class="mono">${sess?('#'+sess.id):'not created yet'}</dd>
    <dt>Schedule</dt><dd>${esc(a.schedule||'manual')}</dd>
    <dt>Tools</dt><dd>${arr(a.tools).map(t=>`<span class="tag">${esc(t)}</span>`).join('')}</dd>
    <dt>Secret grants</dt><dd>${arr(a.vault_names).length?esc(arr(a.vault_names).join(', ')):'none'}</dd>
    <dt>System prompt</dt><dd>${esc(a.system_prompt||'')}</dd>
  </dl>`;
}
function closeChat() { $('#chat').classList.add('hidden'); chatAgent = null; chatSession = null; }

/* ----------------------------------------------------------------- modals */
function agentModal(a = null) {
  const edit = !!a;
  const tools = Object.entries(S.tools || {});
  const chosen = a ? arr(a.tools) : ['chat'];
  const wrap = document.createElement('div');
  wrap.className = 'modal';
  wrap.innerHTML = `<form class="modal-card" id="agent-form">
    <h2>${edit?'Edit agent':'Create agent'}<button type="button" class="icon-btn" data-close-modal>✕</button></h2>
    <div class="field-grid">
      <label>Name<input name="name" value="${esc(a?.name||'')}" required></label>
      <label>Slug<input name="slug" value="${esc(a?.slug||'')}" ${edit?'readonly':''} required></label>
      <label>Role<input name="role" value="${esc(a?.role||'Custom')}"></label>
      <label>Model<input name="model" value="${esc(a?.model||L().default_model||'big-pickle')}"></label>
      <label class="full">Description<textarea name="description" rows="2">${esc(a?.description||'')}</textarea></label>
      <label class="full">System prompt<textarea name="system_prompt" rows="4">${esc(a?.system_prompt||'You are a helpful, concise agent. Never reveal secret values.')}</textarea></label>
      <label>Schedule<input name="schedule" value="${esc(a?.schedule||'')}" placeholder="daily 03:15 or every 30m"></label>
      <label>Secret names (comma separated)<input name="vault_names" value="${esc(arr(a?.vault_names).join(', '))}"></label>
    </div>
    <h3>Tool permissions</h3>
    <div class="check-grid">${tools.map(([k,v])=>`<label class="check"><input type="checkbox" name="tools" value="${k}" ${chosen.includes(k)?'checked':''}>
      <span>${esc(k)}<small>${esc(v.desc)}</small></span></label>`).join('')}</div>
    <div class="row"><label style="flex-direction:row;align-items:center"><input type="checkbox" name="enabled" style="width:auto" ${!a||a.enabled?'checked':''}> Enabled</label>
      <label style="flex-direction:row;align-items:center"><input type="checkbox" name="can_message" style="width:auto" ${!a||a.can_message?'checked':''}> Accepts messages</label></div>
    <div class="row"><button class="btn primary" type="submit">${edit?'Save agent':'Create agent'}</button>
    <button type="button" class="btn ghost" data-close-modal>Cancel</button></div>
    <div class="err" id="agent-err"></div>
  </form>`;
  document.body.appendChild(wrap);
  $$('[data-close-modal]', wrap).forEach(b => b.onclick = () => wrap.remove());
  wrap.onclick = e => { if (e.target === wrap) wrap.remove(); };
  $('#agent-form', wrap).onsubmit = async e => {
    e.preventDefault();
    const f = new FormData(e.target);
    const data = {
      name:f.get('name'), slug:f.get('slug'), role:f.get('role'), model:f.get('model'),
      description:f.get('description'), system_prompt:f.get('system_prompt'),
      schedule:f.get('schedule') || null,
      vault_names:(f.get('vault_names')||'').split(',').map(s=>s.trim()).filter(Boolean),
      tools:f.getAll('tools'), enabled:f.get('enabled')?1:0, can_message:f.get('can_message')?1:0,
    };
    const r = await api(edit?'agent.update':'agent.create', edit?{...data,id:a.id}:data);
    if (!r.ok) { $('#agent-err',wrap).textContent = r.error||'failed'; return; }
    wrap.remove(); toast(edit?'Agent saved':'Agent created'); await bootstrap();
  };
}

/* ----------------------------------------------------------- event wiring */
async function runScan(btn) {
  btn.disabled = true; toast('Scanning the host…');
  const r = await api('security.scan');
  btn.disabled = false;
  if (r.ok) { toast('Scan complete — ' + (r.findings||[]).length + ' finding(s)'); await bootstrap(); }
  else toast(r.error||'scan failed');
}
function bindContentBase() {
  $$('[data-chat]').forEach(b => b.onclick = () => openChat(+b.dataset.chat));
  $$('[data-edit]').forEach(b => b.onclick = () => agentModal((S.agents||[]).find(a=>a.id===+b.dataset.edit)));
  $$('[data-newsess]').forEach(b => b.onclick = () => openChat(+b.dataset.newsess));
  $$('[data-open]').forEach(b => b.onclick = () => openChat(+b.dataset.agent, +b.dataset.open));
  $$('[data-delsess]').forEach(b => b.onclick = async () => {
    if (!confirm('Delete this session?')) return;
    await api('session.delete', {id:+b.dataset.delsess}); toast('Session deleted'); await bootstrap();
  });
  $$('[data-delagent]').forEach(b => b.onclick = async () => {
    if (!confirm('Delete this agent?')) return;
    const r = await api('agent.delete', {id:+b.dataset.delagent});
    if (!r.ok) { toast(r.error); return; } toast('Agent deleted'); await bootstrap();
  });
  $$('[data-esc]').forEach(b => b.onclick = async () => {
    await api('escalation.decide', {id:+b.dataset.esc, approve:b.dataset.ok==='1'});
    toast(b.dataset.ok==='1'?'Approved':'Denied'); await bootstrap();
  });
  $$('[data-scan]').forEach(b => b.onclick = () => runScan(b));
  $$('[data-save-layout]').forEach(b => b.onclick = async () => {
    let value; try { value = JSON.parse($('#layout-json').value); } catch(e) { toast('Invalid JSON'); return; }
    const r = await api('layout.save', {value}); toast(r.ok?'Layout saved':r.error); await bootstrap();
  });
  $$('[data-vault-use]').forEach(b => b.onclick = async () => {
    let params; try { params = JSON.parse($('#v-use-params').value); } catch(e) { toast('Invalid params JSON'); return; }
    const out = await api('vault.test', {name:$('#v-use-name').value, kind:$('#v-use-kind').value,
      params, purpose:$('#v-use-purpose').value});
    const pre = $('#v-use-result'); pre.classList.remove('hidden');
    pre.textContent = JSON.stringify(out, null, 2);
  });
  if ($('[data-newagent]')) $('[data-newagent]').onclick = () => agentModal();
  if ($('[data-newchat]')) $('[data-newchat]').onclick = () => {
    const first = (S.agents||[]).find(a=>a.enabled); if (first) openChat(first.id);
  };
  $$('[data-goto]').forEach(b => b.onclick = () => { view = b.dataset.goto; renderNav(); render(); });
}
function bindDrawer() {
  $$('#drawer-body [data-newagent]').forEach(b => b.onclick = () => agentModal());
  $$('#drawer-body [data-scan]').forEach(b => b.onclick = () => runScan(b));
  $$('[data-sendmsg]').forEach(b => b.onclick = async () => {
    const r = await api('agent.message', {agent_id:+$('#msg-from').value, agent:$('#msg-to').value, body:$('#msg-body').value});
    toast(r.ok?'Message delivered':r.error); $('#msg-body').value='';
  });
  $$('[data-ui-edit]').forEach(b => b.onclick = async () => {
    let value; try { value = JSON.parse($('#ui-value').value); } catch(e) { toast('Value must be valid JSON'); return; }
    const paths = {title:'/meta/title', description:'/meta/description', accent:'/theme/tokens/--accent', mode:'/theme/mode'};
    const path = paths[$('#ui-key').value];
    if (!path) { toast('Use Settings for schema-validated config patches'); return; }
    const r = await api('ui.config.propose', {patches:[{op:'replace', path, value}]});
    toast(r.ok ? `Proposed revision ${r.revision} — approve in Settings`
               : ((r.errors || [])[0] || r.error || 'Rejected'));
    await bootstrap();
  });
  $$('[data-set-model]').forEach(b => b.onclick = async () => {
    const l = L();
    l.default_model = $('#default-model').value.trim() || 'big-pickle';
    const r = await api('layout.save', {value:l}); toast(r.ok?'Default model saved':r.error); await bootstrap();
  });
  $$('[data-refresh]').forEach(b => b.onclick = async () => { await bootstrap(); toast('Refreshed'); });
}

/* ------------------------------------------------------------------ init */
$('#login-form').onsubmit = async e => {
  e.preventDefault();
  const r = await api('login', {username:$('#login-user').value, password:e.target.password.value});
  if (!r.ok) { $('#login-error').textContent = r.error||'Login failed'; return; }
  e.target.password.value = '';
  await bootstrap(true);
};
$('#logout-btn').onclick = async () => { await api('logout'); showLogin(); };
$('#refresh-btn').onclick = async () => { await bootstrap(); toast('Refreshed'); };
$('#voice-btn').onclick = () => { view = 'voice'; renderNav(); render(); };
$('#global-search').onkeydown = (e) => {
  if (e.key !== 'Enter') return;
  const q = e.target.value.trim(); if (!q) return;
  view = 'search'; renderNav(); render();
  const input = $('#search-q'); if (input) { input.value = q; $('#search-go')?.click(); }
};
document.addEventListener('keydown', (e) => {
  if (e.key === '/' && !['INPUT','TEXTAREA'].includes(document.activeElement?.tagName)) {
    e.preventDefault(); $('#global-search')?.focus();
  }
});
$('#menu-btn').onclick = () => { $('#drawer').classList.remove('hidden'); $('#drawer-backdrop').classList.remove('hidden'); };
$('#drawer-close').onclick = () => { $('#drawer').classList.add('hidden'); $('#drawer-backdrop').classList.add('hidden'); };
$('#drawer-backdrop').onclick = () => { $('#drawer').classList.add('hidden'); $('#drawer-backdrop').classList.add('hidden'); };
$('#chat-back').onclick = closeChat; $('#chat-close').onclick = closeChat;
$('#chat-context').onclick = () => $('#chat-context-panel').classList.toggle('hidden');
$('#chat-new').onclick = async () => { chatSession = null; await loadChat(); };
$('#chat-form').onsubmit = async e => {
  e.preventDefault();
  const input = $('#chat-input'), msg = input.value.trim();
  if (!msg || !chatAgent) return;
  input.value = ''; input.disabled = true;
  const r = await api('chat.send', {agent_id:chatAgent.id, session_id:chatSession, message:msg});
  input.disabled = false;
  if (!r.ok) { toast(r.error||'chat failed'); input.value = msg; return; }
  chatSession = r.session_id;
  await loadChat();
  if (r.trace?.length) toast(r.trace.length + ' tool call(s) — see session context');
};
$('#chat-input').onkeydown = e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#chat-form').dispatchEvent(new Event('submit')); }
};

(async () => {
  const me = await api('me');
  if (me.authenticated) {
    const inSetup = await showSetupIfNeeded();
    if (!inSetup) await bootstrap(true);
  } else showLogin();
  setInterval(async () => {
    if ($('#setup-screen').classList.contains('hidden')
        && $('#chat').classList.contains('hidden') && $('#drawer').classList.contains('hidden')
        && !$('#app').classList.contains('hidden') && !hasUnsavedInput()) await bootstrap();
  }, 10000);
})();
