<?php
// ================================================================
// dashboard/editor.php — Publicar un recurso (o editar uno tuyo)
//
// Objetivo: que publicar sea FÁCIL. A la vista solo lo imprescindible:
//   · qué publicas: HTML generado por una IA, la dirección de una simulación
//     que ya existe (PhET, GeoGebra…) o un código para insertar;
//   · título y contenido (obligatorios);
//   · materia y curso/edad (lo que usa el catálogo para encontrarlo).
// Todo lo demás —descripción, idioma, visibilidad, etiquetas y la «Fuente
// original» de un enlace— va plegado en «Más opciones».
//
// Decisiones:
//   · subject_area ya no se escribe a mano (duplicaba la materia y cada cual
//     la escribía a su manera). Se rellena con la etiqueta de la materia
//     elegida; el buscador la usa (AGENTS.md §7).
//   · En un recurso 'url' la dirección es también source_url: con eso la
//     ficha dice «Creado por …» y la lista negra de URLs retiradas funciona.
//   · Visibilidad: «Pública» o «Solo tú (borrador)». NO se ofrece «Tu
//     centro»: en iarepo.com todas las cuentas comparten el tenant 0
//     (shared/auth.php), así que 'school' significaría «cualquiera con
//     cuenta», no tu centro. Si un recurso ya la tiene, se conserva.
//   · Los errores de la API se traducen por CÓDIGO (T.err), nunca por texto.
//
// Piezas comunes: shared/ui.php, shared/labels.php y assets/css/app.css. El
// <style> de abajo es solo lo propio del editor (prefijo .ed-).
// Antirregresión: tests/unit/account_pages_test.php.
// ================================================================

// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ui.php';
require_once __DIR__ . '/../shared/access.php';
require_once __DIR__ . '/../shared/srcdoc.php';   // la vista previa se comporta como la ficha
// h() local — NO se carga shared/helpers.php: su error_handler vuelca JSON y
// corta la página a medias ante cualquier error (CLAUDE.md §2.1).
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

$user = getSessionUser();
if (!$user) { header('Location: /auth/signin.php?return_url=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard/editor.php'))); exit; }
// Publicar es para docentes; quien está aprendiendo va a sus Guardados.
if (($user['role'] ?? '') === 'student') { header('Location: /favorites/'); exit; }

$db = getResourcesDB();
$editId = (int)($_GET['id'] ?? 0);
$resource = null;

$existingTags = [];
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM resources WHERE id = ? AND author_user_id = ? AND author_tenant_id = 0 AND is_active = 1");
    $stmt->execute([$editId, $user['id']]);
    $resource = $stmt->fetch();
    if (!$resource) { header('Location: /dashboard/'); exit; }
    $tagRows = $db->prepare("SELECT tag FROM resource_tags WHERE resource_id = ? ORDER BY tag");
    $tagRows->execute([$editId]);
    $existingTags = $tagRows->fetchAll(PDO::FETCH_COLUMN);
}
$isEdit = $resource !== null;
$v = static fn(string $k, string $default = ''): string => $isEdit ? (string) ($resource[$k] ?? $default) : $default;

