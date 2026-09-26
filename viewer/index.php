<?php
// ================================================================
// viewer/index.php — El visor limpio de un recurso (proyector y alumnos)
//
// Muestra SOLO el recurso, en un iframe con sandbox. Se usa para:
//   - «Mandar a mis alumnos»: el QR y la dirección corta llevan aquí (/view/N)
//   - el modo proyector (?mode=present: sin barra, a pantalla completa)
//   - insertarlo en un aula virtual (el código de «Insertar» apunta aquí)
//   - Campus, que lo embebe con JWT para recursos no públicos
//
// Access: /view/{id} or /viewer/index.php?id={id}
// Auth:   opcional. Lo público se ve sin sesión; lo demás exige JWT/sesión y
//         la MISMA regla que la API (canView, shared/access.php).
//
// Sin pwa.js a propósito: su botón flotante «Instalar app» tapaba la
// proyección. Aquí no debe haber nada encima del recurso salvo un botón
// discreto de pantalla completa que se esconde solo.
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/asset.php';
require_once __DIR__ . '/../shared/labels.php';   // carga también shared/i18n.php
// h() local — NO se carga shared/helpers.php: su error_handler vuelca JSON y
// corta la página a medias ante cualquier error (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { showViewerError(400, t('Falta el recurso'), t('No se proporcionó un ID de recurso.')); }

$db = getResourcesDB();

