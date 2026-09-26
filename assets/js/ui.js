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
//                      el último («Guardado en tus favoritos» al copiar). Con
//                      un diálogo modal abierto se muestra DENTRO de él.
//   IA.cover(r)        portada generativa; mismo marcado que iarepo_cover()
//                      en shared/ui.php. Usa las etiquetas que ya devuelve la
//                      API (subject_class, source_label, source_mono…).
//   IA.openSend(o)     diálogo «Mandar a mis alumnos» (iarepo_send_dialog()):
//                      QR generado AQUÍ (assets/js/qrcode.js), dirección
//                      corta, copiar y Google Classroom. o = {id, title} para
//                      un recurso (/view/N) o {path, title} para otra página
//                      propia (una lista). o.copiedMsg: aviso al copiar.
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
  // Diálogo modal abierto, si lo hay. ':modal' en navegadores recientes; si no
  // lo entienden (lanza), cualquier <dialog open>.
  function openModal() {
    try { return document.querySelector('dialog:modal'); }
    catch (e) { return document.querySelector('dialog[open]'); }
  }
  IA.toast = function (msg) {
    var el = document.getElementById('ia-toast');
    if (!el) return;
    // Con un <dialog> modal abierto, el resto de la página es inerte y queda
    // POR DEBAJO de él: «Enlace copiado» salía tapado por «Mandar a mis
    // alumnos» (en el móvil no se veía nada) y el lector de pantalla no lo
    // anunciaba. Mientras dure, el aviso vive DENTRO del diálogo; al cerrarse
    // vuelve al <body> [revisión 2026-09].
    var dlg = openModal();
    if (dlg && el.parentNode !== dlg) {
      dlg.appendChild(el);
      dlg.addEventListener('close', function () { document.body.appendChild(el); }, { once: true });
    } else if (!dlg && el.parentNode !== document.body) {
      document.body.appendChild(el);
    }
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
  var THUMB_RE = /^\/thumbnails\/og-\d+\.png(\?v=\d+)?$/;
  IA.cover = function (r, opts) {
    opts = opts || {};
    var subj = SUBJ_RE.test(r.subject_class || '') ? r.subject_class : 's-general';
    var icon = ICON_RE.test(r.category_icon || '') ? r.category_icon : 'sparkles';
    var html = '<div class="ia-cover ' + subj + '" aria-hidden="true">';
    // Captura real (la API la da en r.thumb solo si existe en el servidor).
    if (THUMB_RE.test(r.thumb || '')) html += '<img class="ia-cover-img" src="' + r.thumb + '" alt="" loading="lazy" decoding="async">';
    if (r.source_label) {
      html += '<span class="ia-cover-source"><span class="ia-cover-mono">' + IA.esc(r.source_mono || '') + '</span>'
            + IA.esc(r.source_label) + '</span>';
    }
    html += '<span class="ia-cover-icon"><i data-lucide="' + icon + '"></i></span>';
    // Solo el primer tema (topic_label lo calcula shared/labels.php; si la fila
    // no pasó por iarepo_with_labels, se corta aquí): topic_tag a veces es una
    // lista y la portada pintaba «WAVES,INTRODU…».
    var topic = opts.topic != null ? opts.topic
      : (r.topic_label || String(r.topic_tag || '').split(',')[0].trim() || r.category_label || '');
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
    if (!dlg || !o || (!o.id && !o.path)) return;
    // o.id → el visor limpio de un recurso (/view/N). o.path → otra página
    // propia (p. ej. una lista: '/collection/?id=3'). Solo rutas locales.
    var path = o.path && /^\/[^\/\\]/.test(o.path) ? o.path : '/view/' + parseInt(o.id, 10);
    var url = location.origin + path;
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
      // Primero se cierra el diálogo: un <dialog> modal va en la capa
      // superior y TAPABA el QR grande donde no hay pantalla completa (Safari
      // de iPhone): «Proyectar el código» no hacía nada visible y el QR
      // aparecía solo al cerrar el diálogo [revisión 2026-09].
      if (dlg.open && typeof dlg.close === 'function') dlg.close();
      full.classList.add('is-open');
      var rfs = full.requestFullscreen || full.webkitRequestFullscreen;   // webkit: Safari de iPad antiguo
      if (rfs) {
        try { var p = rfs.call(full); if (p && p.catch) p.catch(function () {}); } catch (e) {}
      }
    };

    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    IA.icons();
  };

  function closeQrFull() {
    var full = document.getElementById('ia-qr-full');
    if (!full || !full.classList.contains('is-open')) return;
    full.classList.remove('is-open');
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
    else if (document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
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
  ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        var full = document.getElementById('ia-qr-full');
        if (full) full.classList.remove('is-open');
      }
    });
  });

  window.IA = IA;
  if (document.readyState !== 'loading') IA.icons();
  else document.addEventListener('DOMContentLoaded', IA.icons);
})();
