// ================================================================
// pwa.js — iarepo PWA helper
//   1. Matches the browser UI bar (theme-color) to the active theme
//   2. Registers the service worker
//   3. Shows an "Instalar app" button when the browser allows install
// ================================================================
(function () {
  // 1) theme-color → match light/dark theme stored by the app
  try {
    var dark = localStorage.getItem('iarepo-theme') === 'dark';
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#0a0e1a' : '#7c3aed');
  } catch (e) {}

  // 2) service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () { /* non-fatal */ });
    });
  }

  // 3) install button (Chrome/Edge/Android — fires beforeinstallprompt)
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                  || window.navigator.standalone === true;
  if (isStandalone) return;

  var deferred = null;

  function showButton() {
    if (document.getElementById('pwa-install-btn')) return;
    try { if (localStorage.getItem('iarepo-install-dismissed') === '1') return; } catch (e) {}

    var style = document.createElement('style');
    style.textContent =
      '#pwa-install-btn{position:fixed;left:16px;bottom:16px;z-index:999;display:flex;align-items:center;gap:8px;' +
      'padding:10px 16px;border:none;border-radius:100px;cursor:pointer;color:#fff;' +
      'font:600 .85rem/1 -apple-system,"Segoe UI",Roboto,sans-serif;' +
      'background:linear-gradient(135deg,#7c3aed,#06b6d4);box-shadow:0 6px 20px rgba(124,58,237,.35);' +
      'transition:transform .2s}' +
      '#pwa-install-btn:hover{transform:translateY(-2px)}' +
      '#pwa-install-btn .pwa-x{opacity:.65;font-weight:400;padding:0 2px}' +
      '#pwa-install-btn .pwa-x:hover{opacity:1}';
    document.head.appendChild(style);

    var btn = document.createElement('button');
    btn.id = 'pwa-install-btn';
    btn.type = 'button';
    btn.innerHTML = '⬇️ Instalar app <span class="pwa-x" title="Ocultar">✕</span>';
    btn.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('pwa-x')) {
        btn.remove();
        try { localStorage.setItem('iarepo-install-dismissed', '1'); } catch (err) {}
        return;
      }
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; btn.remove(); });
    });
    document.body.appendChild(btn);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (document.body) showButton();
    else window.addEventListener('DOMContentLoaded', showButton);
  });

  window.addEventListener('appinstalled', function () {
    var btn = document.getElementById('pwa-install-btn');
    if (btn) btn.remove();
    deferred = null;
  });
})();
