// ================================================================
// assets/js/theme.js — Tema claro/oscuro, UNA implementación para todo
//
// Se carga en el <head> (sin defer): aplica el tema ANTES de pintar, así no
// hay parpadeo claro→oscuro. Sustituye a las 17 copias del conmutador que
// había repartidas por las páginas, que además lo aplicaban al final del
// <body> (parpadeo) y quitaban el atributo al elegir claro: con el sistema
// en oscuro, quien había elegido claro veía oscuro.
//
//   · Elección guardada en localStorage 'iarepo-theme' = 'light' | 'dark'
//     (la misma clave que ya usaban las páginas: nadie pierde su elección).
//   · Sin elección: el del sistema (prefers-color-scheme, en app.css).
//   · Cualquier botón con [data-theme-toggle] lo cambia.
// ================================================================
(function () {
  var KEY = 'iarepo-theme';
  var root = document.documentElement;

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function current() {
    var a = root.getAttribute('data-theme');
    if (a === 'dark' || a === 'light') return a;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function paintMeta() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', current() === 'dark' ? '#0D1015' : '#F6F7F9');
  }
  function apply(v) {
    if (v === 'dark' || v === 'light') root.setAttribute('data-theme', v);
    else root.removeAttribute('data-theme');
    paintMeta();
  }
  function toggle() {
    var next = current() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) {}
    apply(next);
  }

  apply(stored());
  document.addEventListener('DOMContentLoaded', paintMeta);
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
    if (b) { e.preventDefault(); toggle(); }
  });
  window.IATheme = { toggle: toggle, current: current };
})();
