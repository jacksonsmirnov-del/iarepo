// ================================================================
// assets/js/ui.js — Comportamiento común de la interfaz (window.IA)
//
// Lo que antes se reescribía en cada página, una vez:
//   IA.esc(s)          escapa para HTML Y para atributos entre comillas
//                      simples o dobles. La versión vieja (textContent →
//                      innerHTML) no escapaba la comilla simple, y un título
//                      con apóstrofo rompía un onclick="f('…')".
//   IA.toast(msg)      aviso breve (#ia-toast, lo imprime iarepo_footer()).
//                      Cada llamada pone SU texto: el aviso viejo reutilizaba
//                      el último («Guardado en tus favoritos» al copiar).
//   IA.cover(r)        portada generativa; mismo marcado que iarepo_cover()
//                      en shared/ui.php. Usa las etiquetas que ya devuelve la
//                      API (subject_class, source_label, source_mono…).
//   IA.openSend(o)     diálogo «Mandar a mis alumnos» (iarepo_send_dialog()):
//                      QR generado AQUÍ (assets/js/qrcode.js), dirección
//                      corta /view/N, copiar y Google Classroom.
//   IA.copy(text)      portapapeles con respaldo para navegadores viejos.
//   IA.icons()         lucide.createIcons() si está cargado.
// Además: menú móvil ([data-menu-toggle]) y cierre de diálogos
// ([data-dialog-close]).
//
// Los textos que ve la persona NO están aquí: los pone la página con t() en
// el HTML (regla §2.3 de CLAUDE.md). Este fichero solo mueve piezas.
// ================================================================
(function () {
  'use strict';

  var IA = window.IA || {};

  IA.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  IA.icons = function () {
    try { if (window.lucide) window.lucide.createIcons(); } catch (e) {}
  };

  var toastTimer = null;
  IA.toast = function (msg) {
    var el = document.getElementById('ia-toast');
    if (!el) return;
    el.textContent = String(msg || '');
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-on'); }, 2600);
  };

  IA.copy = function (text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (ok, ko) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? ok() : ko(); } catch (e) { ko(e); }
      document.body.removeChild(ta);
    });
  };

  var ICON_RE = /^[a-z0-9-]{2,40}$/;
  var SUBJ_RE = /^s-[a-z-]{2,30}$/;
  IA.cover = function (r, opts) {
    opts = opts || {};
    var subj = SUBJ_RE.test(r.subject_class || '') ? r.subject_class : 's-general';
    var icon = ICON_RE.test(r.category_icon || '') ? r.category_icon : 'sparkles';
    var html = '<div class="ia-cover ' + subj + '" aria-hidden="true">';
    if (r.source_label) {
      html += '<span class="ia-cover-source"><span class="ia-cover-mono">' + IA.esc(r.source_mono || '') + '</span>'
            + IA.esc(r.source_label) + '</span>';
    }
    html += '<span class="ia-cover-icon"><i data-lucide="' + icon + '"></i></span>';
    var topic = opts.topic != null ? opts.topic : (r.topic_tag || r.category_label || '');
    if (topic) html += '<span class="ia-cover-topic">' + IA.esc(topic) + '</span>';
    if (opts.badge) html += '<span class="ia-cover-badge">' + IA.esc(opts.badge) + '</span>';
    return html + '</div>';
  };

  // ── Diálogo «Mandar a mis alumnos» ─────────────────────────────
  function qrSvg(text) {
    if (typeof window.qrcode !== 'function') return '';
    var qr = window.qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
  }

  IA.openSend = function (o) {
    var dlg = document.getElementById('ia-send');
    if (!dlg || !o || !o.id) return;
    var url = location.origin + '/view/' + parseInt(o.id, 10);
    var shortUrl = url.replace(/^https?:\/\//, '');
    var q = function (sel) { return dlg.querySelector(sel) || document.querySelector(sel); };

    q('[data-send-name]').textContent = o.title || '';
    q('[data-send-url]').textContent = shortUrl;
    q('[data-send-qr]').innerHTML = qrSvg(url);
    q('[data-send-classroom]').setAttribute('href',
      'https://classroom.google.com/share?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(o.title || ''));

    q('[data-send-copy]').onclick = function () {
      IA.copy(url).then(function () { IA.toast(o.copiedMsg || shortUrl); }, function () { IA.toast(shortUrl); });
    };
    q('[data-send-project]').onclick = function () {
      var full = document.getElementById('ia-qr-full');
      if (!full) return;
      full.querySelector('[data-send-qr-big]').innerHTML = qrSvg(url);
      full.querySelector('[data-send-url-big]').textContent = shortUrl;
      full.classList.add('is-open');
      if (full.requestFullscreen) full.requestFullscreen().catch(function () {});
    };

    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    IA.icons();
  };

  function closeQrFull() {
    var full = document.getElementById('ia-qr-full');
    if (!full || !full.classList.contains('is-open')) return;
    full.classList.remove('is-open');
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
  }

  // ── Delegación de eventos comunes ──────────────────────────────
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target : null;
    if (!t) return;

    var menu = t.closest('[data-menu-toggle]');
    if (menu) {
      var nav = document.getElementById(menu.getAttribute('aria-controls'));
      var open = menu.getAttribute('aria-expanded') !== 'true';
      menu.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (nav) nav.classList.toggle('is-open', open);
      return;
    }
    var close = t.closest('[data-dialog-close]');
    if (close) {
      var d = close.closest('dialog');
      if (d && d.close) d.close(); else if (d) d.removeAttribute('open');
      return;
    }
    if (t.closest('#ia-qr-full')) { closeQrFull(); return; }

    // Clic en el fondo de un diálogo modal = cerrar.
    if (t.tagName === 'DIALOG' && t.classList.contains('ia-dialog') && t.close) t.close();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeQrFull();
  });
  document.addEventListener('fullscreenchange', function () {
    if (!document.fullscreenElement) {
      var full = document.getElementById('ia-qr-full');
      if (full) full.classList.remove('is-open');
    }
  });

  window.IA = IA;
  if (document.readyState !== 'loading') IA.icons();
  else document.addEventListener('DOMContentLoaded', IA.icons);
})();
