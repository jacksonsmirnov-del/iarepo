<?php
// ================================================================
// shared/ui.php — Componentes de interfaz compartidos (PHP)
//
// Cabecera, pie, portada de recurso y diálogo «Mandar a mis alumnos»: UNA
// implementación para todas las páginas. Antes cada página llevaba su propia
// cabecera (y su propio conmutador de tema, 17 copias): el selector de idioma
// existía en unas y no en otras, y en móvil desaparecía.
//
// Reglas:
//   · Funciones que imprimen HTML con estilos de assets/css/app.css (.ia-*).
//   · Todo texto visible pasa por t() (CLAUDE.md §2.3).
//   · Escapan con iarepo_e(): no dependen del h() de cada página ni de
//     shared/helpers.php (prohibido en páginas HTML, CLAUDE.md §2.1).
//   · Iconos: <i data-lucide="…">; la página llama a lucide.createIcons().
//
// Uso típico en una página (en <head> la hoja y el tema; al abrir el <body>
// la cabecera; al cerrarlo el pie y los scripts comunes):
/*
     require_once __DIR__ . '/../shared/ui.php';
     <head> … <?= iarepo_head_assets() ?> … </head>
     <body class="ia-page"> <?php iarepo_header($user, 'explore'); ?> …
     <?php iarepo_footer($user); ?> <?= iarepo_body_assets() ?> </body>
*/
// ================================================================

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/asset.php';
require_once __DIR__ . '/labels.php';

function iarepo_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** <head>: hoja común + tema aplicado ANTES de pintar (sin parpadeo claro→oscuro). */
function iarepo_head_assets(): string
{
    return '<link rel="stylesheet" href="' . iarepo_e(iarepo_asset('/assets/css/app.css')) . '">' . "\n"
         . '<script src="' . iarepo_e(iarepo_asset('/assets/js/theme.js')) . '"></script>' . "\n";
}

/** Final del <body>: iconos + comportamiento común (menú, diálogos, avisos). */
function iarepo_body_assets(bool $withQr = false): string
{
    $out = '<script src="' . iarepo_e(iarepo_asset('/assets/js/lucide.min.js')) . '"></script>' . "\n";
    if ($withQr)
        $out .= '<script src="' . iarepo_e(iarepo_asset('/assets/js/qrcode.js')) . '"></script>' . "\n";
    $out .= '<script src="' . iarepo_e(iarepo_asset('/assets/js/ui.js')) . '"></script>' . "\n";
    return $out;
}

/** Ruta actual (solo ruta + query propia), para volver tras entrar. */
function iarepo_current_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return preg_match('#^/[^/\\\\]#', $uri) || $uri === '/' ? $uri : '/';
}

/**
 * Cabecera común.
 * $user: getSessionUser() o null. $active: 'explore' | 'saved' | 'teach' | ''.
 */