// Fetch resource
$stmt = $db->prepare("
    SELECT r.code_content, r.code_type, r.title, r.visibility,
           r.author_tenant_id, r.author_user_id, r.author_display_name, r.subject_area,
           r.source_name, r.source_url, r.iframe_blocked,
           c.name AS category_name, c.slug AS category_slug
    FROM resources r
    LEFT JOIN categories c ON r.category_id = c.id
    WHERE r.id = ? AND r.is_active = 1
");
$stmt->execute([$id]);
$resource = $stmt->fetch();

if (!$resource) {
    showViewerError(404, t('Recurso no encontrado'), t('Este recurso no existe o ha sido eliminado.'));
}

// ── Visibilidad ──────────────────────────────────────────────
// Lo público (community) lo ve cualquiera. Lo demás exige identidad (JWT de
// Campus o sesión) y pasa por canView(), la misma regla que la API. Antes
// había aquí una copia propia que comparaba el tenant con !== entre un int y
// lo que devolviera PDO: según el driver, un docente de su propio colegio
// podía quedarse fuera.
if ($resource['visibility'] !== 'community') {
    $user = authenticate();
    if (!$user) {
        showViewerError(401, t('Hace falta entrar'), t('Necesitas entrar con tu cuenta para ver este recurso.'),
            '/auth/signin.php?return_url=' . rawurlencode('/view/' . $id));
    }
    if (!canView($resource, $user)) {
        $msg = match ($resource['visibility']) {
            'draft'  => t('Este recurso es un borrador privado.'),
            'area'   => t('Este recurso está restringido al área del autor.'),
            default  => t('Este recurso está restringido al colegio del autor.'),
        };
        showViewerError(403, $resource['visibility'] === 'draft' ? t('Recurso privado') : t('Recurso restringido'), $msg);
    }
}

// ── Las visitas ya NO se cuentan aquí ────────────────────────
//
// Había un `UPDATE resources SET view_count = view_count + 1` en este punto.
// Se retiró [2026-08-06] y `view_count` queda CONGELADO como marca histórica.
//
// Contaba por CARGA y sin deduplicar: una persona recargando ocho veces valía
// ocho visitas, y los crawlers sumaban igual que las personas. Peor aún, este
// era uno de los dos únicos sitios que contaban — /resource/N, que es donde de
// verdad se usa el recurso, no contaba nada.
//
// Ahora mide assets/js/track.js contra api/track.php: una fila por persona,
// recurso y día, en `resource_views`, y el contador vivo es
// `resources.unique_views`. Ver AGENTS.md §6.8.
//
// ⚠️ NO vuelvas a añadir un incremento aquí "por si acaso": duplicaría la
// visita del mismo usuario en la misma carga y las dos métricas dejarían de
// poder compararse entre sí.

// ── Render ────────────────────────────────────────────────────
$mode = $_GET['mode'] ?? 'view'; // 'view' or 'present'
$isPresent = ($mode === 'present');
// ?ui=0: sin NINGÚN control encima (ni pantalla completa ni «Abrir en…»).
// Para capturas automáticas: setup/tools/generate-thumbnails.sh hace una foto
// con Chrome headless de ?mode=present, y el botón saldría en cada miniatura.
$noUi = ($_GET['ui'] ?? '') === '0';
// Incrustado en un iframe (Campus lo incrusta con ?token=; también Moodle o
// Google Sites con el código de «Insertar»). Ahí un enlace normal navega
// DENTRO del iframe: metía la web entera de iarepo dentro de Campus y, sin el
// ?token=, un recurso de centro acababa en «acceso restringido». Se detecta
// con Sec-Fetch-Dest (lo pone el navegador) y, si no llega, en el cliente
// (window.top !== window.self): la ficha solo se ofrece en PESTAÑA NUEVA y
// solo si el recurso es público; si no, el título es texto plano.
$embedded = ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe';
$isPublic = ($resource['visibility'] ?? '') === 'community';

// Etiquetas visibles (nada de claves en crudo). Un recurso 'url' sin
// source_url tiene la fuente en su propia dirección.
$labelRow = $resource;
if (trim((string) $resource['source_url']) === '' && $resource['code_type'] === 'url')
    $labelRow['source_url'] = $resource['code_content'];
$sourceLabel   = iarepo_source_label($labelRow);
$categoryLabel = iarepo_category_label($resource['category_slug'] ?? null, $resource['category_name'] ?? ($resource['subject_area'] ?? null));
// Solo http(s) limpia: nunca un javascript: en el src de un iframe ni en un
// href, ni una dirección que PHP y el navegador leen con hosts distintos
// (iarepo_safe_http_url, shared/labels.php).
$safeUrl = static fn(?string $u): string => iarepo_safe_http_url($u);
$url       = $resource['code_type'] === 'url' ? $safeUrl($resource['code_content']) : '';
$sourceUrl = $safeUrl($resource['source_url'] ?? '') ?: $url;
$openName  = $sourceLabel ?: t('su web');
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($resource['title']) ?> — iarepo</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://iarepo.com/resource/<?= $id ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <!-- Misma medición que /resource/N, marcando la superficie 'viewer' para
         poder distinguir quién abre a pantalla completa de quién se queda en
         la ficha. Sustituye al UPDATE crudo que había aquí: contaba por CARGA
         y sin deduplicar, así que una persona recargando ocho veces valía
         ocho visitas. -->
    <script src="<?= h(iarepo_asset('/assets/js/track.js')) ?>" data-resource-id="<?= $id ?>" data-surface="viewer" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        body { background: #0f172a; }

        /* Barra superior (oculta en modo proyector) */
        .viewer-bar {
            display: <?= $isPresent ? 'none' : 'flex' ?>;
            align-items: center; justify-content: space-between; gap: 12px;
            min-height: 48px; padding: 6px 12px 6px 16px;
            background: #1e293b; color: #e2e8f0; font-size: 14px;
            border-bottom: 1px solid #334155;
        }
        .viewer-bar .who { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .viewer-bar .title { font-weight: 700; color: #f8fafc; text-decoration: none; }
        .viewer-bar .title:hover { text-decoration: underline; }
        .viewer-bar .meta { color: #cbd5e1; font-size: 13px; }
        .viewer-bar .meta a { color: #cbd5e1; }
        .viewer-bar .actions { display: flex; gap: 8px; flex: none; }
        .viewer-bar .btn {
            min-height: 36px; padding: 6px 14px; border-radius: 999px; border: 0; cursor: pointer;
            font-family: inherit; font-size: 13px; font-weight: 600; line-height: 1;
        }
        .btn-present { background: #a78bfa; color: #160e2e; }
        .btn-present:hover { background: #c4b5fd; }
        .btn-close { background: #334155; color: #e2e8f0; display: inline-flex; align-items: center; text-decoration: none; }
        .lbl-narrow { display: none; }
        @media (max-width: 559px) and (pointer: coarse) { .lbl-wide { display: none; } .lbl-narrow { display: inline; } }
        /* En un móvil estrecho, «Pantalla completa» + «Ver la ficha» dejaban el
           título en tres letras. El título YA lleva a la ficha: se subraya y el
           botón sobra. */
        @media (max-width: 419px) { .btn-close { display: none; } .viewer-bar .title { text-decoration: underline; text-underline-offset: 3px; } }
        .btn-close:hover { background: #475569; }
        .viewer-bar .btn:focus-visible, .fs-btn:focus-visible, .ext-link:focus-visible { outline: 3px solid #ffd400; outline-offset: 2px; }

        /* Resource frame */
        .viewer-frame {
            width: 100%;
            height: <?= $isPresent ? '100vh' : 'calc(100vh - 49px)' ?>;
            border: none; display: block; background: white;
        }
        body.is-fs .viewer-frame, body.is-fs .external-fallback { height: 100vh; }

        /* Sitio que no se deja embeber */
        .external-fallback {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: <?= $isPresent ? '100vh' : 'calc(100vh - 49px)' ?>;
            background: #f8fafc; color: #1e293b; text-align: center; padding: 40px;
        }
        .external-fallback[hidden], .viewer-frame[hidden] { display: none; }
        .external-fallback h2 { font-size: 1.5rem; margin-bottom: 12px; }
        .external-fallback p { color: #475569; margin-bottom: 24px; max-width: 500px; line-height: 1.6; }
        .external-fallback .ext-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px; border-radius: 999px;
            background: #6d28d9; color: white; font-size: 1.1rem; font-weight: 700; text-decoration: none;
        }
        .external-fallback .ext-btn:hover { background: #5b21b6; }
        .external-fallback .source { color: #475569; font-size: .9rem; margin-top: 16px; }

        /* Controles flotantes discretos: se esconden tras 3 s sin mover el
           ratón (.is-idle) y vuelven al moverlo o al pasar por encima. Sobre
           una pizarra digital no se ve nada que no sea el recurso. */
        .fs-btn, .ext-link {
            position: fixed; z-index: 9999; display: inline-flex; align-items: center; gap: 6px;
            min-height: 40px; padding: 8px 16px; border-radius: 999px;
            background: rgba(15, 23, 42, .78); color: #fff; border: 1px solid rgba(255, 255, 255, .25);
            font: 600 13px/1 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            text-decoration: none; cursor: pointer; transition: opacity .3s, background .2s;
        }
        .fs-btn { top: 12px; right: 12px; }
        .ext-link { bottom: 12px; right: 12px; }
        .fs-btn:hover, .ext-link:hover { background: rgba(109, 40, 217, .92); }
        .fs-btn[hidden] { display: none; }
        /* Solo con ratón: en una pantalla táctil (pizarra digital, tableta) el
           toque sobre el iframe no llega a esta página y el botón no volvería;
           ahí se queda atenuado, pero a la vista. */
        .is-idle .fs-btn, .is-idle .ext-link { opacity: .4; }
        @media (hover: hover) { .is-idle .fs-btn, .is-idle .ext-link { opacity: 0; } }
        .is-idle .fs-btn:hover, .is-idle .ext-link:hover, .fs-btn:focus-visible, .ext-link:focus-visible { opacity: 1; }
        @media (prefers-reduced-motion: reduce) { .fs-btn, .ext-link { transition: none; } }
        .no-ui .fs-btn, .no-ui .ext-link { display: none !important; }
    </style>
    <?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="<?= trim(($isPresent ? 'is-present' : '') . ($noUi ? ' no-ui' : '')) ?: 'is-view' ?>">
    <div class="viewer-bar">
        <div class="who">
            <!-- El título lleva a la ficha: quien llega por un QR encuentra ahí
                 «¿te quedó claro?» y el siguiente paso. -->
            <?php if ($embedded && !$isPublic): ?>
            <span class="title"><?= h($resource['title']) ?></span>
            <?php else: ?>
            <a class="title" href="/resource/<?= $id ?>" data-public="<?= $isPublic ? '1' : '0' ?>"<?= $embedded ? ' target="_blank" rel="noopener"' : '' ?>><?= h($resource['title']) ?></a>
            <?php endif; ?>
            <span class="meta"> — <?= h($categoryLabel) ?><?php if ($sourceLabel): ?> · <?= h(t('Creado por')) ?> <?php if ($sourceUrl !== ''): ?><a href="<?= h($sourceUrl) ?>" target="_blank" rel="noopener"><?= h($sourceLabel) ?></a><?php else: ?><?= h($sourceLabel) ?><?php endif; ?><?php endif; ?></span>
        </div>
        <div class="actions">
            <?php /* En un móvil (quien llega por el QR suele ser un alumno) el
                     botón dice «Pantalla completa»: «Proyectar» es palabra de docente. */ ?>
            <button type="button" class="btn btn-present" id="btnPresent"><span class="lbl-wide"><?= h(t('Proyectar')) ?></span><span class="lbl-narrow"><?= h(t('Pantalla completa')) ?></span></button>
            <?php /* «Cerrar» con window.close() no hacía nada justo en el caso
                     principal: la pestaña que abre un QR o un enlace no la abrió
                     un script y el navegador no deja cerrarla. Ahora es un enlace
                     a la ficha («¿te quedó claro?», siguiente paso); solo si otra
                     página abrió esta pestaña, cierra [revisión 2026-09]. */ ?>
            <?php if (!$embedded): ?>
            <a class="btn btn-close" id="btnClose" href="/resource/<?= $id ?>" data-close="<?= h(t('Cerrar')) ?>"><?= h(t('Ver la ficha')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pantalla completa: visible en modo proyector y mientras se proyecta. -->
    <button type="button" class="fs-btn" id="fsBtn"<?= $isPresent ? '' : ' hidden' ?>
            data-enter="<?= h(t('Pantalla completa')) ?>" data-exit="<?= h(t('Salir de pantalla completa')) ?> (Esc)"><?= h(t('Pantalla completa')) ?></button>

    <?php if ($resource['code_type'] === 'html'): ?>
        <iframe class="viewer-frame"
                srcdoc="<?= h($resource['code_content']) ?>"
                sandbox="allow-scripts allow-modals allow-popups"
                title="<?= h($resource['title']) ?>">
        </iframe>
    <?php elseif ($resource['code_type'] === 'url'): ?>
        <?php $blocked = (int) ($resource['iframe_blocked'] ?? 0) === 1 || $url === ''; ?>
        <?php if (!$blocked): ?>
        <!-- Try iframe first, fallback on block -->
        <iframe class="viewer-frame" id="url-frame"
                src="<?= h($url) ?>"
                title="<?= h($resource['title']) ?>">
        </iframe>
        <?php endif; ?>
        <div class="external-fallback" id="url-fallback"<?= $blocked ? '' : ' hidden' ?>>
            <h2><?= h($resource['title']) ?></h2>
            <p><?= h(t('Este sitio no deja que se muestre dentro de iarepo. Ábrelo en su web original: funciona igual.')) ?></p>
            <?php if ($url !== ''): ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener" class="ext-btn"><?= h(t('Abrir en')) ?> <?= h($openName) ?> ↗</a>
            <?php endif; ?>
            <?php if ($sourceLabel): ?>
                <div class="source"><?= h(t('Fuente')) ?>: <?= h($resource['source_name'] ?: $sourceLabel) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($url !== '' && !$blocked): ?>
        <!-- Abrir fuera, siempre a mano: si el sitio se ve mal en un iframe,
             es un clic. Discreto y se esconde con el resto de controles. -->
        <a class="ext-link" href="<?= h($url) ?>" target="_blank" rel="noopener"
           title="<?= h(t('Abrir en pestaña nueva')) ?>"><?= h(t('Abrir en')) ?> <?= h($openName) ?> ↗</a>
        <script>
        // Detect iframe block: if iframe doesn't load in 4s, show fallback
        (function(){
            const frame = document.getElementById('url-frame');
            const fallback = document.getElementById('url-fallback');
            let loaded = false;
            const block = function () { frame.hidden = true; fallback.hidden = false; };
            frame.addEventListener('load', function() { loaded = true; });
            frame.addEventListener('error', block);
            setTimeout(function() {
                if (!loaded) return;
                try {
                    // Try to access frame — will throw if cross-origin
                    const doc = frame.contentDocument || frame.contentWindow.document;
                    if (!doc || !doc.body || doc.body.innerHTML === '') block();
                } catch(e) {
                    // Cross-origin — iframe loaded but we can't check, leave it
                }
            }, 4000);
        })();
        </script>
        <?php endif; ?>
    <?php elseif ($resource['code_type'] === 'embed'): ?>
        <iframe class="viewer-frame"
                srcdoc="<?= h($resource['code_content']) ?>"
                sandbox="allow-scripts allow-modals allow-popups allow-forms"
                title="<?= h($resource['title']) ?>">
        </iframe>
    <?php else: ?>
        <pre class="viewer-frame" style="overflow:auto;background:#1e1e2e;color:#cdd6f4;padding:20px;font-size:14px;font-family:ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap"><?= h($resource['code_content']) ?></pre>
    <?php endif; ?>

    <script>
    (function () {
        const IS_PRESENT = <?= $isPresent ? 'true' : 'false' ?>;
        const body = document.body;
        const bar = document.querySelector('.viewer-bar');
        const fsBtn = document.getElementById('fsBtn');

        // ── Controles que se esconden solos ─────────────────────────
        // Tras 3 s sin mover el ratón, opacidad 0 (siguen ahí: pasar por
        // encima o tabular los devuelve). Sobre el iframe el ratón no avisa a
        // esta página, así que también se esconden si se mueve DENTRO.
        let idle = null;
        function wake() {
            body.classList.remove('is-idle');
            clearTimeout(idle);
            idle = setTimeout(function () { body.classList.add('is-idle'); }, 3000);
        }
        ['mousemove', 'touchstart', 'keydown'].forEach(function (ev) { document.addEventListener(ev, wake, { passive: true }); });
        wake();

        // ── Pantalla completa ──────────────────────────────────────
        // Fullscreen API si existe; si no (iPhone), se oculta la barra y el
        // recurso ocupa la ventana. Esc sale en los dos casos.
        function isFull() { return !!(document.fullscreenElement || document.webkitFullscreenElement) || body.classList.contains('is-fs'); }
        function paint() {
            const full = isFull();
            fsBtn.textContent = full ? fsBtn.dataset.exit : fsBtn.dataset.enter;
            fsBtn.hidden = !full && !IS_PRESENT;
            if (bar) bar.style.display = full || IS_PRESENT ? 'none' : '';
        }
        function enter() {
            const el = document.documentElement;
            const rfs = el.requestFullscreen || el.webkitRequestFullscreen;
            body.classList.add('is-fs');
            if (rfs) {
                try { const p = rfs.call(el); if (p && p.catch) p.catch(function () {}); } catch (e) {}
            }
            paint(); wake();
        }
        function leave() {
            body.classList.remove('is-fs');
            if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
            else if (document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
            paint();
        }
        fsBtn.addEventListener('click', function () { isFull() ? leave() : enter(); });
        document.getElementById('btnPresent').addEventListener('click', enter);
        // «Cerrar» solo cuando otra página abrió esta pestaña (entonces el
        // navegador sí deja cerrarla); si no, el enlace lleva a la ficha.
        const btnClose = document.getElementById('btnClose');
        // Respaldo de Sec-Fetch-Dest (navegadores antiguos): dentro de un
        // iframe, nada navega dentro de él (ver $embedded arriba).
        let inFrame = false;
        try { inFrame = window.top !== window.self; } catch (e) { inFrame = true; }
        if (inFrame) {
            if (btnClose) btnClose.remove();
            const ttl = document.querySelector('.viewer-bar a.title');
            if (ttl && ttl.dataset.public === '1') { ttl.target = '_blank'; ttl.rel = 'noopener'; }
            else if (ttl) ttl.replaceWith(Object.assign(document.createElement('span'), { className: 'title', textContent: ttl.textContent }));
        }
        if (btnClose && btnClose.isConnected && window.opener) {
            btnClose.textContent = btnClose.dataset.close;
            btnClose.addEventListener('click', function (e) { e.preventDefault(); window.close(); });
        }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isFull()) leave(); });
        // Salir con Esc o con el botón del navegador en modo nativo
        ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
            document.addEventListener(ev, function () {
                if (!document.fullscreenElement && !document.webkitFullscreenElement) body.classList.remove('is-fs');
                paint();
            });
        });
        paint();
    })();
    </script>
</body>
</html>
<?php

// ══════════════════════════════════════════════════════════════
// Página de error del visor: en el idioma de la interfaz, sin fuentes
// externas y con una salida útil (entrar, si el problema es la sesión).
// ══════════════════════════════════════════════════════════════
function showViewerError(int $httpCode, string $title, string $message, ?string $signinUrl = null): never {
    http_response_code($httpCode);
    ?>
    <!DOCTYPE html>
    <html lang="<?= lang() ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title><?= h($title) ?> — iarepo</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                background: #0f172a; color: #e2e8f0;
                display: flex; align-items: center; justify-content: center;
                min-height: 100vh; text-align: center; padding: 24px;
            }
            .error-card {
                max-width: 480px; background: #1e293b; border: 1px solid #334155;
                border-radius: 16px; padding: 48px 32px; box-shadow: 0 8px 32px rgba(0,0,0,.4);
            }
            .error-code { font-size: 3.5rem; font-weight: 800; color: #a78bfa; margin-bottom: 12px; }
            h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 12px; }
            p { color: #cbd5e1; line-height: 1.6; margin-bottom: 24px; }
            .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
            .back-btn {
                display: inline-block; padding: 12px 24px; border-radius: 999px;
                background: #a78bfa; color: #160e2e; text-decoration: none; font-weight: 700; font-size: .95rem;
            }
            .back-btn.secondary { background: transparent; color: #e2e8f0; border: 2px solid #475569; }
            .back-btn:focus-visible { outline: 3px solid #ffd400; outline-offset: 2px; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-code"><?= $httpCode ?></div>
            <h1><?= h($title) ?></h1>
            <p><?= h($message) ?></p>
            <div class="actions">
                <?php if ($signinUrl): ?><a href="<?= h($signinUrl) ?>" class="back-btn"><?= h(t('Entrar')) ?></a><?php endif; ?>
                <a href="/" class="back-btn<?= $signinUrl ? ' secondary' : '' ?>">← <?= h(t('Volver a iarepo')) ?></a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
