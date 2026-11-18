'use strict';
// Hermes Control Deck - single-page UI. No framework, no build step, no CDN.
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

let S = {};
let view = 'overview';
let chatAgent = null, chatSession = null;
let customStyle = null;

const NAV = [
  {id:'overview', ico:'◈', label:'Overview'},
  {id:'sessions', ico:'▤', label:'Sessions'},
  {id:'agents',    ico:'⬡', label:'Agents'},
  {id:'approvals', ico:'✓', label:'Approvals'},
  {id:'security', ico:'⚠', label:'Security'},
  {id:'vault',     ico:'⚿', label:'Vault'},
  {id:'settings', ico:'⚙', label:'Settings'},
  {id:'audit',     ico:'≡', label:'Audit'},
  {id:'setup',     ico:'⬇', label:'Setup'},
];
const TITLES = {
  overview:['Overview','All agents, sessions and live activity'],
  sessions:['Sessions','Full context and chat for every agent session'],
  agents:['Agents','Permissions, ranks and per-agent scopes'],
  approvals:['Approvals','One-time escalations and recorded actions'],
  security:['Security','Daily exploit watch and proposed fixes'],
  vault:['Vault','Names and policies only — values never reach an agent'],
  settings:['Settings','Layout the agents can edit, and owner preferences'],
  audit:['Audit','Every decision and privileged call'],
  setup:['Setup','Endpoints, brokers and operator commands'],
};

async function api(action, data = {}) {
  const r = await fetch('/api.php?action=' + encodeURIComponent(action), {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
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
  $('#brand-name').textContent = (l.title || 'HERMES').toUpperCase();
  const accent = l.accent && l.accent.toLowerCase() !== '#4dd0e1' ? l.accent : '#ed8d30';
  document.documentElement.style.setProperty('--accent', accent);
  document.documentElement.style.setProperty('--accent-dark', accent === '#ed8d30' ? '#c96f14' : accent);
  if (!customStyle) { customStyle = document.createElement('style'); document.head.appendChild(customStyle); }
  customStyle.textContent = l.custom_css || '';
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

function renderNav() {
  const counts = {approvals: pendingCount(), security: findingCount()};
  $('#nav').innerHTML = NAV.map(n => `
    <button class="nav-item ${view===n.id?'active':''}" data-view="${n.id}">
      <span class="ico">${n.ico}</span><span>${n.label}</span>
      ${counts[n.id] ? `<span class="nav-badge">${counts[n.id]}</span>` : ''}
    </button>`).join('');
  $$('.nav-item').forEach(b => b.onclick = () => { view = b.dataset.view; renderNav(); render(); });
}
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
    settings:renderSettings, audit:renderAudit, setup:renderSetup}[view];
  $('#content').innerHTML = fn ? fn() : '';
  bindContent();
}

/* ---------------------------------------------------------------- overview */
function renderOverview() {
  const agents = sortedAgents();
  const openSessions = (S.sessions||[]).length;
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
  return hero + kpi + `<div class="section-head"><h2>Agent ranks</h2><span class="muted">ordered by layout</span></div>
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
  const ap = (S.approvals||[]).map(a => `<tr><td>#${a.id}</td><td>${esc(a.kind)}</td><td>${esc(a.summary)}</td><td>${esc(a.status)}</td><td>${fmt(a.created_at)}</td></tr>`).join('');
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
  const rows = (S.vault?.secrets||[]).map(s => `<tr>
    <td class="mono">${esc(s.name)}</td><td>${esc(s.label)}</td>
    <td>${esc(JSON.stringify(s.allow||{}))}</td><td>${esc(s.approval)}</td></tr>`).join('');
  const opts = (S.vault?.secrets||[]).map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
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
    </div><div class="row" style="margin-top:12px"><button class="btn primary small" data-vault-use>Use secret</button>
      <span class="muted" style="font-size:11px">You will still approve at the vault broker.</span></div>
    <pre id="v-use-result" class="hidden"></pre></div>`;
}

/* --------------------------------------------------------------- settings */
function renderSettings() {
  const l = L();
  const order = (l.card_order||[]).join(', ');
  return `<div class="grid two">
    <div class="card"><h3>Layout (agent-editable)</h3><p class="muted">The Web Interface agent can change these keys with your approval. Owner saves here.</p>
      <textarea id="layout-json" rows="16">${esc(JSON.stringify(l, null, 2))}</textarea>
      <div class="row" style="margin-top:10px"><button class="btn primary small" data-save-layout>Save layout</button>
      <span class="muted" style="font-size:11px">keys: title, accent, default_model, card_order, welcome, custom_css</span></div></div>
    <div class="card"><h3>Current values</h3>
      <dl class="mono" style="font-size:11px">
        <dt>title</dt><dd>${esc(l.title||'HERMES')}</dd>
        <dt>accent</dt><dd>${esc(l.accent||'#4dd0e1 (default)')}</dd>
        <dt>default_model</dt><dd>${esc(l.default_model||'big-pickle (default)')}</dd>
        <dt>card_order</dt><dd>${esc(order||'agent ids')}</dd>
        <dt>custom_css</dt><dd>${l.custom_css?(l.custom_css.length+' characters'):'none'}</dd>
      </dl>
      <p class="muted" style="font-size:11px;margin-top:14px">Agents edit one key at a time via <span class="mono">webui_edit</span>; every change is recorded in Approvals and Audit.</p>
    </div></div>`;
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
  return `<div class="grid two">
    <div class="card"><h3>Endpoints</h3><table>
      <tr><td>Panel</td><td class="mono">this page</td></tr>
      <tr><td>Vault broker</td><td class="mono">${S.vault?.available?'/run/vault-broker.sock online':'offline'}</td></tr>
      <tr><td>Host control</td><td class="mono">${S.host?.available?'/run/panel-host.sock online':'offline'}</td></tr>
      <tr><td>Model source</td><td class="mono">local OpenCode API (host.docker.internal:4097)</td></tr>
    </table></div>
    <div class="card"><h3>Operator commands (on the Deck)</h3><pre>sudo systemctl status panel-hostctl
sudo /opt/hermes/hermesctl.sh status
sudo /home/deck/vault/vault.py list
docker compose -f /opt/hermes-control-panel/docker-compose.yml ps</pre></div>
  </div>`;
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
function bindContent() {
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
    const webui = (S.agents||[]).find(a=>a.slug==='webui');
    if (!webui) { toast('Web Interface agent not found'); return; }
    // Record the edit through the owner API; the agent applies it on its next turn.
    const r = await api('layout.save', {value, key:$('#ui-key').value});
    toast(r.ok?'Queued — approve in Approvals':r.error); await bootstrap();
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
  if (me.authenticated) await bootstrap(true); else showLogin();
  setInterval(async () => {
    if ($('#chat').classList.contains('hidden') && $('#drawer').classList.contains('hidden')) await bootstrap();
  }, 10000);
})();
