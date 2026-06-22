// KofC AI Agent — shared admin navigation.
// Include on each admin page with: <script src="nav.js"></script>
// Renders a top nav bar, highlights the current page, and links out to the agent app.
(function () {
  var pages = [
    { href: 'index.html',      label: 'Home' },
    { href: 'knowledge.html',  label: 'Knowledge Base' },
    { href: 'supervisor.html', label: 'Supervisor' },
    { href: 'users.html',      label: 'Users' }
  ];

  var current = (location.pathname.split('/').pop() || 'index.html').toLowerCase();

  var css = document.createElement('style');
  css.textContent =
    '.kofc-adminnav{background:#1b2a4a;color:#fff;display:flex;align-items:center;gap:6px;' +
      'padding:0 20px;height:46px;font-family:system-ui,sans-serif;position:sticky;top:0;z-index:2147483000;}' +
    '.kofc-adminnav .brand{font-size:13px;font-weight:600;letter-spacing:.02em;opacity:.85;' +
      'margin-right:14px;white-space:nowrap;color:#fff;text-decoration:none;}' +
    '.kofc-adminnav a.lnk{color:#cdd6e6;text-decoration:none;font-size:13px;padding:6px 12px;border-radius:6px;white-space:nowrap;}' +
    '.kofc-adminnav a.lnk:hover{background:rgba(255,255,255,.10);color:#fff;}' +
    '.kofc-adminnav a.lnk.active{background:#2f5597;color:#fff;}' +
    '.kofc-adminnav a.app{margin-left:auto;color:#cdd6e6;text-decoration:none;font-size:13px;' +
      'padding:6px 12px;border:1px solid rgba(255,255,255,.25);border-radius:6px;white-space:nowrap;}' +
    '.kofc-adminnav a.app:hover{background:rgba(255,255,255,.10);color:#fff;}';

  var nav = document.createElement('nav');
  nav.className = 'kofc-adminnav';
  var html = '<a class="brand" href="index.html">KofC AI Agent &middot; Admin</a>';
  pages.forEach(function (p) {
    var active = (p.href.toLowerCase() === current) ? ' active' : '';
    html += '<a class="lnk' + active + '" href="' + p.href + '">' + p.label + '</a>';
  });
  html += '<a class="app" href="/" title="Open the AI Agent">AI Agent &#8599;</a>';
  nav.innerHTML = html;

  function mount() {
    document.head.appendChild(css);
    document.body.insertBefore(nav, document.body.firstChild);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();