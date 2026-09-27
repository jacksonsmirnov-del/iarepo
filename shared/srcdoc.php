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
// ── 2. localStorage / sessionStorage / document.cookie [2026-09-27] ──
// El sandbox NO lleva allow-same-origin (a propósito: el código del autor no
// puede tocar la sesión de quien lo mira). Sin él, LEER localStorage lanza
// «The document is sandboxed and lacks the 'allow-same-origin' flag» y el
// script del recurso se para antes de dibujar nada: recurso en blanco
// (recurso 617, un juego que guardaba la puntuación). Lo hace cualquier HTML
// de IA que «guarde el progreso». Arreglo: un script al PRINCIPIO (antes que
// los del autor) que, SOLO si el acceso real falla, pone en su lugar un
// almacén en memoria. Dura lo que la pestaña, que es lo único posible sin
// abrir el sandbox; el recurso funciona y no se abre ninguna puerta.
// Va justo detrás de <head> (o del <!doctype>, o al principio si no hay
// ninguno): delante del <!doctype> pondría la página en modo quirks.
//
// ── 1. Enlaces internos ────────────────────────────────────────
// El arreglo es un script mínimo que se AÑADE al final del HTML del autor:
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

/**
 * Almacenamiento de respaldo: si leer localStorage / sessionStorage /
 * document.cookie falla (sandbox sin allow-same-origin), uno en memoria.
 * Si el acceso real funciona, no toca nada.
 */
function iarepo_srcdoc_storage(): string
{
    $tag = 'script';   // ver iarepo_srcdoc_shim(): por el guard G6
    return "<$tag data-iarepo=\"almacen\">" . '(function(){'
        . 'function m(){var d={};return{'
        .   'getItem:function(k){k=String(k);return Object.prototype.hasOwnProperty.call(d,k)?d[k]:null},'
        .   'setItem:function(k,v){d[String(k)]=String(v)},'
        .   'removeItem:function(k){delete d[String(k)]},'
        .   'clear:function(){d={}},'
        .   'key:function(i){var ks=Object.keys(d);return i<ks.length?ks[i]:null},'
        .   'get length(){return Object.keys(d).length}}}'
        . '["localStorage","sessionStorage"].forEach(function(n){'
        .   'try{if(window[n])return}catch(e){}'
        .   'try{Object.defineProperty(window,n,{value:m(),configurable:true})}catch(e){}});'
        . 'try{document.cookie}catch(e){var c="";'
        .   'try{Object.defineProperty(document,"cookie",{get:function(){return c},set:function(){},configurable:true})}catch(x){}}'
        . '})();' . "</$tag>";
}

/** Dónde va el script del principio: tras <head…>, si no tras <!doctype…>, si no en 0. */
const IAREPO_SRCDOC_HEAD_RE    = '/<head(?=[\s>\/])[^>]*>/i';
const IAREPO_SRCDOC_DOCTYPE_RE = '/^\s*<!doctype[^>]*>/i';

/** El HTML del autor, listo para el atributo srcdoc (escápalo con h()). */
function iarepo_srcdoc(string $html): string
{
    $at = 0;
    if (preg_match(IAREPO_SRCDOC_HEAD_RE, $html, $m, PREG_OFFSET_CAPTURE)
        || preg_match(IAREPO_SRCDOC_DOCTYPE_RE, $html, $m, PREG_OFFSET_CAPTURE))
        $at = $m[0][1] + strlen($m[0][0]);
    return substr($html, 0, $at) . iarepo_srcdoc_storage() . substr($html, $at)
         . "\n" . iarepo_srcdoc_shim();
}

/**
 * Lo mismo para las vistas previas que montan el srcdoc en JavaScript (editor
 * y admin): define iarepoSrcdoc(code), con los mismos scripts y las mismas
 * expresiones. tests/unit/srcdoc_test.php compara su salida con la de PHP.
 */
function iarepo_srcdoc_js(): string
{
    // Las dos expresiones se escriben igual en PCRE y en JS («/…/i»): van tal cual.
    $f = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
    return 'function iarepoSrcdoc(c){'
        . 'var E=' . json_encode(iarepo_srcdoc_storage(), $f) . ',L=' . json_encode("\n" . iarepo_srcdoc_shim(), $f) . ';'
        . 'var m=' . IAREPO_SRCDOC_HEAD_RE . '.exec(c)||' . IAREPO_SRCDOC_DOCTYPE_RE . '.exec(c);'
        . 'var i=m?m.index+m[0].length:0;'
        . 'return c.slice(0,i)+E+c.slice(i)+L}';
}
