// KofC Advisor — shared admin navigation.
// Include on each admin page with: <script src="nav.js"></script>
// Renders a top nav bar and highlights the current page.
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
    '.kofc-adminnav .brand{font-size:13px;font-weight:600;letter-spacing:.02em;opacity:.85;margin-right:14px;white-space:nowrap;}' +
    '.kofc-adminnav a{color:#cdd6e6;text-decoration:none;font-size:13px;padding:6px 12px;border-radius:6px;white-space:nowrap;}' +
    '.kofc-adminnav a:hover{background:rgba(255,255,255,.10);color:#fff;}' +
    '.kofc-adminnav a.active{background:#2f5597;color:#fff;}';

  var nav = document.createElement('nav');
  nav.className = 'kofc-adminnav';
  var html = '<a class="brand" href="index.html" style="color:#fff;padding:0;">KofC Advisor &middot; Admin</a>';
  pages.forEach(function (p) {
    var active = (p.href.toLowerCase() === current) ? ' active' : '';
    html += '<a class="' + active.trim() + '" href="' + p.href + '">' + p.label + '</a>';
  });
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