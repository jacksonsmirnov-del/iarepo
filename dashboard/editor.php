<?php
// ================================================================
// dashboard/editor.php — Resource Editor
//
// Create new resources or edit existing ones.
// Requires Google Sign-In session.
// ================================================================

session_start();
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';
require_once __DIR__ . '/../shared/i18n.php';
lang();

$user = getSessionUser();
if (!$user) { header('Location: /'); exit; }

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

// Fetch categories for dropdown
$cats = $db->query("SELECT id, name, icon FROM categories ORDER BY name")->fetchAll();
$isEdit = $resource !== null;
$pageTitle = $isEdit ? t('Editar Recurso') : t('Nuevo Recurso');
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — iarepo</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#7c3aed">
<script src="/assets/js/pwa.js" defer></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--bg2:#fff;--bg3:#f1f5f9;--text:#1e293b;--text2:#475569;--text3:#94a3b8;--accent:#7c3aed;--accent2:#06b6d4;--grad:linear-gradient(135deg,#7c3aed,#06b6d4);--card:#fff;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06)}
[data-theme="dark"]{--bg:#0a0e1a;--bg2:#111827;--bg3:#1e293b;--text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;--card:#151c2e;--border:#1e293b;--shadow:0 1px 3px rgba(0,0,0,.3)}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:var(--accent2);text-decoration:none}

.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;background:var(--bg2);border-bottom:1px solid var(--border)}
.topbar a{color:var(--accent);font-weight:600;font-size:.95rem}

.container{max-width:1100px;margin:0 auto;padding:24px}
h1{font-size:1.4rem;font-weight:700;margin-bottom:24px;display:flex;align-items:center;gap:8px}

.editor-layout{display:grid;grid-template-columns:1fr 1fr;gap:20px;min-height:70vh}
.mobile-tabs{display:none}
@media(max-width:900px){
  .editor-layout{grid-template-columns:1fr;min-height:auto}
  .mobile-tabs{display:flex;gap:8px;margin-bottom:16px}
  .mtab{flex:1;padding:11px;border:1px solid var(--border);background:var(--bg2);color:var(--text2);border-radius:10px;font-weight:600;cursor:pointer;font-family:inherit;font-size:.88rem;transition:.15s}
  .mtab.active{background:var(--accent);color:#fff;border-color:var(--accent)}
  .editor-layout.show-form .preview-panel{display:none}
  .editor-layout.show-preview .form-panel{display:none}
  .preview-panel{min-height:62vh}
}

.form-panel{display:flex;flex-direction:column;gap:16px}
.form-group label{display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;color:var(--text2)}
.form-control{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text);font-family:inherit;font-size:.9rem}
.form-control:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,58,237,.1)}
select.form-control{cursor:pointer}
textarea.form-control{resize:vertical;min-height:60px}
.code-editor{font-family:'Fira Code',monospace;font-size:13px;tab-size:2;min-height:300px;flex:1}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}

