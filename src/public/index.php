<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/setup.php';
Auth::ensureOwner();

$config = ConfigGovernance::active();
$theme = $config['theme'] ?? [];
$preset = Theme::presets()[$theme['preset'] ?? ''] ?? Theme::presets()['brezel-light-rounded'];
$mode = $theme['mode'] ?? $preset['mode'];
$radius = $theme['radius'] ?? $preset['radius'];
if (empty($_COOKIE['panel_csrf'])) {
    setcookie('panel_csrf', bin2hex(random_bytes(24)), [
        'expires' => time() + 12 * 3600, 'path' => '/', 'httponly' => false,
        'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($config['meta']['locale'] ?? 'en') ?>"
      data-theme="<?= htmlspecialchars($mode) ?>" data-radius="<?= htmlspecialchars($radius) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="<?= $mode === 'dark' ? 'dark' : 'light' ?>">
<title><?= htmlspecialchars($config['meta']['title'] ?? 'Hermes Control Deck') ?></title>
<link rel="stylesheet" href="/assets/vendor/animate.min.css">
<link rel="stylesheet" href="/tailwind.css">
<link rel="stylesheet" href="/app.css">
</head>
<body>

<div id="login" class="login-wrap hidden">
  <form id="login-form" class="login-card" autocomplete="on">
    <div class="login-mark">⬡</div>
    <h1>Hermes Control Deck</h1>
    <p class="muted">Owner access. Agents never see this page.</p>
    <label>Username<input name="username" id="login-user" value="admin" autocomplete="username" required></label>
    <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn primary wide" type="submit">Unlock</button>
    <div id="login-error" class="err"></div>
  </form>
</div>

<div id="setup-screen" class="setup-shell hidden"></div>

<div id="app" class="app hidden">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark">⬡</span><span class="brand-name" id="brand-name">HERMES</span></div>
    <nav id="nav" class="nav"></nav>
    <div class="sidebar-foot">
      <div class="owner-chip"><span class="dot"></span><span id="owner-name">owner</span></div>
      <button class="btn ghost small" id="logout-btn">Lock</button>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button class="icon-btn" id="menu-btn" title="Full menu">☰</button>
      <div class="title-wrap"><h1 id="view-title">Overview</h1><span class="subtitle" id="view-sub"></span></div>
      <div class="searchbox"><span class="mag">⌕</span><input id="global-search" placeholder="Search everything…  (/)" autocomplete="off"></div>
      <div class="top-pills" id="pills"></div>
      <button class="icon-btn" id="chat-dock-btn" title="Open chat panel">✎</button>
      <button class="icon-btn" id="refresh-btn" title="Refresh">⟳</button>
    </header>
    <section id="content" class="content"></section>
  </main>

  <div id="drawer-backdrop" class="backdrop hidden"></div>
  <aside id="drawer" class="drawer hidden">
    <div class="drawer-head"><h2>Full menu</h2><button class="icon-btn" id="drawer-close">✕</button></div>
    <div id="drawer-body" class="drawer-body"></div>
  </aside>

  <div id="chat" class="chat-overlay hidden">
    <div class="chat-head">
      <button class="icon-btn" id="chat-back">←</button>
      <div><strong id="chat-agent">Agent</strong><span class="muted" id="chat-session"></span></div>
      <div class="chat-head-actions">
        <button class="btn ghost small" id="chat-new">New chat</button>
        <button class="btn ghost small" id="chat-context">Context</button>
        <button class="btn ghost small" id="chat-full" title="Toggle fullscreen chat">Fullscreen ⛶</button>
        <button class="icon-btn" id="chat-close" title="Close chat">✕</button>
      </div>
    </div>
    <div class="chat-main">
      <div class="chat-thread" id="chat-thread"></div>
      <aside class="chat-context hidden" id="chat-context-panel"></aside>
    </div>
    <form class="chat-compose" id="chat-form">
      <button class="mic-compose" id="chat-mic" type="button" title="Hold to talk">◉</button>
      <textarea id="chat-input" rows="2" placeholder="Message this agent… (plain language is fine)"></textarea>
      <button class="btn primary" type="submit">Send</button>
    </form>
    <div id="chat-mic-hint" class="mic-hint hidden">Listening… release to add the transcript</div>
  </div>

  <div id="toast" class="toast hidden"></div>
</div>

<script>window.API_METHODS = <?= json_encode(array_map(static fn(array $a): string => $a['method'], PanelActions::catalog()), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/app.js"></script>
</body>
</html>