function iarepo_header(?array $user, string $active = ''): void
{
    $isStudent = ($user['role'] ?? '') === 'student';
    $signin    = '/auth/signin.php?return_url=' . rawurlencode(iarepo_current_path());
    $saved     = $user ? '/favorites/' : '/auth/signin.php?return_url=' . rawurlencode('/favorites/');
    $teach     = $user ? '/dashboard/' : '/auth/signin.php?return_url=' . rawurlencode('/dashboard/');
    $toLang    = lang() === 'en' ? 'es' : 'en';

    $links = [['explore', '/', t('Explorar')], ['saved', $saved, t('Guardados')]];
    if (!$isStudent)
        $links[] = ['teach', $teach, t('Para docentes')];

    $nav = static function () use ($links, $active): string {
        $out = '';
        foreach ($links as [$key, $href, $label])
            $out .= '<a href="' . iarepo_e($href) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . iarepo_e($label) . '</a>';
        return $out;
    };
    ?>
<a class="ia-sr-only" href="#main"><?= iarepo_e(t('Saltar al contenido')) ?></a>
<header class="ia-header">
  <div class="ia-container ia-header-inner">
    <a class="ia-logo" href="/"><img src="/assets/img/logo-icon.svg" alt="" width="30" height="30"><span>iarepo</span></a>
    <nav class="ia-nav" aria-label="<?= iarepo_e(t('Principal')) ?>"><?= $nav() ?></nav>
    <div class="ia-header-tools">
      <a class="ia-btn ia-btn-ghost ia-btn-sm" id="lang-switch" href="<?= iarepo_e(langSwitchUrl($toLang)) ?>"
         hreflang="<?= $toLang ?>" title="<?= $toLang === 'en' ? 'Switch to English' : 'Cambiar a español' ?>"
         aria-label="<?= $toLang === 'en' ? 'Switch to English' : 'Cambiar a español' ?>"><i data-lucide="globe"></i><?= strtoupper($toLang) ?></a>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon" data-theme-toggle aria-label="<?= iarepo_e(t('Cambiar tema')) ?>" title="<?= iarepo_e(t('Cambiar tema')) ?>">
        <i data-lucide="moon" class="ia-when-light"></i><i data-lucide="sun" class="ia-when-dark"></i>
      </button>
      <?php if ($user): ?>
        <a class="ia-btn ia-btn-ghost ia-btn-sm" href="/profile/<?= (int) ($user['id'] ?? 0) ?>" title="<?= iarepo_e(t('Mi perfil')) ?>">
          <?php if (!empty($user['avatar_url'])): ?><img class="ia-avatar" src="<?= iarepo_e($user['avatar_url']) ?>" alt="" width="32" height="32" referrerpolicy="no-referrer"><?php else: ?><i data-lucide="user-round"></i><?php endif; ?>
          <span class="ia-hide-xs"><?= iarepo_e(explode(' ', (string) ($user['name'] ?? ''))[0]) ?></span>
        </a>
      <?php else: ?>
        <a class="ia-btn ia-btn-primary ia-btn-sm" href="<?= iarepo_e($signin) ?>"><?= iarepo_e(t('Entrar')) ?></a>
      <?php endif; ?>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon ia-menu-btn" data-menu-toggle aria-expanded="false" aria-controls="ia-mobile-nav" aria-label="<?= iarepo_e(t('Menú')) ?>"><i data-lucide="menu"></i></button>
    </div>
  </div>
  <nav class="ia-container ia-mobile-nav" id="ia-mobile-nav" aria-label="<?= iarepo_e(t('Principal')) ?>">
    <?= $nav() ?>
    <?php if ($user): ?><a href="/auth/logout.php"><?= iarepo_e(t('Salir')) ?></a><?php endif; ?>
  </nav>
</header>
<?php
}

/** Pie común. A los alumnos no se les invita a publicar. */
function iarepo_footer(?array $user = null): void
{
    $isStudent = ($user['role'] ?? '') === 'student';
    $publish   = $user ? '/dashboard/editor.php' : '/auth/signin.php?return_url=' . rawurlencode('/dashboard/editor.php');
    ?>
<footer class="ia-footer">
  <div class="ia-container ia-footer-inner">
    <?php if (!$isStudent): ?>
    <div class="ia-cta-band">
      <p><?= iarepo_e(t('¿Has hecho una simulación con IA para tu clase? Compártela con otros docentes.')) ?></p>
      <a class="ia-btn ia-btn-primary" href="<?= iarepo_e($publish) ?>"><i data-lucide="upload"></i><?= iarepo_e(t('Publicar un recurso')) ?></a>
    </div>
    <?php endif; ?>
    <p><strong>iarepo</strong> — <?= iarepo_e(t('Simulaciones gratuitas de ciencias y matemáticas, clasificadas por curso. Los recursos externos pertenecen a sus autores: iarepo los elige, los enlaza y cita la fuente.')) ?></p>
    <div class="ia-footer-links">
      <a href="/legal/terms.php"><?= iarepo_e(t('Términos y privacidad')) ?></a>
      <a href="https://github.com/jacksonsmirnov-del/iarepo" rel="noopener"><?= iarepo_e(t('Código abierto (MIT)')) ?></a>
      <a href="https://claseprivada.com">Clase Privada</a>
    </div>
  </div>
</footer>
<div class="ia-toast" id="ia-toast" role="status" aria-live="polite"></div>
<?php
}