.preview-panel{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column}
.preview-header{padding:10px 16px;background:var(--bg3);border-bottom:1px solid var(--border);font-size:.85rem;font-weight:600;color:var(--text2);display:flex;justify-content:space-between;align-items:center}
.preview-frame{flex:1;border:none;background:#fff;min-height:400px}

.actions{display:flex;gap:12px;margin-top:20px;justify-content:flex-end}
.btn{padding:10px 24px;border-radius:10px;border:none;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:600;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:var(--grad);color:#fff}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(124,58,237,.3)}
.btn-secondary{background:var(--bg3);color:var(--text2);border:1px solid var(--border)}
.btn-secondary:hover{border-color:var(--accent);color:var(--accent)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}

.status-msg{padding:10px 16px;border-radius:8px;font-size:.85rem;margin-top:12px;display:none}
.status-msg.success{display:block;background:rgba(34,197,94,.1);color:#16a34a;border:1px solid rgba(34,197,94,.2)}
.status-msg.error{display:block;background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}

.tag-input-wrap{display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:6px 8px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);min-height:42px;cursor:text}
.tag-input-wrap:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.tag-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:6px;background:rgba(124,58,237,.1);color:var(--accent);font-size:.78rem;font-weight:500}
.tag-chip-remove{background:none;border:none;cursor:pointer;color:var(--accent);opacity:.6;font-size:.8rem;padding:0;line-height:1}
.tag-chip-remove:hover{opacity:1}
.tag-input-field{border:none;outline:none;background:none;font-family:inherit;font-size:.85rem;color:var(--text);min-width:120px;flex:1;padding:2px 4px}
</style>
<?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body>

<div class="topbar">
  <a href="/dashboard/" style="display:flex;align-items:center;gap:8px"><img src="/assets/img/logo-icon.svg" alt="iarepo" style="height:22px;width:auto"> Dashboard</a>
  <span style="font-size:.85rem;color:var(--text2)"><?= h($user['name']) ?></span>
</div>

<div class="container">
  <h1><?= $isEdit ? '✏️' : '➕' ?> <?= h($pageTitle) ?></h1>

  <?php if (!$isEdit): ?>
  <div style="background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.2);border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px">
    <span style="font-size:1.4rem;flex-shrink:0">🤖</span>
    <div>
      <strong style="font-size:.9rem"><?= h(t('¿Tienes un recurso HTML generado con IA?')) ?></strong>
      <p style="font-size:.82rem;color:var(--text2);margin-top:3px;line-height:1.5"><?= h(t('Pídele a Gemini, ChatGPT o Claude que genere una simulación interactiva en HTML, pega el código aquí y compártela con la comunidad.')) ?> <a href="https://gemini.google.com" target="_blank" rel="noopener" style="color:var(--accent)"><?= h(t('Abrir Gemini →')) ?></a></p>
    </div>
  </div>
  <?php endif; ?>

  <div class="mobile-tabs">
    <button type="button" class="mtab active" data-panel="form">✏️ <?= h(t('Editar')) ?></button>
    <button type="button" class="mtab" data-panel="preview">👁 <?= h(t('Vista previa')) ?></button>
  </div>
  <div class="editor-layout show-form">
    <div class="form-panel">
      <div class="form-group">
        <label><?= h(t('Título *')) ?></label>
        <input type="text" class="form-control" id="title" placeholder="<?= h(t('ej. Simulador de Caída Libre')) ?>" value="<?= $resource ? h($resource['title']) : '' ?>">
      </div>
      <div class="form-group">
        <label><?= h(t('Descripción')) ?></label>
        <textarea class="form-control" id="description" rows="2" placeholder="<?= h(t('Breve descripción del recurso...')) ?>"><?= $resource ? h($resource['description']) : '' ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label><?= h(t('Tipo de contenido')) ?></label>
          <select class="form-control" id="codeType">
            <option value="html" <?= ($resource && $resource['code_type']==='html') ? 'selected' : '' ?>>⭐ <?= h(t('HTML generado con IA')) ?></option>
            <option value="prompt" <?= ($resource && $resource['code_type']==='prompt') ? 'selected' : '' ?>>💡 <?= h(t('Prompt de IA')) ?></option>
            <option value="url" <?= ($resource && $resource['code_type']==='url') ? 'selected' : '' ?>>🔗 <?= h(t('URL externa')) ?></option>
            <option value="embed" <?= ($resource && $resource['code_type']==='embed') ? 'selected' : '' ?>>📋 <?= h(t('Embed')) ?></option>
            <option value="python" <?= ($resource && $resource['code_type']==='python') ? 'selected' : '' ?>>🐍 <?= h(t('Python')) ?></option>
          </select>
        </div>
        <div class="form-group">
          <label><?= h(t('Visibilidad')) ?></label>
          <select class="form-control" id="visibility">
            <option value="draft" <?= ($resource && $resource['visibility']==='draft') ? 'selected' : '' ?>>🔒 <?= h(t('Borrador')) ?></option>
            <option value="community" <?= (!$resource || ($resource && $resource['visibility']==='community')) ? 'selected' : '' ?>>🌍 <?= h(t('Comunidad')) ?></option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label><?= h(t('Área / Materia')) ?></label>
          <input type="text" class="form-control" id="subjectArea" placeholder="<?= h(t('ej. Physics')) ?>" value="<?= $resource ? h($resource['subject_area'] ?? '') : '' ?>">
        </div>
        <div class="form-group">
          <label><?= h(t('Categoría')) ?></label>
          <select class="form-control" id="categoryId">
            <option value=""><?= h(t('— Sin categoría —')) ?></option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($resource && $resource['category_id'] == $c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label><?= h(t('Nivel educativo')) ?></label>
          <select class="form-control" id="level">
            <option value="general"><?= h(t('General')) ?></option>
            <option value="primary" <?= ($resource && $resource['level']==='primary') ? 'selected' : '' ?>><?= h(t('Primaria')) ?></option>
            <option value="secondary" <?= ($resource && $resource['level']==='secondary') ? 'selected' : '' ?>><?= h(t('Secundaria')) ?></option>
            <option value="ib" <?= ($resource && $resource['level']==='ib') ? 'selected' : '' ?>><?= h(t('IB')) ?></option>
            <option value="university" <?= ($resource && $resource['level']==='university') ? 'selected' : '' ?>><?= h(t('Universidad')) ?></option>
          </select>
        </div>
        <div class="form-group">
          <label><?= h(t('Idioma')) ?></label>
          <select class="form-control" id="lang">
            <option value="es" <?= ($resource && $resource['lang']==='es') ? 'selected' : '' ?>>Español</option>
            <option value="en" <?= ($resource && $resource['lang']==='en') ? 'selected' : '' ?>>English</option>
            <option value="pt" <?= ($resource && $resource['lang']==='pt') ? 'selected' : '' ?>>Português</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Tags <span style="font-weight:400;color:var(--text3);font-size:.8rem"><?= h(t('· Enter para agregar · máx. 20')) ?></span></label>
        <div class="tag-input-wrap" id="tagWrap" onclick="document.getElementById('tagInput').focus()">
          <input type="text" class="tag-input-field" id="tagInput" placeholder="<?= h(t('ej. gravedad, simulación...')) ?>" maxlength="50" autocomplete="off">
        </div>
      </div>
      <div class="form-group" style="flex:1;display:flex;flex-direction:column">
        <label><?= h(t('Código / Contenido *')) ?></label>
        <textarea class="form-control code-editor" id="codeContent" placeholder="<?= h(t('Pega tu código HTML aquí...')) ?>"><?= $resource ? h($resource['code_content']) : '' ?></textarea>
      </div>
    </div>

    <div class="preview-panel">
      <div class="preview-header">
        <span>👁 <?= h(t('Vista previa')) ?></span>
        <button class="btn btn-secondary" style="padding:4px 12px;font-size:.78rem" id="refreshPreview">↻ <?= h(t('Actualizar')) ?></button>
      </div>
      <iframe class="preview-frame" id="previewFrame" sandbox="allow-scripts allow-modals allow-popups"></iframe>
    </div>
  </div>

  <div class="actions">
    <a href="/dashboard/" class="btn btn-secondary"><?= h(t('Cancelar')) ?></a>
    <button class="btn btn-primary" id="saveBtn">💾 <?= $isEdit ? h(t('Guardar cambios')) : h(t('Publicar recurso')) ?></button>
  </div>
  <div class="status-msg" id="statusMsg"></div>
</div>

<script>
const EDIT_ID = <?= $editId ?: 'null' ?>;
const tags = new Set(<?= json_encode($existingTags, JSON_UNESCAPED_UNICODE) ?>);
const T = {
  titleReq: <?= json_encode(t('El título es obligatorio')) ?>,
  contentReq: <?= json_encode(t('El contenido es obligatorio')) ?>,
  saving: <?= json_encode(t('⏳ Guardando...')) ?>,
  saveFail: <?= json_encode(t('No se pudo guardar el recurso')) ?>,
  updated: <?= json_encode(t('¡Recurso actualizado!')) ?>,
  saveChanges: <?= json_encode(t('Guardar cambios')) ?>,
  published: <?= json_encode(t('✅ ¡Publicado!')) ?>,
  createdMsg: <?= json_encode(t('¡Recurso creado exitosamente! Redirigiendo...')) ?>,
  publishResource: <?= json_encode(t('Publicar recurso')) ?>,
  phDefault: <?= json_encode(t('Pega tu contenido aquí...')) ?>,
  phHtml: <?= json_encode(t('<!-- Pega aquí el HTML generado con Gemini, ChatGPT o Claude -->')) ?> + '\n<!DOCTYPE html>\n<html>...',
  phPrompt: <?= json_encode(t('Escribe el prompt que usaste para generar el recurso. Otros profesores podrán replicarlo y adaptarlo.')) ?>,
};

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

function renderTags(){
  document.querySelectorAll('.tag-chip').forEach(c=>c.remove());
  const wrap=document.getElementById('tagWrap');
  const input=document.getElementById('tagInput');
  tags.forEach(tag=>{
    const chip=document.createElement('span');
    chip.className='tag-chip';
    chip.innerHTML=`${esc(tag)} <button class="tag-chip-remove" type="button" onclick="removeTag('${esc(tag).replace(/'/g,"\\'")}')">✕</button>`;
    wrap.insertBefore(chip,input);
  });
}

function addTag(val){
  const tag=val.toLowerCase().trim().replace(/[,]+$/,'').slice(0,50);
  if(tag && tags.size<20) tags.add(tag);
  renderTags();
}

function removeTag(tag){ tags.delete(tag); renderTags(); }

document.getElementById('tagInput').addEventListener('keydown',e=>{
  if(e.key==='Enter'||e.key===','){
    e.preventDefault();
    const v=document.getElementById('tagInput').value.trim();
    if(v){ addTag(v); document.getElementById('tagInput').value=''; }
  }
  if(e.key==='Backspace'&&!document.getElementById('tagInput').value&&tags.size){
    const last=[...tags].pop();
    tags.delete(last);
    renderTags();
  }
});

document.getElementById('tagInput').addEventListener('blur',()=>{
  const v=document.getElementById('tagInput').value.trim();
  if(v){ addTag(v); document.getElementById('tagInput').value=''; }
});

renderTags();

// Theme
if(localStorage.getItem('iarepo-theme')==='dark') document.documentElement.setAttribute('data-theme','dark');

// Mobile tabs (Editar / Vista previa)
const editorLayout=document.querySelector('.editor-layout');
document.querySelectorAll('.mtab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    document.querySelectorAll('.mtab').forEach(t=>t.classList.remove('active'));
    tab.classList.add('active');
    const showPreview=tab.dataset.panel==='preview';
    editorLayout.classList.toggle('show-preview',showPreview);
    editorLayout.classList.toggle('show-form',!showPreview);
    if(showPreview) updatePreview();
  });
});

