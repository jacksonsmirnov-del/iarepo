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
  // ── QR con el logo de iarepo en el centro ─────────────────────
  // Un QR compartido en un grupo de WhatsApp o en un estado lleva la marca.
  // Nivel de corrección H: el código se sigue leyendo aunque falte ~30 % de
  // sus datos. El logo, con su margen blanco, tapa un cuadrado de ~24 % del
  // lado (≈ 6 % de la superficie), muy por debajo. Verificado decodificando
  // capturas de pantalla y la imagen descargable con un lector real (jsQR).
  // tests/unit/labels_test.php comprueba que el hueco no crece.
  var QR_LOGO_RATIO = 0.24;
  var QR_MARGIN = 2;       // módulos de zona blanca (el contenedor añade más)
  var QR_INK = '#11141A';
  var qrSeq = 0;

  function qrModel(text) {
    var qr = window.qrcode(0, 'H');
    qr.addData(text);
    qr.make();
    return qr;
  }

  // Hueco del logo en módulos: lado con la misma paridad que el QR, para
  // quedar centrado en la rejilla y tapar módulos enteros, nunca medios.
  function qrHole(n) {
    var box = Math.ceil(n * QR_LOGO_RATIO);
    if (box % 2 !== n % 2) box++;
    return { box: box, at: (n - box) / 2 };
  }

  // Geometría de assets/img/logo-icon.svg (viewBox 0 0 64 64). Si cambia el
  // logo, cambia aquí también: lo dibujan igual el SVG y la imagen (canvas).
  var LOGO_FROM = '#7c3aed', LOGO_TO = '#06b6d4';
  function logoSvg(x, y, s, gid) {
    var k = s / 64;
    return '<g transform="translate(' + x + ' ' + y + ') scale(' + k + ')">'
      + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">'
      + '<stop offset="0" stop-color="' + LOGO_FROM + '"/><stop offset="1" stop-color="' + LOGO_TO + '"/></linearGradient></defs>'
      + '<rect width="64" height="64" rx="14" fill="url(#' + gid + ')"/>'
      + '<line x1="32" y1="22" x2="19" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-opacity=".7"/>'
      + '<line x1="32" y1="22" x2="45" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-opacity=".7"/>'
      + '<circle cx="19" cy="11" r="3.5" fill="#fff" fill-opacity=".72"/><circle cx="45" cy="11" r="3.5" fill="#fff" fill-opacity=".72"/>'
      + '<circle cx="32" cy="22" r="5.5" fill="#fff"/><rect x="27" y="31" width="10" height="22" rx="5" fill="#fff"/></g>';
  }
  function roundRect(g, x, y, w, h, r) {
    g.beginPath();
    g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
    g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
  }
  function logoCanvas(g, x, y, s) {
    var k = s / 64;
    g.save(); g.translate(x, y); g.scale(k, k);
    var grad = g.createLinearGradient(0, 0, 64, 64);
    grad.addColorStop(0, LOGO_FROM); grad.addColorStop(1, LOGO_TO);
    g.fillStyle = grad; roundRect(g, 0, 0, 64, 64, 14); g.fill();
    g.strokeStyle = 'rgba(255,255,255,.7)'; g.lineWidth = 2; g.lineCap = 'round';
    g.beginPath(); g.moveTo(32, 22); g.lineTo(19, 12); g.moveTo(32, 22); g.lineTo(45, 12); g.stroke();
    g.fillStyle = 'rgba(255,255,255,.72)';
    g.beginPath(); g.arc(19, 11, 3.5, 0, 7); g.fill(); g.beginPath(); g.arc(45, 11, 3.5, 0, 7); g.fill();
    g.fillStyle = '#fff'; g.beginPath(); g.arc(32, 22, 5.5, 0, 7); g.fill();
    roundRect(g, 27, 31, 10, 22, 5); g.fill();
    g.restore();
  }

  function qrSvg(text) {
    if (typeof window.qrcode !== 'function') return '';
    var qr = qrModel(text), n = qr.getModuleCount(), m = QR_MARGIN, size = n + 2 * m;
    var hole = qrHole(n), d = '';
    for (var r = 0; r < n; r++)
      for (var c = 0; c < n; c++)
        if (qr.isDark(r, c)) d += 'M' + (c + m) + ' ' + (r + m) + 'h1v1h-1z';
    var bx = hole.at + m, pad = 0.6;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" data-qr-hole="' + hole.box + '/' + n + '">'
      + '<rect width="' + size + '" height="' + size + '" fill="#fff"/>'
      + '<path d="' + d + '" fill="' + QR_INK + '"/>'
      + '<rect x="' + bx + '" y="' + bx + '" width="' + hole.box + '" height="' + hole.box + '" rx="' + (hole.box * 0.22) + '" fill="#fff"/>'
      + '<g shape-rendering="geometricPrecision">' + logoSvg(bx + pad, bx + pad, hole.box - 2 * pad, 'iaqr' + (++qrSeq)) + '</g>'
      + '</svg>';
  }

  // Imagen para compartir (1080×1350, formato vertical de estados y chats):
  // marca, título, instrucción, QR con logo, dirección corta y pie. Los textos
  // los pone la página con t() en data-* del diálogo (iarepo_send_dialog).
  var FONT = 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif';
  function wrapLines(g, text, maxW, maxLines) {
    var words = String(text || '').split(/\s+/), lines = [], line = '';
    for (var i = 0; i < words.length; i++) {
      var probe = line ? line + ' ' + words[i] : words[i];
      if (g.measureText(probe).width > maxW && line) { lines.push(line); line = words[i]; }
      else line = probe;
    }
    if (line) lines.push(line);
    if (lines.length > maxLines) { lines = lines.slice(0, maxLines); lines[maxLines - 1] = lines[maxLines - 1].replace(/\s*\S*$/, '') + '…'; }
    return lines;
  }
  // Escribe una línea centrada reduciendo el cuerpo hasta que quepa.
  function fitText(g, text, cx, y, maxW, weight, size, family) {
    do { g.font = weight + ' ' + size + 'px ' + family; size -= 2; }
    while (size > 18 && g.measureText(text).width > maxW);
    g.fillText(text, cx, y);
  }
  function qrCard(text, title, shortUrl, t) {
    var W = 1080, H = 1350, X = 90, cv = document.createElement('canvas');
    cv.width = W; cv.height = H;
    var g = cv.getContext('2d');
    g.fillStyle = '#ffffff'; g.fillRect(0, 0, W, H);
    g.textBaseline = 'alphabetic';

    logoCanvas(g, X, 80, 84);
    g.fillStyle = QR_INK; g.font = '800 58px ' + FONT; g.fillText('iarepo', X + 104, 142);

    g.font = '800 62px ' + FONT;
    var y = 262, lines = wrapLines(g, title, W - 2 * X, 3);
    for (var i = 0; i < lines.length; i++) { g.fillText(lines[i], X, y); y += 74; }
    g.fillStyle = '#444C5A'; g.font = '500 36px ' + FONT;
    g.fillText(t.scan || '', X, y + 6);

    var qr = qrModel(text), n = qr.getModuleCount(), m = QR_MARGIN, size = n + 2 * m;
    var side = Math.min(660, H - (y + 60) - 210), cell = Math.floor(side / size), qs = cell * size;
    var qx = Math.round((W - qs) / 2), qy = y + 60;
    g.fillStyle = '#fff'; g.fillRect(qx, qy, qs, qs);
    g.fillStyle = QR_INK;
    for (var r = 0; r < n; r++)
      for (var c = 0; c < n; c++)
        if (qr.isDark(r, c)) g.fillRect(qx + (c + m) * cell, qy + (r + m) * cell, cell, cell);
    var hole = qrHole(n), hx = qx + (hole.at + m) * cell, hs = hole.box * cell;
    g.fillStyle = '#fff'; roundRect(g, hx, hx - qx + qy, hs, hs, hs * 0.22); g.fill();
    var pad = Math.round(cell * 0.6);
    logoCanvas(g, hx + pad, hx - qx + qy + pad, hs - 2 * pad);

    g.textAlign = 'center';
    g.fillStyle = QR_INK;
    fitText(g, shortUrl, W / 2, qy + qs + 70, W - 2 * X, '700', 44, 'ui-monospace, Menlo, Consolas, monospace');
    // Pie en dos líneas: la frase (de la página, con t()) y el dominio, que
    // pone el navegador (location.host) y es lo que se quiere que se recuerde.
    g.fillStyle = '#444C5A';
    fitText(g, t.footer || '', W / 2, H - 112, W - 2 * X, '600', 34, FONT);
    g.fillStyle = '#6D28D9';
    fitText(g, location.host, W / 2, H - 62, W - 2 * X, '800', 40, FONT);
    return cv;
  }
  IA._qr = { svg: qrSvg, hole: qrHole, ratio: QR_LOGO_RATIO };   // para tests y QA

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

    q('[data-send-image]').onclick = function () {
      var t = { scan: dlg.getAttribute('data-img-scan'), footer: dlg.getAttribute('data-img-footer') };
      var cv = qrCard(url, o.title || '', shortUrl, t);
      var name = 'iarepo-' + path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.png';
      cv.toBlob(function (blob) {
        if (!blob) return;
        var file = typeof File === 'function' ? new File([blob], name, { type: 'image/png' }) : null;
        // En el móvil, la hoja de compartir del sistema (WhatsApp, estados…)
        // con la imagen y el enlace; en el ordenador, se descarga.
        if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
          navigator.share({ files: [file], title: o.title || 'iarepo', text: url }).catch(function () {});
          return;
        }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = name;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
        IA.toast(dlg.getAttribute('data-img-saved') || name);
      }, 'image/png');
    };

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