/**
 * Portada generativa de un recurso (color de la materia + icono + fuente).
 * $r: fila de resources con category_slug/category_icon (o con las etiquetas
 * de iarepo_with_labels()). $topic: texto opcional abajo a la izquierda.
 */
function iarepo_cover(array $r, ?string $topic = null): string
{
    $r    = isset($r['subject_class']) ? $r : iarepo_with_labels($r);
    $icon = preg_match('/^[a-z0-9-]{2,40}$/', (string) ($r['category_icon'] ?? '')) ? $r['category_icon'] : 'sparkles';
    $out  = '<div class="ia-cover ' . iarepo_e($r['subject_class']) . '" aria-hidden="true">';
    if (!empty($r['source_label']))
        $out .= '<span class="ia-cover-source"><span class="ia-cover-mono">' . iarepo_e($r['source_mono']) . '</span>' . iarepo_e($r['source_label']) . '</span>';
    $out .= '<span class="ia-cover-icon"><i data-lucide="' . iarepo_e($icon) . '"></i></span>';
    $topic ??= trim((string) ($r['topic_tag'] ?? '')) !== '' ? (string) $r['topic_tag'] : ($r['category_label'] ?? '');
    if ($topic !== '')
        $out .= '<span class="ia-cover-topic">' . iarepo_e($topic) . '</span>';
    if (($r['lang'] ?? '') === 'en' && lang() !== 'en')
        $out .= '<span class="ia-cover-badge">' . iarepo_e(t('En inglés')) . '</span>';
    return $out . '</div>';
}

/**
 * Diálogo «Mandar a mis alumnos» (QR + dirección corta + Classroom).
 * Se imprime UNA vez por página; assets/js/ui.js lo rellena con
 * IA.openSend({id, title}). El QR se genera en el navegador
 * (assets/js/qrcode.js): la dirección no sale a ningún servicio externo.
 */
function iarepo_send_dialog(): void
{
    ?>
<dialog class="ia-dialog" id="ia-send" aria-labelledby="ia-send-title">
  <div class="ia-dialog-inner">
    <div class="ia-dialog-head">
      <div><h2 id="ia-send-title"><?= iarepo_e(t('Mandar a mis alumnos')) ?></h2><p class="ia-muted ia-small" data-send-name></p></div>
      <button type="button" class="ia-btn ia-btn-ghost ia-btn-icon ia-dialog-close" data-dialog-close aria-label="<?= iarepo_e(t('Cerrar')) ?>"><i data-lucide="x"></i></button>
    </div>
    <div class="ia-qr">
      <div class="ia-qr-code" data-send-qr></div>
      <ol class="ia-steps">
        <li><span class="ia-step-n">1</span><span><strong><?= iarepo_e(t('Que lo escaneen con la cámara del móvil o la tableta.')) ?></strong><br><span class="ia-muted ia-small"><?= iarepo_e(t('Se abre directamente, sin registrarse.')) ?></span></span></li>
        <li><span class="ia-step-n">2</span><span><strong><?= iarepo_e(t('O que escriban esta dirección:')) ?></strong><div class="ia-shorturl" data-send-url></div></span></li>
      </ol>
    </div>
    <div class="ia-dialog-actions">
      <button type="button" class="ia-btn ia-btn-primary" data-send-project><i data-lucide="presentation"></i><?= iarepo_e(t('Proyectar el código')) ?></button>
      <button type="button" class="ia-btn ia-btn-secondary" data-send-copy><i data-lucide="copy"></i><?= iarepo_e(t('Copiar enlace')) ?></button>
      <a class="ia-btn ia-btn-secondary" data-send-classroom target="_blank" rel="noopener"><i data-lucide="school"></i>Google Classroom</a>
    </div>
    <p class="ia-note"><i data-lucide="shield-check" style="width:16px;height:16px;vertical-align:-3px"></i> <?= iarepo_e(t('Tus alumnos no necesitan cuenta ni correo para abrirlo.')) ?></p>
  </div>
</dialog>
<div class="ia-qr-full" id="ia-qr-full" role="dialog" aria-modal="true" aria-label="<?= iarepo_e(t('Código QR para los alumnos')) ?>">
  <div><div class="ia-qr-code" data-send-qr-big></div><p data-send-url-big></p></div>
</div>
<?php
}