// Live preview
function updatePreview(){
  const type=document.getElementById('codeType').value;
  const code=document.getElementById('codeContent').value;
  const frame=document.getElementById('previewFrame');
  if(type==='html'||type==='embed') frame.srcdoc=code;
  else if(type==='url') frame.src=code;
  else frame.srcdoc='<pre style="padding:20px;font-family:monospace;white-space:pre-wrap">'+code.replace(/</g,'&lt;')+'</pre>';
}

let previewTimer;
document.getElementById('codeContent').addEventListener('input',()=>{
  clearTimeout(previewTimer);
  previewTimer=setTimeout(updatePreview,800);
});
document.getElementById('refreshPreview').addEventListener('click',updatePreview);
const placeholders = {
  html: T.phHtml,
  prompt: T.phPrompt,
  url: 'https://phet.colorado.edu/sims/html/...',
  embed: '<iframe src="..." width="100%" height="500" frameborder="0"></iframe>',
  python: '# Código Python\nprint("Hola mundo")',
};
document.getElementById('codeType').addEventListener('change',()=>{
  const type=document.getElementById('codeType').value;
  if(!document.getElementById('codeContent').value)
    document.getElementById('codeContent').placeholder=placeholders[type]||T.phDefault;
  updatePreview();
});

// Save
let isSaving=false;  // blocks concurrent requests while one is in flight
let created=false;   // once created we lock the button and redirect (no duplicates)
document.getElementById('saveBtn').addEventListener('click', async()=>{
  if(isSaving||created) return;  // ignore extra clicks while saving / after publishing
  const title=document.getElementById('title').value.trim();
  const code=document.getElementById('codeContent').value;
  if(!title){showStatus('error',T.titleReq);return}
  if(!code){showStatus('error',T.contentReq);return}

  const body={
    title,
    description:document.getElementById('description').value.trim(),
    code_content:code,
    code_type:document.getElementById('codeType').value,
    visibility:document.getElementById('visibility').value,
    subject_area:document.getElementById('subjectArea').value.trim(),
    category_id:document.getElementById('categoryId').value||null,
    level:document.getElementById('level').value,
    lang:document.getElementById('lang').value,
    tags:Array.from(tags),
  };

  const btn=document.getElementById('saveBtn');
  isSaving=true;
  btn.disabled=true;btn.textContent=T.saving;

  try{
    const url=EDIT_ID?`/api/resources.php?id=${EDIT_ID}`:'/api/resources.php';
    const method=EDIT_ID?'PUT':'POST';
    const res=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error||T.saveFail);

    if(EDIT_ID){
      // Edit: re-enable so the user can keep refining.
      showStatus('success',T.updated);
      isSaving=false;
      btn.disabled=false;btn.textContent='💾 '+T.saveChanges;
    }else{
      // Create: keep the button locked and redirect to the new resource.
      // This is what stops extra clicks from creating duplicates.
      created=true;
      btn.textContent=T.published;
      showStatus('success',T.createdMsg);
      setTimeout(()=>{ window.location = data.id ? '/resource/'+data.id : '/dashboard/'; },1200);
    }
  }catch(e){
    showStatus('error',e.message);
    isSaving=false;
    btn.disabled=false;btn.textContent='💾 '+(EDIT_ID?T.saveChanges:T.publishResource);
  }
});

function showStatus(type,msg){
  const el=document.getElementById('statusMsg');
  el.className='status-msg '+type;
  el.textContent=msg;
  if(type==='success') setTimeout(()=>el.style.display='none',5000);
}

// Init preview
if(document.getElementById('codeContent').value) updatePreview();
</script>
</body>
</html>
