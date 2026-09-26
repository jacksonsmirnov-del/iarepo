// ================================================================
// pwa.js — iarepo PWA helper
//   1. Registers the service worker
//   2. Shows an "Install app" button when the browser allows install
//   3. Applies a guest's pending «Guardar» once they come back signed in
//
// Se carga con shared/ui.php::iarepo_pwa_script(), que pasa los textos YA
// traducidos en data-* (un .js estático no puede llamar a t()). El
// theme-color ya no se toca aquí: lo lleva assets/js/theme.js, y los dos
// pintaban colores distintos (parpadeo al cargar).
// ================================================================
// document.currentScript solo vale mientras el script se ejecuta: se lee YA.
var IAREPO_PWA_TXT = (document.currentScript && document.currentScript.dataset) || {};

(function () {
  var TXT = IAREPO_PWA_TXT;

  // 1) service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () { /* non-fatal */ });
    });
  }

  // 2) install button (Chrome/Edge/Android — fires beforeinstallprompt)
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
    // Con nodos, no innerHTML: el texto llega de un atributo y no se interpreta.
    var x = document.createElement('span');
    x.className = 'pwa-x';
    x.title = TXT.hide || 'Ocultar';
    x.textContent = '✕';
    btn.appendChild(document.createTextNode('⬇️ ' + (TXT.install || 'Instalar app') + ' '));
    btn.appendChild(x);
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

// ================================================================
// Pending favorite: un invitado pulsó ⭐ antes de registrarse. La intención
// quedó en localStorage (sobrevive el redirect de Google, a diferencia del
// query/cookie). Al cargar cualquier página ya con sesión, se aplica el
// favorito (idempotente) y, si hay a dónde, se vuelve al recurso.
// ================================================================
(function () {
  var KEY = 'iarepo_pending_fav';
  var raw;
  try { raw = localStorage.getItem(KEY); } catch (e) { return; }
  if (!raw) return;

  var p;
  try { p = JSON.parse(raw); } catch (e) { p = { id: raw }; }
  var id = parseInt(p && p.id, 10);
  if (!id) { try { localStorage.removeItem(KEY); } catch (e) {} return; }

  fetch('/api/favorites.php?id=' + id + '&action=add', { method: 'POST' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.ok) return; // aún sin sesión (401) → se conserva para después del login
      try { localStorage.removeItem(KEY); } catch (e) {}

      // ¿Volver al recurso donde estaba? Solo rutas locales y si no estamos ya ahí.
      var ret = p && p.ret;
      if (ret && /^\/[^\/]/.test(ret) && ret.split('?')[0] !== location.pathname) {
        location.href = ret;
        return;
      }

      // Ya estamos en el sitio: refleja el ⭐ y avisa.
      var sel = '#favBtn[data-id="' + id + '"], .fav-btn[data-id="' + id + '"], .fav-corner[data-fid="' + id + '"]';
      document.querySelectorAll(sel).forEach(function (b) {
        b.classList.add('is-fav');
        b.setAttribute('aria-pressed', 'true');
      });
      var msg = IAREPO_PWA_TXT.saved || 'Guardado. Solo tú lo ves, en Guardados.';
      // El aviso común (#ia-toast de shared/ui.php) si la página lo tiene.
      if (window.IA && window.IA.toast && document.getElementById('ia-toast')) {
        window.IA.toast(msg);
        return;
      }
      var t = document.createElement('div');
      t.textContent = msg;
      t.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);background:#1e293b;color:#fff;padding:11px 20px;border-radius:10px;font:600 .88rem/1 -apple-system,"Segoe UI",Roboto,sans-serif;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.3)';
      document.body && document.body.appendChild(t);
      setTimeout(function () { t.remove(); }, 2600);
    })
    .catch(function () {});
})();
