<?php
// ================================================================
// admin/create.php — Quick Resource Creator
//
// Simple admin page to paste HTML code from Gemini/Claude/ChatGPT
// and publish directly to iarepo. Protected by admin password.
// ================================================================

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/moderation.php';

// Admin password — change this in production
$config = require __DIR__ . '/../.env.php';
$ADMIN_PASS = $config['ADMIN_PASS'] ?? 'iarepo2026';

session_start();

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pass'])) {
    if ($_POST['admin_pass'] === $ADMIN_PASS) {
        $_SESSION['admin'] = true;
        header('Location: create.php');
        exit;
    }
    $loginError = 'Contraseña incorrecta';
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: create.php');
    exit;
}

// Check auth
if (empty($_SESSION['admin'])) {
    showLogin($loginError ?? null);
    exit;
}

$db = getResourcesDB();

// Load categories
$cats = $db->query("SELECT id, name, icon FROM categories ORDER BY name")->fetchAll();

// Handle create
$success = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $code = $_POST['code_content'] ?? '';
    $codeType = $_POST['code_type'] ?? 'html';
    $catId = (int) ($_POST['category_id'] ?? 0) ?: null;
    $level = $_POST['level'] ?? 'general';
    $lang = $_POST['lang'] ?? 'en';
    $sourcePrompt = trim($_POST['source_prompt'] ?? '');
    $sourceName = trim($_POST['source_name'] ?? '');
    $sourceUrl = trim($_POST['source_url'] ?? '');
    $visibility = $_POST['visibility'] ?? 'community';
    $tags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));

    if (!$title) {
        $error = 'Título requerido';
    } else {
        $hash = $code ? md5($code) : null;

        $stmt = $db->prepare("
            INSERT INTO resources (title, description, code_content, code_type, subject_area,
                lang, level, category_id, source_prompt, source_url, source_name,
                author_tenant_id, author_user_id, author_display_name, author_tenant_name,
                visibility, content_hash, moderation_status)
            VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, 0, 1, 'iarepo', '', ?, ?, 'approved')
        ");
        $stmt->execute([
            $title, $description, $code, $codeType,
            $lang, $level, $catId,
            $sourcePrompt ?: null, $sourceUrl ?: null, $sourceName ?: null,
            $visibility, $hash,
        ]);
        $newId = (int) $db->lastInsertId();

        // Save tags
        if ($tags) {
            $tagStmt = $db->prepare("INSERT IGNORE INTO resource_tags (resource_id, tag) VALUES (?, ?)");
            foreach (array_slice($tags, 0, 20) as $tag) {
                $tag = strtolower(trim($tag));
                if ($tag) $tagStmt->execute([$newId, $tag]);
            }
        }

        // Save version
        $db->prepare("
            INSERT INTO resource_versions (resource_id, version_number, code_content, editor_user_id, editor_display_name, change_description)
            VALUES (?, 1, ?, 1, 'iarepo', 'Initial version')
        ")->execute([$newId, $code]);

        $success = "✅ Recurso #{$newId} creado — <a href='/view/{$newId}' target='_blank'>Ver recurso</a>";
    }
}

