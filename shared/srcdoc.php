<?php
// ================================================================
// shared/srcdoc.php — El HTML de un recurso, listo para un iframe srcdoc
//
// Los recursos 'html' y 'embed' se pintan con <iframe srcdoc="…">: en la
// ficha, en el visor (el que incrusta Campus), en la vista previa del editor
// y en la de admin. TODOS pasan por iarepo_srcdoc(): no se escribe
// srcdoc="<?= h($code) ?…" a mano en ningún sitio más.
//
// ── POR QUÉ ────────────────────────────────────────────────────
// Un documento srcdoc no tiene URL propia: resuelve sus enlaces contra la de
// la página que lo contiene. Un índice interno <a href="#ecuaciones"> apunta
// entonces a /resource/610#ecuaciones, y al pulsarlo el iframe NAVEGA ahí:
// la ficha de iarepo se carga dentro de sí misma y el recurso desaparece
// (2026-09-27, «Cuadernillo de datos Física»). Lo mismo con href="#" y con
// un enlace relativo (href="tema2.html"), que carga una página de iarepo.
// Pasa con cualquier HTML hecho por una IA que tenga un índice, así que se
// arregla aquí y no pidiendo otra cosa en el prompt.
//
// El arreglo es un script mínimo que se AÑADE al final del HTML del autor
// (al final: delante del <!doctype> pondría la página en modo quirks):
//   · href="#id"  → desplaza hasta #id (o [name=id]) dentro del recurso;
//   · href="#"    → vuelve arriba;
//   · relativo    → no hace nada (en un srcdoc no hay otros ficheros: solo
//                   podía cargar iarepo dentro del iframe);
//   · absoluto (https://…), con target, con modificadores (Ctrl/Cmd/Mayús),
//     o si el propio recurso ya lo gestionó (preventDefault) → no se toca.
// No cambia el sandbox ni lee nada del recurso: solo corrige a dónde lleva
// un clic que, sin él, sacaba al usuario del recurso.
//
// Funciones puras: sin require, sin BD, sin salida. La cargan páginas HTML,
// así que NUNCA helpers.php (CLAUDE.md §2.1).
// Antirregresión: tests/unit/srcdoc_test.php (lógica del script, en Node) y
// tests/integration/render_pages_test.php (ficha y visor lo llevan).
// ================================================================

/** El script que mantiene los enlaces internos dentro del recurso. */
function iarepo_srcdoc_shim(): string
{
    // Sin comillas simples ni cierre de script dentro: viaja en un atributo
    // srcdoc (escapado con h()) y en un literal JS (json_encode).
    // La etiqueta se compone con $tag para que el guard G6 (que valida el JS
    // de cada <script> literal del repo) no lea esta concatenación PHP como
    // si fuera JavaScript. El JS de verdad lo ejecuta tests/unit/srcdoc_test.php.
    $tag = 'script';
    return "<$tag data-iarepo=\"anclas\">" . '(function(){'
        . 'function t(e){var n=e.target;return n&&n.closest?n.closest("a[href]"):null}'
        . 'document.addEventListener("click",function(e){'
        .   'if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;'
        .   'var a=t(e);if(!a)return;'
        .   'var g=a.getAttribute("target");if(g&&g!=="_self")return;'
        .   'var h=(a.getAttribute("href")||"").trim();'
        .   'if(h.charAt(0)==="#"){e.preventDefault();var id=h.slice(1);'
        .     'try{id=decodeURIComponent(id)}catch(x){}'
        .     'var el=id?(document.getElementById(id)||document.getElementsByName(id)[0]):null;'
        .     'if(el){el.scrollIntoView({behavior:"smooth",block:"start"})}'
        .     'else if(!id||id==="top"){window.scrollTo({top:0,behavior:"smooth"})}'
        .     'return}'
        .   'if(!/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(h)){e.preventDefault()}'
        . '})})();' . "</$tag>";
}

/** El HTML del autor + el script de anclas, para el atributo srcdoc (escápalo con h()). */
function iarepo_srcdoc(string $html): string
{
    return $html . "\n" . iarepo_srcdoc_shim();
}
