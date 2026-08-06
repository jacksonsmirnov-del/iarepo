<?php
// ================================================================
// viewer/index.php — Resource Viewer / Presenter
//
// Renders a resource in a safe iframe sandbox.
// Used for:
//   - Preview in the editor
//   - Fullscreen presentation mode (projector)
//   - Student view (assignments)
//
// Access: /view/{id} or /viewer/index.php?id={id}
// Auth:   Optional (public resources visible without token)
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/i18n.php';
lang();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { showViewerError(400, 'Missing resource ID', 'No se proporcionó un ID de recurso.'); }

$db = getResourcesDB();

// Fetch resource
$stmt = $db->prepare("
    SELECT code_content, code_type, title, visibility,
           author_tenant_id, author_user_id, author_display_name, subject_area,
           source_name, source_url, iframe_blocked
    FROM resources
    WHERE id = ? AND is_active = 1
");
$stmt->execute([$id]);
$resource = $stmt->fetch();

if (!$resource) {
    showViewerError(404, 'Resource not found', 'Este recurso no existe o ha sido eliminado.');
}

// ── Visibility check ─────────────────────────────────────────
// Community resources are public. Others require a valid JWT.
if ($resource['visibility'] !== 'community') {
    $user = authenticate();
    if (!$user) {
        showViewerError(401, 'Authentication required', 'Necesitas autenticarte para ver este recurso.');
    }

    $vis = $resource['visibility'];
    $authorTenant = $resource['author_tenant_id'];
    $userTenant = $user['tenant_id'] ?? 0;

    // Draft: only author
    if ($vis === 'draft') {
        if ($user['user_id'] != $resource['author_user_id'] ?? -1) {
            showViewerError(403, 'Private resource', 'Este recurso es un borrador privado.');
        }
    }
    // Area: same tenant + same area
    elseif ($vis === 'area') {
        if ($userTenant !== $authorTenant) {
            showViewerError(403, 'Restricted resource', 'Este recurso está restringido al área del autor.');
        }
    }
    // School: same tenant
    elseif ($vis === 'school') {
        if ($userTenant !== $authorTenant) {
            showViewerError(403, 'Restricted resource', 'Este recurso está restringido al colegio del autor.');
        }
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
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#7c3aed">
    <script src="/assets/js/pwa.js" defer></script>
    <!-- Misma medición que /resource/N, marcando la superficie 'viewer' para
         poder distinguir quién abre a pantalla completa de quién se queda en
         la ficha. Sustituye al UPDATE crudo que había aquí: contaba por CARGA
         y sin deduplicar, así que una persona recargando ocho veces valía
         ocho visitas. -->
    <script src="/assets/js/track.js" data-resource-id="<?= $id ?>" data-surface="viewer" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; font-family: system-ui, sans-serif; }
        body { background: #0f172a; }

        /* Top bar (hidden in present mode) */
        .viewer-bar {
            display: <?= $isPresent ? 'none' : 'flex' ?>;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background: #1e293b;
            color: #e2e8f0;
            font-size: 14px;
            border-bottom: 1px solid #334155;
        }
        .viewer-bar .title { font-weight: 600; }
        .viewer-bar .meta { color: #94a3b8; font-size: 12px; }
        .viewer-bar .actions { display: flex; gap: 8px; }
        .viewer-bar .btn {
            padding: 6px 14px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .btn-present {
            background: #3b82f6;
            color: white;
        }
        .btn-present:hover { background: #2563eb; }
        .btn-close {
            background: #334155;
            color: #e2e8f0;
        }
        .btn-close:hover { background: #475569; }

        /* Resource frame */
        .viewer-frame {
            width: 100%;
            height: <?= $isPresent ? '100vh' : 'calc(100vh - 45px)' ?>;
            border: none;
            display: block;
            background: white;
        }

        /* External link fallback for iframe-blocked sites */
        .external-fallback {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: <?= $isPresent ? '100vh' : 'calc(100vh - 45px)' ?>;
            background: #f8fafc;
            color: #1e293b;
            text-align: center;
            padding: 40px;
        }
        .external-fallback h2 { font-size: 1.5rem; margin-bottom: 12px; }
        .external-fallback p { color: #64748b; margin-bottom: 24px; max-width: 500px; line-height: 1.6; }
        .external-fallback .ext-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 14px 28px; border-radius: 10px;
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            color: white; font-size: 1.1rem; font-weight: 600;
            text-decoration: none; transition: transform .2s, box-shadow .2s;
            box-shadow: 0 4px 16px rgba(124,58,237,.3);
        }
        .external-fallback .ext-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(124,58,237,.4); }
        .external-fallback .source { color: #94a3b8; font-size: .85rem; margin-top: 16px; }

        /* Fullscreen exit button */
        .fs-exit {
            display: none;
            position: fixed;
            top: 12px;
            right: 16px;
            background: rgba(0,0,0,0.75);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 8px 18px;
            border-radius: 24px;
            font-size: 13px;
            font-family: inherit;
            font-weight: 500;
            z-index: 99999;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .fs-exit:hover { background: rgba(220,38,38,0.85); }
        body:hover .fs-exit.visible { opacity: 1; }
    </style>
    <?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>
    <div class="viewer-bar">
        <div>
            <span class="title"><?= h($resource['title']) ?></span>
            <span class="meta"> — <?= h($resource['author_display_name']) ?> · <?= h($resource['subject_area'] ?? '') ?></span>
            <?php if ($resource['source_name']): ?>
                <a href="<?= h($resource['source_url'] ?? '#') ?>" target="_blank" rel="noopener"
                   style="color:#94a3b8;text-decoration:none;font-size:11px;margin-left:8px" title="Fuente original">
                    📎 <?= h($resource['source_name']) ?>
                </a>
            <?php endif; ?>
        </div>
        <div class="actions">
            <button class="btn btn-present" id="btnPresent">📺 <?= h(t('Presentar')) ?></button>
            <button class="btn btn-close" id="btnClose">✕ <?= h(t('Cerrar')) ?></button>
        </div>
    </div>

    <button class="fs-exit" id="fs-exit">✕ <?= h(t('Salir de pantalla completa')) ?></button>

    <?php if ($resource['code_type'] === 'html'): ?>
        <iframe class="viewer-frame"
                srcdoc="<?= h($resource['code_content']) ?>"
                sandbox="allow-scripts allow-modals allow-popups"
                title="<?= h($resource['title']) ?>">
        </iframe>
    <?php elseif ($resource['code_type'] === 'url'): ?>
        <?php $url = $resource['code_content']; ?>
        <!-- Try iframe first, fallback on block -->
        <iframe class="viewer-frame" id="url-frame"
                src="<?= h($url) ?>"
                title="<?= h($resource['title']) ?>"
                style="display:block;">
        </iframe>
        <div class="external-fallback" id="url-fallback" style="display:none;">
            <div style="font-size:3rem;margin-bottom:16px;">🔗</div>
            <h2><?= h($resource['title']) ?></h2>
            <p><?= h(t('Este recurso no permite ser embebido en iframe por políticas de seguridad del sitio original. Haz click para abrirlo directamente.')) ?></p>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener" class="ext-btn">
                🚀 <?= h(t('Abrir')) ?> <?= h($resource['source_name'] ?? t('recurso')) ?>
            </a>
            <?php if ($resource['source_name']): ?>
                <div class="source"><?= h(t('Fuente')) ?>: <?= h($resource['source_name']) ?></div>
            <?php endif; ?>
        </div>
        <!-- Floating open-external button (always visible for URLs) -->
        <a href="<?= h($url) ?>" target="_blank" rel="noopener"
           style="position:fixed;bottom:16px;right:16px;z-index:9999;
                  background:rgba(30,41,59,.9);color:#e2e8f0;padding:8px 16px;
                  border-radius:20px;font-size:12px;text-decoration:none;
                  border:1px solid #475569;backdrop-filter:blur(8px);
                  transition:background .2s"
           onmouseover="this.style.background='rgba(59,130,246,.9)'"
           onmouseout="this.style.background='rgba(30,41,59,.9)'"
           title="<?= h(t('Abrir en pestaña nueva')) ?>">↗ <?= h(t('Abrir externo')) ?></a>
        <script>
        // Detect iframe block: if iframe doesn't load in 4s, show fallback
        (function(){
            const frame = document.getElementById('url-frame');
            const fallback = document.getElementById('url-fallback');
            let loaded = false;
            frame.addEventListener('load', function() { loaded = true; });
            frame.addEventListener('error', function() {
                frame.style.display = 'none';
                fallback.style.display = 'flex';
            });
            // Timeout fallback — if frame content is empty/blocked
            setTimeout(function() {
                if (!loaded) return; // loaded fine
                try {
                    // Try to access frame — will throw if cross-origin
                    const doc = frame.contentDocument || frame.contentWindow.document;
                    if (!doc || !doc.body || doc.body.innerHTML === '') {
                        frame.style.display = 'none';
                        fallback.style.display = 'flex';
                    }
                } catch(e) {
                    // Cross-origin — iframe loaded but we can't check, leave it
                }
            }, 4000);
        })();
        </script>
    <?php elseif ($resource['code_type'] === 'embed'): ?>
        <iframe class="viewer-frame"
                srcdoc="<?= h($resource['code_content']) ?>"
                sandbox="allow-scripts allow-modals allow-popups allow-forms"
                title="<?= h($resource['title']) ?>">
        </iframe>
    <?php else: ?>
        <pre style="width:100%;height:calc(100vh - 45px);overflow:auto;background:#1e1e2e;color:#cdd6f4;padding:20px;font-size:14px;font-family:'Fira Code',monospace;white-space:pre-wrap"><?= h($resource['code_content']) ?></pre>
    <?php endif; ?>

    <script>
        const bar = document.querySelector('.viewer-bar');
        const exitBtn = document.getElementById('fs-exit');

        document.getElementById('btnPresent').addEventListener('click', goPresent);
        document.getElementById('btnClose').addEventListener('click', () => window.close());
        exitBtn.addEventListener('click', exitPresent);

        function goPresent() {
            const el = document.documentElement;
            const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (rfs) {
                rfs.call(el).then(() => enterFS()).catch(() => enterFS());
            } else {
                enterFS();
            }
        }

        function enterFS() {
            bar.style.display = 'none';
            exitBtn.style.display = 'block';
            exitBtn.classList.add('visible');
        }

        function exitPresent() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                leaveFS();
            }
        }

        function leaveFS() {
            bar.style.display = '';
            exitBtn.style.display = 'none';
            exitBtn.classList.remove('visible');
        }

        // Restore on exit fullscreen (ESC or browser button)
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) leaveFS();
        });
        document.addEventListener('webkitfullscreenchange', () => {
            if (!document.webkitFullscreenElement) leaveFS();
        });
    </script>
</body>
</html>
<?php

// ══════════════════════════════════════════════════════════════
// HELPER: Professional error page for viewer
// ══════════════════════════════════════════════════════════════
function showViewerError(int $httpCode, string $title, string $message): never {
    http_response_code($httpCode);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= $title ?> — iarepo</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Inter', system-ui, sans-serif;
                background: #0f172a; color: #e2e8f0;
                display: flex; align-items: center; justify-content: center;
                min-height: 100vh; text-align: center; padding: 24px;
            }
            .error-card {
                max-width: 480px;
                background: #1e293b;
                border: 1px solid #334155;
                border-radius: 16px;
                padding: 48px 32px;
                box-shadow: 0 8px 32px rgba(0,0,0,.4);
            }
            .error-code {
                font-size: 4rem; font-weight: 800;
                background: linear-gradient(135deg, #7c3aed, #06b6d4);
                -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                margin-bottom: 12px;
            }
            h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 12px; }
            p { color: #94a3b8; line-height: 1.6; margin-bottom: 24px; }
            .back-btn {
                display: inline-block; padding: 10px 24px;
                background: linear-gradient(135deg, #7c3aed, #06b6d4);
                color: white; text-decoration: none; border-radius: 24px;
                font-weight: 600; font-size: .9rem; transition: transform .2s;
            }
            .back-btn:hover { transform: translateY(-2px); }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-code"><?= $httpCode ?></div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($message) ?></p>
            <a href="/" class="back-btn">← <?= h(t('Volver a iarepo')) ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