function showLogin($error = null) {
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — iarepo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login{background:#fff;padding:40px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);width:320px;text-align:center}
.login h2{margin-bottom:20px;color:#1e293b}
.login input{width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:8px;font-size:1rem;margin-bottom:12px}
.login button{width:100%;padding:12px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer}
.err{color:#ef4444;font-size:.85rem;margin-bottom:12px}
</style></head><body>
<form class="login" method="POST">
<h2>🔑 iarepo Admin</h2>
<?php if ($error): ?><div class="err"><?= $error ?></div><?php endif; ?>
<input type="password" name="admin_pass" placeholder="Contraseña" autofocus>
<button type="submit">Entrar</button>
</form></body></html>
<?php exit; }
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Crear Recurso — iarepo Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1e293b;padding:24px}
.container{max-width:900px;margin:0 auto}
h1{font-size:1.5rem;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.subtitle{color:#64748b;margin-bottom:24px;font-size:.9rem}
.logout{float:right;color:#64748b;font-size:.85rem}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.9rem}
.alert.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.full{grid-column:1/-1}
label{display:block;font-size:.85rem;font-weight:500;margin-bottom:4px;color:#475569}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.9rem;background:#fff}
textarea{resize:vertical;min-height:120px}
textarea.code{font-family:'Courier New',monospace;font-size:.8rem;min-height:300px;background:#0f172a;color:#e2e8f0;border-color:#334155}
input:focus,select:focus,textarea:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.15)}
.btn{padding:12px 24px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;font-family:inherit;font-weight:600}
.btn:hover{background:#6d28d9}
.preview-btn{padding:8px 16px;background:#0891b2;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.85rem;margin-left:8px}
.actions{margin-top:20px;display:flex;gap:12px;align-items:center}
.preview-frame{width:100%;height:400px;border:1px solid #e2e8f0;border-radius:8px;margin-top:12px;display:none;background:#fff}
.tip{font-size:.75rem;color:#94a3b8;margin-top:4px}
</style>
</head><body>
<div class="container">
<a href="?logout" class="logout">Cerrar sesión</a>
<h1>📝 Crear Recurso</h1>
<p class="subtitle">Pega el código de Gemini/Claude/ChatGPT y publícalo directamente en iarepo.com</p>

<?php if ($success): ?><div class="alert ok"><?= $success ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= $error ?></div><?php endif; ?>

<form method="POST">
<div class="form-grid">
  <div class="full">
    <label>Título *</label>
    <input name="title" required placeholder="Ej: Pendulum Wave Simulator">
  </div>
  <div class="full">
    <label>Descripción</label>
    <textarea name="description" rows="2" placeholder="Describe el recurso..."></textarea>
  </div>
  <div>
    <label>Categoría</label>
    <select name="category_id">
      <option value="">— Seleccionar —</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Tipo</label>
    <select name="code_type">
      <option value="html">HTML (código interactivo)</option>
      <option value="url">URL (enlace externo)</option>
      <option value="embed">Embed</option>
      <option value="python">Python</option>
      <option value="prompt">AI Prompt</option>
    </select>
  </div>
  <div>
    <label>Nivel</label>
    <select name="level">
      <option value="general">General</option>
      <option value="primary">Primaria</option>
      <option value="secondary" selected>Secundaria</option>
      <option value="ib">IB</option>
      <option value="university">Universidad</option>
    </select>
  </div>
  <div>
    <label>Idioma</label>
    <select name="lang">
      <option value="en">English</option>
      <option value="es">Español</option>
      <option value="pt">Português</option>
    </select>
  </div>
  <div>
    <label>Visibilidad</label>
    <select name="visibility">
      <option value="community">Community (público)</option>
      <option value="draft">Draft (borrador)</option>
    </select>
  </div>
  <div>
    <label>Tags (separados por coma)</label>
    <input name="tags" placeholder="simulation, physics, interactive">
  </div>
  <div class="full">
    <label>Código / URL <button type="button" class="preview-btn" onclick="previewCode()">👁 Vista previa</button></label>
    <textarea name="code_content" class="code" id="code" placeholder="Pega aquí el código HTML de Gemini..."></textarea>
    <iframe class="preview-frame" id="preview" sandbox="allow-scripts"></iframe>
    <p class="tip">Para HTML: pega el código completo. Para URL: pega solo la URL.</p>
  </div>
  <div class="full">
    <label>Prompt original (opcional — se guarda para referencia)</label>
    <textarea name="source_prompt" rows="2" placeholder="El prompt que usaste en Gemini para generar este recurso..."></textarea>
  </div>
  <div>
    <label>Fuente original</label>
    <input name="source_name" placeholder="Ej: Recreated from NTNU VPL">
  </div>
  <div>
    <label>URL fuente original</label>
    <input name="source_url" placeholder="https://...">
  </div>
</div>
<div class="actions">
  <button type="submit" class="btn">🚀 Publicar recurso</button>
</div>
</form>
</div>

<script>
function previewCode() {
  const code = document.getElementById('code').value;
  const frame = document.getElementById('preview');
  frame.style.display = frame.style.display === 'none' ? 'block' : 'none';
  if (frame.style.display === 'block') {
    frame.srcdoc = code;
  }
}
</script>
</body></html>