// ¿Es TU VERSIÓN de otro recurso (un fork)? «Hacer mi versión» aterriza aquí
// con el mismo título que el original, y sin decirlo no se sabe si se está
// tocando el original ni quién lo ve. El original solo se nombra si quien
// edita puede verlo (la misma regla que la ficha: shared/access.php).
$isFork = $isEdit && (int) ($resource['fork_of'] ?? 0) > 0;
$forkOf = null;
if ($isFork) {
    $o = $db->prepare("SELECT id, title, author_display_name, visibility, author_tenant_id, author_user_id
                       FROM resources WHERE id = ? AND is_active = 1");
    $o->execute([(int) $resource['fork_of']]);
    $row = $o->fetch();
    if ($row && canView($row, authenticate()))
        $forkOf = $row;
}

// Materias: etiqueta traducida (shared/labels.php), ordenadas por etiqueta.
// Se incluyen las inactivas solo si es la del recurso que se edita.
$cats = $db->query("SELECT id, name, slug, is_active FROM categories ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$cats = array_values(array_filter($cats, static fn($c) => (int) $c['is_active'] === 1 || (int) $c['id'] === (int) $v('category_id', '0')));
foreach ($cats as &$c)
    $c['label'] = iarepo_category_label($c['slug'], $c['name']);
unset($c);
usort($cats, static fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

// Tipo de contenido. Los tres de siempre a la vista; 'prompt', 'python' y
// 'other' solo aparecen si el recurso que se edita ya es de ese tipo (no se
// le cambia el tipo a nadie por no ofrecerlo).
$types = [
    'html'  => [t('HTML hecho con IA'), 'sparkles'],
    'url'   => [t('Enlace a una simulación'), 'link'],
    'embed' => [t('Código para insertar'), 'code'],
];
$legacyTypes = ['prompt' => [t('Prompt para IA'), 'message-square'], 'python' => [t('Código Python'), 'file-code'], 'other' => [t('Otro'), 'file']];
$currentType = $v('code_type', 'html');
if (isset($legacyTypes[$currentType]))
    $types[$currentType] = $legacyTypes[$currentType];
if (!isset($types[$currentType]))
    $currentType = 'html';

// Curso y edad: las claves de la API. Un nivel antiguo que no esté entre
// ellas ('bachillerato', 'eso'…) se conserva como opción para no pisarlo.
$levels = ['general' => iarepo_level_label('general')] + iarepo_level_options();
$currentLevel = $v('level', 'general') ?: 'general';
if (!isset($levels[$currentLevel]))
    $levels[$currentLevel] = iarepo_level_label($currentLevel);

// Visibilidad (ver la cabecera: sin «Tu centro» para cuentas de iarepo.com).
$visibilities = ['community' => t('Pública'), 'draft' => t('Solo tú (borrador)')];
$currentVis = $v('visibility', 'community');
if (!isset($visibilities[$currentVis]))
    $visibilities[$currentVis] = t('Con cuenta en iarepo');

$langs = ['es' => t('Español'), 'en' => t('Inglés'), 'pt' => t('Portugués')];
$currentLang = $v('lang', lang() === 'en' ? 'en' : 'es');

$pageTitle = $isFork ? t('Tu versión') : ($isEdit ? t('Editar recurso') : t('Publicar un recurso'));
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — iarepo</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#F6F7F9">
<?= iarepo_head_assets() ?>
<?= iarepo_pwa_script() ?>
<style>
/* Solo lo propio del editor (.ed-*). Lo común sale de assets/css/app.css. */
.ed-main { padding-top: 20px; }
.ed-back { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; font-size: .95rem; margin-bottom: 8px; }
.ed-back svg { width: 18px; height: 18px; }
.ed-main h1 { font-size: clamp(1.7rem, 1.35rem + 1.3vw, 2.3rem); margin-bottom: 6px; }
.ed-intro { max-width: 62ch; margin-bottom: 18px; }
.ed-fork { display: flex; gap: 10px; align-items: flex-start; max-width: 70ch; margin-bottom: 18px; padding: 12px 14px; border: 1px solid var(--ia-line); border-left: 4px solid var(--ia-accent); border-radius: var(--ia-radius); background: var(--ia-surface); }
.ed-fork svg { flex: none; width: 20px; height: 20px; margin-top: 2px; color: var(--ia-accent); }
.ed-fork p { margin: 0; }

.ed-layout { display: grid; gap: 20px; grid-template-columns: 1fr; }
@media (min-width: 960px) { .ed-layout { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; } }
.ed-switch { display: flex; gap: 8px; margin-bottom: 14px; }
@media (min-width: 960px) { .ed-switch { display: none; } }
@media (max-width: 959px) {
  .ed-layout.show-form .ed-preview { display: none; }
  .ed-layout.show-preview .ed-form { display: none; }
}

.ed-form { display: grid; gap: 16px; min-width: 0; }
.ed-field label, .ed-legend { display: block; font-weight: 700; font-size: .95rem; margin-bottom: 6px; padding: 0; }
.ed-field .ia-input, .ed-field .ia-select { width: 100%; }
.ed-req { color: var(--ia-danger); }
.ed-hint { font-size: .875rem; color: var(--ia-ink-3); margin: 6px 0 0; }
.ed-row { display: grid; gap: 16px; grid-template-columns: 1fr; }
@media (min-width: 560px) { .ed-row { grid-template-columns: 1fr 1fr; } }
fieldset.ed-types { border: 0; margin: 0; padding: 0; min-width: 0; }
.ed-types .ia-chips { gap: 8px; }
/* Radios reales dentro de un chip: el marcado es accesible sin JS y el
   estado se pinta con :has(), con .is-active como respaldo (lo pone el JS). */
.ed-types .ia-chip:has(input:checked), .ed-types .ia-chip.is-active { background: var(--ia-ink); border-color: var(--ia-ink); color: var(--ia-bg); }
.ed-types .ia-chip:has(input:focus-visible) { outline: 3px solid var(--ia-accent); outline-offset: 2px; }
.ed-types .ia-chip svg { width: 18px; height: 18px; }
textarea.ed-code { min-height: 280px; font: 13px/1.5 var(--ia-mono); tab-size: 2; resize: vertical; }
textarea.ed-desc { min-height: 80px; resize: vertical; line-height: 1.45; }

.ed-more { border: 1px solid var(--ia-line); border-radius: var(--ia-radius); background: var(--ia-surface); }
.ed-more > summary { cursor: pointer; padding: 12px 16px; font-weight: 700; list-style: none; display: flex; align-items: center; gap: 8px; min-height: var(--ia-tap); }
.ed-more > summary::-webkit-details-marker { display: none; }
.ed-more > summary::before { content: "▸"; color: var(--ia-ink-3); transition: transform .15s; }
.ed-more[open] > summary::before { transform: rotate(90deg); }
.ed-more-body { display: grid; gap: 16px; padding: 4px 16px 16px; }

.ed-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 6px 10px; min-height: var(--ia-tap);
  background: var(--ia-surface); border: 2px solid var(--ia-line-strong); border-radius: 12px; cursor: text; }
.ed-tags:focus-within { border-color: var(--ia-accent); }
.ed-tags input { flex: 1; min-width: 120px; border: 0; outline: 0; background: transparent; font: 500 .95rem/1.2 var(--ia-font); color: var(--ia-ink); padding: 4px 2px; }
.ed-tag { display: inline-flex; align-items: center; gap: 2px; padding: 2px 4px 2px 10px; border-radius: 999px; background: var(--ia-accent-soft); color: var(--ia-accent); font-weight: 600; font-size: .875rem; }
.ed-tag button { display: grid; place-items: center; width: 24px; height: 24px; border: 0; border-radius: 50%; background: none; color: inherit; cursor: pointer; font-size: 1rem; line-height: 1; }
.ed-tag button:hover { background: rgba(0, 0, 0, .08); }

.ed-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; }
.ed-status { margin: 0; padding: 10px 14px; border-radius: 10px; font-weight: 600; }
.ed-status.is-ok { background: var(--ia-ok-soft); color: var(--ia-ok); }
.ed-status.is-error { background: color-mix(in srgb, var(--ia-danger) 12%, transparent); color: var(--ia-danger); }

.ed-preview { background: var(--ia-surface); border: 1px solid var(--ia-line); border-radius: var(--ia-radius); overflow: hidden; display: flex; flex-direction: column; min-height: 60vh; }
@media (min-width: 960px) { .ed-preview { position: sticky; top: 80px; height: calc(100vh - 100px); } }
.ed-preview-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 8px 8px 16px; border-bottom: 1px solid var(--ia-line); font-weight: 700; }
.ed-preview iframe { flex: 1; width: 100%; min-height: 420px; border: 0; background: #fff; }
.ed-preview-empty { padding: 24px 16px; color: var(--ia-ink-3); margin: 0; }
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($user, 'teach'); ?>

<main id="main" class="ia-container ed-main">
  <a class="ed-back" href="/dashboard/"><i data-lucide="chevron-left"></i><?= h(t('Mi panel')) ?></a>
  <h1><?= h($pageTitle) ?></h1>
  <?php if ($isFork): ?>
    <div class="ed-fork" id="forkNote" role="note">
      <i data-lucide="git-branch"></i>
      <p><strong><?= h(t('Esta es tu copia: el original no se toca.')) ?></strong>
      <?php if ($forkOf): ?>
        <?php $origLink = '<a href="/resource/' . (int) $forkOf['id'] . '">' . h((string) $forkOf['title']) . '</a>'; ?>
        <?= trim((string) $forkOf['author_display_name']) !== ''
            ? sprintf(h(t('Parte de «%s», de %s.')), $origLink, h((string) $forkOf['author_display_name']))
            : sprintf(h(t('Parte de «%s».')), $origLink) ?>
      <?php endif; ?>
      <?php if ($currentVis === 'draft'): ?>
        <?= h(t('Es un borrador: solo la ves tú. Cámbiala a tu gusto y, cuando quieras compartirla, elige «Pública» en «Quién puede verlo».')) ?>
      <?php endif; ?></p>
    </div>
  <?php endif; ?>
  <?php if ($isEdit): ?>
    <p class="ia-muted ed-intro"><?= h(t('Los cambios se guardan como una versión nueva: la anterior no se pierde.')) ?>
      <a href="/resource/<?= $editId ?>"><?= h(t('Ver la ficha')) ?></a></p>
  <?php else: ?>
    <p class="ia-muted ed-intro"><?= h(t('Pega el HTML que te ha hecho una IA o la dirección de una simulación que ya exista. En un minuto está en el catálogo, con tu nombre y citando a su autor.')) ?></p>
  <?php endif; ?>

  <div class="ed-switch" role="group" aria-label="<?= h(t('Vista')) ?>">
    <button type="button" class="ia-chip" data-panel="form" aria-pressed="true"><i data-lucide="pencil"></i><?= h(t('Editar')) ?></button>
    <button type="button" class="ia-chip" data-panel="preview" aria-pressed="false"><i data-lucide="eye"></i><?= h(t('Vista previa')) ?></button>
  </div>

  <div class="ed-layout show-form" id="edLayout">
    <form class="ed-form" id="edForm" novalidate>
      <fieldset class="ed-types">
        <legend class="ed-legend"><?= h(t('¿Qué vas a publicar?')) ?></legend>
        <div class="ia-chips">
          <?php foreach ($types as $key => [$label, $icon]): ?>
          <label class="ia-chip<?= $key === $currentType ? ' is-active' : '' ?>">
            <input class="ia-sr-only" type="radio" name="codeType" value="<?= h($key) ?>"<?= $key === $currentType ? ' checked' : '' ?>>
            <i data-lucide="<?= h($icon) ?>"></i><?= h($label) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="ed-hint" id="typeHint"></p>
      </fieldset>

      <div class="ed-field">
        <label for="title"><?= h(t('Título')) ?> <span class="ed-req" aria-hidden="true">*</span></label>
        <input type="text" class="ia-input" id="title" maxlength="255" required autocomplete="off"
               placeholder="<?= h(t('Ej.: Caída libre con y sin rozamiento')) ?>" value="<?= h($v('title')) ?>">
      </div>

      <div class="ed-field" data-show="url">
        <label for="codeUrl"><?= h(t('Dirección de la simulación')) ?> <span class="ed-req" aria-hidden="true">*</span></label>
        <input type="url" class="ia-input" id="codeUrl" maxlength="500" inputmode="url" autocomplete="off"
               placeholder="https://phet.colorado.edu/sims/html/…" value="<?= $currentType === 'url' ? h($v('code_content')) : '' ?>">
        <p class="ed-hint"><?= h(t('Se abrirá en su web original y la ficha citará a su autor.')) ?></p>
      </div>

      <div class="ed-field" data-show="html embed prompt python other">
        <label for="codeContent" id="codeLabel"><?= h(t('Contenido')) ?> <span class="ed-req" aria-hidden="true">*</span></label>
        <textarea class="ia-input ed-code" id="codeContent" spellcheck="false" autocomplete="off"><?= $currentType !== 'url' ? h($v('code_content')) : '' ?></textarea>
      </div>

      <div class="ed-row">
        <div class="ed-field">
          <label for="categoryId"><?= h(t('Materia')) ?></label>
          <select class="ia-select" id="categoryId">
            <option value=""><?= h(t('Elige la materia')) ?></option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int) $c['id'] ?>" data-label="<?= h($c['label']) ?>"<?= (int) $c['id'] === (int) $v('category_id', '0') ? ' selected' : '' ?>><?= h($c['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ed-field">
          <label for="level"><?= h(t('Curso y edad')) ?></label>
          <select class="ia-select" id="level">
            <?php foreach ($levels as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $key === $currentLevel ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <details class="ed-more" id="more"<?= $isEdit ? ' open' : '' ?>>
        <summary><?= h(t('Más opciones')) ?></summary>
        <div class="ed-more-body">
          <div class="ed-field">
            <label for="description"><?= h(t('Descripción')) ?></label>
            <textarea class="ia-input ed-desc" id="description" maxlength="2000"
                      placeholder="<?= h(t('Qué se aprende con él y cómo usarlo en clase (opcional)')) ?>"><?= h($v('description')) ?></textarea>
          </div>
          <div class="ed-row">
            <div class="ed-field">
              <label for="lang"><?= h(t('Idioma del recurso')) ?></label>
              <select class="ia-select" id="lang">
                <?php foreach ($langs as $key => $label): ?>
                  <option value="<?= $key ?>"<?= $key === $currentLang ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="ed-field">
              <label for="visibility"><?= h(t('Quién puede verlo')) ?></label>
              <select class="ia-select" id="visibility">
                <?php foreach ($visibilities as $key => $label): ?>
                  <option value="<?= h($key) ?>"<?= $key === $currentVis ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="ed-hint"><?= h(t('Las públicas aparecen en el catálogo; un borrador solo lo ves tú.')) ?></p>
            </div>
          </div>
          <div class="ed-field" data-show="url">
            <label for="sourceName"><?= h(t('Fuente original')) ?></label>
            <input type="text" class="ia-input" id="sourceName" maxlength="150" autocomplete="off"
                   placeholder="<?= h(t('Ej.: PhET Interactive Simulations')) ?>" value="<?= h($v('source_name')) ?>">
            <p class="ed-hint"><?= h(t('Quién lo creó. Si lo dejas vacío, se deduce de la dirección (PhET, GeoGebra, NASA…).')) ?></p>
          </div>
          <div class="ed-field">
            <label for="tagInput"><?= h(t('Etiquetas')) ?></label>
            <div class="ed-tags" id="tagWrap">
              <input type="text" id="tagInput" maxlength="50" autocomplete="off" placeholder="<?= h(t('Ej.: gravedad, energía')) ?>" aria-describedby="tagHint">
            </div>
            <p class="ed-hint" id="tagHint"><?= h(t('Pulsa Intro o coma para añadir cada una. Hasta 20.')) ?></p>
          </div>
        </div>
      </details>

      <p class="ed-status" id="statusMsg" role="status" aria-live="polite" hidden></p>
      <div class="ed-actions">
        <a href="/dashboard/" class="ia-btn ia-btn-secondary"><?= h(t('Cancelar')) ?></a>
        <button type="submit" class="ia-btn ia-btn-primary" id="saveBtn"><i data-lucide="<?= $isEdit ? 'save' : 'upload' ?>"></i><span><?= $isEdit ? h(t('Guardar cambios')) : h(t('Publicar recurso')) ?></span></button>
      </div>
    </form>

    <section class="ed-preview" aria-labelledby="previewTitle">
      <div class="ed-preview-head">
        <span id="previewTitle"><?= h(t('Vista previa')) ?></span>
        <button type="button" class="ia-btn ia-btn-ghost ia-btn-sm" id="refreshPreview"><i data-lucide="refresh-cw"></i><?= h(t('Actualizar')) ?></button>
      </div>
      <p class="ed-preview-empty" id="previewEmpty"><?= h(t('Aquí verás tu recurso funcionando en cuanto pegues el contenido.')) ?></p>
      <!-- Sin allow-same-origin: el código del autor no puede tocar la
           sesión de quien edita (misma regla que el visor). -->
      <iframe id="previewFrame" title="<?= h(t('Vista previa')) ?>" sandbox="allow-scripts allow-modals allow-popups" hidden></iframe>
    </section>
  </div>
</main>

<?php iarepo_footer($user, false); /* sin la banda «Publicar»: ya estás publicando */ ?>
<?= iarepo_body_assets() ?>
<script>
const EDIT_ID = <?= $editId ?: 'null' ?>;
// La vista previa monta el srcdoc como la ficha y el visor: enlaces internos
// dentro del recurso y localStorage de respaldo (shared/srcdoc.php).
<?= iarepo_srcdoc_js() ?>
// JSON_HEX_TAG | JSON_HEX_AMP: un título o una etiqueta con «<!--<script»
// dentro de un <script> dejaba la página en blanco (el parser HTML se lo tragaba).
const ORIG = <?= json_encode([
    'categoryId' => $isEdit ? (string) ($resource['category_id'] ?? '') : '',
    'subject'    => $v('subject_area'),
    // Para no pisar la fuente al editar un enlace (ver «Guardar» más abajo).
    'code'       => $currentType === 'url' ? $v('code_content') : '',
    'sourceUrl'  => $v('source_url'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const tags = new Set(<?= json_encode(array_values($existingTags), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
const T = {
  titleReq: <?= json_encode(t('Ponle un título.')) ?>,
  contentReq: <?= json_encode(t('Falta el contenido.')) ?>,
  urlReq: <?= json_encode(t('Pega la dirección de la simulación (empieza por https://).')) ?>,
  saving: <?= json_encode(t('Guardando...')) ?>,
  publishing: <?= json_encode(t('Publicando...')) ?>,
  saveChanges: <?= json_encode(t('Guardar cambios')) ?>,
  publishResource: <?= json_encode(t('Publicar recurso')) ?>,
  updated: <?= json_encode(t('Cambios guardados.')) ?>,
  published: <?= json_encode(t('¡Publicado! Abriendo tu recurso…')) ?>,
  pendingReview: <?= json_encode(t('¡Publicado! Lo revisaremos automáticamente en unos minutos. Abriendo tu recurso…')) ?>,
  unsaved: <?= json_encode(t('Tienes cambios sin guardar.')) ?>,
  removeTag: <?= json_encode(t('Quitar la etiqueta %s')) ?>,
  label: {
    html: <?= json_encode(t('Pega aquí el HTML')) ?>,
    embed: <?= json_encode(t('Pega aquí el código para insertar')) ?>,
    prompt: <?= json_encode(t('Escribe aquí el prompt')) ?>,
    python: <?= json_encode(t('Pega aquí el código Python')) ?>,
    other: <?= json_encode(t('Contenido')) ?>,
  },
  hint: {
    html: <?= json_encode(t('Pídele a Gemini, ChatGPT o Claude una simulación interactiva en un solo fichero HTML y pega aquí su respuesta completa.')) ?>,
    url: <?= json_encode(t('La dirección de una simulación que ya existe en otra web (PhET, GeoGebra, NASA…).')) ?>,
    embed: <?= json_encode(t('El código que da la web original para insertarla en otra página (búscalo como «Insertar» o «Embed»).')) ?>,
    prompt: <?= json_encode(t('El texto que le pediste a la IA. Otros docentes podrán reutilizarlo.')) ?>,
    python: <?= json_encode(t('Código Python para leer o copiar.')) ?>,
    other: '',
  },
  placeholder: {
    html: <?= json_encode(t('<!-- Pega aquí el HTML generado con Gemini, ChatGPT o Claude -->')) ?>,
    embed: '<iframe src="…" width="100%" height="500"></iframe>',
    prompt: <?= json_encode(t('Escribe el prompt que usaste para generar el recurso. Otros profesores podrán replicarlo y adaptarlo.')) ?>,
    python: '# Python',
    other: '',
  },
  // Errores de la API: por CÓDIGO, nunca por el texto (que va en inglés y
  // es un contrato con Campus, no un mensaje para la persona).
  err: {
    MISSING_TITLE: <?= json_encode(t('Ponle un título.')) ?>,
    INVALID_URL: <?= json_encode(t('La dirección tiene que empezar por http:// o https://.')) ?>,
    INVALID_SOURCE_URL: <?= json_encode(t('La dirección tiene que empezar por http:// o https://.')) ?>,
    INVALID_SOURCE_NAME: <?= json_encode(t('Revisa el nombre de la fuente original.')) ?>,
    DUPLICATE_OWN: <?= json_encode(t('Ya publicaste este mismo contenido. Búscalo en Mi panel y edítalo allí.')) ?>,
    DUPLICATE_CONTENT: <?= json_encode(t('Ese contenido ya está publicado en iarepo por otra persona.')) ?>,
    BLACKLISTED_URL: <?= json_encode(t('Esa dirección se retiró del catálogo porque dejó de funcionar. Prueba con otra fuente.')) ?>,
    BLACKLISTED_DOMAIN: <?= json_encode(t('Esa web dejó de funcionar en varios recursos y está bloqueada. Prueba con otra fuente.')) ?>,
    DAILY_LIMIT: <?= json_encode(t('Has llegado al máximo de recursos nuevos por hoy. Podrás publicar más mañana.')) ?>,
    RATE_LIMITED: <?= json_encode(t('Demasiados cambios seguidos. Espera un minuto y vuelve a intentarlo.')) ?>,
    NOT_AUTHOR: <?= json_encode(t('Solo quien lo publicó puede editar este recurso.')) ?>,
    NOT_FOUND: <?= json_encode(t('Este recurso ya no existe.')) ?>,
    UPDATE_FAILED: <?= json_encode(t('No se pudo guardar. Inténtalo de nuevo en un momento.')) ?>,
    INVALID_JSON: <?= json_encode(t('No se pudo guardar. Inténtalo de nuevo en un momento.')) ?>,
    NETWORK: <?= json_encode(t('Sin conexión. Revisa la red y vuelve a intentarlo: no has perdido nada.')) ?>,
  },
  errStatus: {
    401: <?= json_encode(t('Tu sesión ha caducado. Entra de nuevo en otra pestaña y vuelve a pulsar el botón: no has perdido nada.')) ?>,
    403: <?= json_encode(t('Tu cuenta no puede publicar recursos.')) ?>,
  },
  errGeneric: <?= json_encode(t('No se pudo guardar el recurso')) ?>,
};

const $ = id => document.getElementById(id);
const form = $('edForm'), saveBtn = $('saveBtn'), statusMsg = $('statusMsg');
let dirty = false;   // cambios sin guardar (ver «beforeunload» más abajo)
const type = () => (form.querySelector('input[name="codeType"]:checked') || {}).value || 'html';

// ── Errores que nadie avisaría: al registro (/api/log-error.php) ──
function report(msg) {
  try {
    const body = JSON.stringify({ message: String(msg).slice(0, 500), source: 'editor', lineno: 0, page: location.pathname });
    if (!(navigator.sendBeacon && navigator.sendBeacon('/api/log-error.php', body)))
      fetch('/api/log-error.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
  } catch (e) { /* registrar un fallo no puede provocar otro */ }
}

function showStatus(kind, msg) {
  statusMsg.hidden = !msg;
  statusMsg.className = 'ed-status ' + (kind === 'error' ? 'is-error' : 'is-ok');
  statusMsg.textContent = msg || '';
}

// ── Tipo de contenido: qué campo se ve y qué ayuda sale ──────────
function applyType() {
  const t = type();
  form.querySelectorAll('.ed-types .ia-chip').forEach(l => l.classList.toggle('is-active', l.querySelector('input').checked));
  document.querySelectorAll('[data-show]').forEach(el => { el.hidden = !el.dataset.show.split(' ').includes(t); });
  $('typeHint').textContent = T.hint[t] || '';
  if (t !== 'url') {
    $('codeLabel').firstChild.textContent = (T.label[t] || T.label.other) + ' ';
    $('codeContent').placeholder = T.placeholder[t] || '';
  }
}
form.querySelectorAll('input[name="codeType"]').forEach(r => r.addEventListener('change', () => { applyType(); updatePreview(); }));
applyType();

// ── Etiquetas (chips con DOM, nada de onclick con texto interpolado) ──
const tagWrap = $('tagWrap'), tagInput = $('tagInput');
function renderTags() {
  tagWrap.querySelectorAll('.ed-tag').forEach(c => c.remove());
  tags.forEach(tag => {
    const chip = document.createElement('span');
    chip.className = 'ed-tag';
    chip.append(tag);
    const x = document.createElement('button');
    x.type = 'button';
    x.textContent = '×';
    x.setAttribute('aria-label', T.removeTag.replace('%s', tag));
    x.addEventListener('click', () => { tags.delete(tag); dirty = true; renderTags(); tagInput.focus(); });
    chip.append(x);
    tagWrap.insertBefore(chip, tagInput);
  });
}
function addTag(val) {
  const tag = val.toLowerCase().trim().replace(/,+$/, '').slice(0, 50);
  if (tag && tags.size < 20) { tags.add(tag); dirty = true; }
  renderTags();
}
tagWrap.addEventListener('click', e => { if (e.target === tagWrap) tagInput.focus(); });
tagInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault();
    if (tagInput.value.trim()) { addTag(tagInput.value); tagInput.value = ''; }
  } else if (e.key === 'Backspace' && !tagInput.value && tags.size) {
    tags.delete([...tags].pop()); dirty = true; renderTags();
  }
});
tagInput.addEventListener('blur', () => { if (tagInput.value.trim()) { addTag(tagInput.value); tagInput.value = ''; } });
renderTags();

// ── Vista previa ────────────────────────────────────────────────
const frame = $('previewFrame');
function updatePreview() {
  const t = type();
  const code = t === 'url' ? $('codeUrl').value.trim() : $('codeContent').value;
  const show = t === 'url' ? /^https?:\/\/\S+$/i.test(code) : code.trim() !== '';
  frame.hidden = !show;
  $('previewEmpty').hidden = show;
  if (!show) { frame.removeAttribute('srcdoc'); frame.removeAttribute('src'); return; }
  if (t === 'url') { frame.removeAttribute('srcdoc'); frame.src = code; }
  else if (t === 'html' || t === 'embed') iarepoSetSrcdoc(frame, iarepoSrcdoc(code));   // shared/srcdoc.php
  else frame.srcdoc = '<pre style="padding:16px;font:13px/1.5 monospace;white-space:pre-wrap">' + code.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</pre>';
}
let previewTimer;
['codeContent', 'codeUrl'].forEach(id => $(id).addEventListener('input', () => {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(updatePreview, 700);
}));
$('refreshPreview').addEventListener('click', updatePreview);
updatePreview();

// Móvil: «Editar» / «Vista previa» (en escritorio van lado a lado).
document.querySelectorAll('.ed-switch [data-panel]').forEach(b => b.addEventListener('click', () => {
  const preview = b.dataset.panel === 'preview';
  document.querySelectorAll('.ed-switch [data-panel]').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
  $('edLayout').classList.toggle('show-preview', preview);
  $('edLayout').classList.toggle('show-form', !preview);
  if (preview) updatePreview();
}));

// ── Cambios sin guardar: que un recargón no se lleve el HTML pegado ──
form.addEventListener('input', () => { dirty = true; });
form.addEventListener('change', () => { dirty = true; });
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = T.unsaved; } });

// ── Guardar ─────────────────────────────────────────────────────
// La materia rellena subject_area (el buscador la usa): si el recurso ya
// tenía una y no se cambió de materia, se conserva la suya.
function subjectArea() {
  const sel = $('categoryId');
  if (sel.value === ORIG.categoryId && ORIG.subject) return ORIG.subject;
  const opt = sel.options[sel.selectedIndex];
  return sel.value && opt ? opt.dataset.label : '';
}

let busy = false, created = false;   // sin dobles envíos ni duplicados
form.addEventListener('submit', async e => {
  e.preventDefault();
  if (busy || created) return;
  const t = type();
  const title = $('title').value.trim();
  const url = $('codeUrl').value.trim();
  const code = t === 'url' ? url : $('codeContent').value;
  if (!title) { showStatus('error', T.titleReq); $('title').focus(); return; }
  if (t === 'url' && !/^https?:\/\/\S+$/i.test(url)) { showStatus('error', url ? T.err.INVALID_URL : T.urlReq); $('codeUrl').focus(); return; }
  if (t !== 'url' && !code.trim()) { showStatus('error', T.contentReq); $('codeContent').focus(); return; }

  const body = {
    title,
    description: $('description').value.trim(),
    code_content: code,
    code_type: t,
    visibility: $('visibility').value,
    subject_area: subjectArea(),
    category_id: $('categoryId').value || null,
    level: $('level').value,
    lang: $('lang').value,
    tags: Array.from(tags),
  };
  // En un enlace, la dirección ES la fuente (ficha «Creado por …» y lista
  // negra de URLs retiradas). En los demás tipos no se toca.
  // Al EDITAR, solo si la fuente seguía a la dirección (o no había): muchos
  // enlaces citan la PÁGINA del autor (p. ej. la del laboratorio en
  // biointeractive.org) y apuntan a la simulación en otro sitio; mandarla
  // siempre sustituía esa página por la dirección de la simulación al
  // cambiar solo el título. Sin la clave, la API conserva la que había.
  if (t === 'url') {
    if (!EDIT_ID || !ORIG.sourceUrl || ORIG.sourceUrl === ORIG.code) body.source_url = url;
    body.source_name = $('sourceName').value.trim();
  }

  busy = true;
  saveBtn.disabled = true;
  saveBtn.querySelector('span').textContent = EDIT_ID ? T.saving : T.publishing;
  showStatus('', '');

  let res, data = null;
  try {
    res = await fetch(EDIT_ID ? `/api/resources.php?id=${EDIT_ID}` : '/api/resources.php', {
      method: EDIT_ID ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    try { data = await res.json(); } catch (_) { data = null; }
  } catch (_) {
    res = null;
  }

  if (res && data && data.ok) {
    dirty = false;
    if (EDIT_ID) {
      showStatus('ok', T.updated);
      IA.toast(T.updated);
      busy = false;
      saveBtn.disabled = false;
      saveBtn.querySelector('span').textContent = T.saveChanges;
    } else {
      // Creado: el botón queda bloqueado (así un doble clic no duplica) y se
      // abre la ficha. Si hay moderación, se avisa antes de irse.
      created = true;
      const pending = data.moderation_status === 'pending_review';
      showStatus('ok', pending ? T.pendingReview : (data.info || T.published));
      setTimeout(() => { location.href = data.id ? '/resource/' + parseInt(data.id, 10) : '/dashboard/'; }, pending ? 3200 : 1200);
    }
    return;
  }

  // Error: se explica por código; lo inesperado, además, se registra.
  const errCode = data && data.code ? data.code : (res ? '' : 'NETWORK');
  if (res && (!data || res.status >= 500))
    report(`${EDIT_ID ? 'PUT' : 'POST'} /api/resources.php → ${res.status}${data ? ' ' + (data.code || '') : ' (sin JSON)'}`);
  showStatus('error', T.err[errCode] || (res && T.errStatus[res.status]) || T.errGeneric);
  busy = false;
  saveBtn.disabled = false;
  saveBtn.querySelector('span').textContent = EDIT_ID ? T.saveChanges : T.publishResource;
});
</script>
</body>
</html>
