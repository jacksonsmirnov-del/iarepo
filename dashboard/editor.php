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
$pageTitle = $isEdit ? 'Editar Recurso' : 'Nuevo Recurso';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — iarepo</title>
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
@media(max-width:900px){.editor-layout{grid-template-columns:1fr}}

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
</head>
<body>

<div class="topbar">
  <a href="/dashboard/">← Dashboard</a>
  <span style="font-size:.85rem;color:var(--text2)"><?= h($user['name']) ?></span>
</div>

<div class="container">
  <h1><?= $isEdit ? '✏️' : '➕' ?> <?= h($pageTitle) ?></h1>

  <div class="editor-layout">
    <div class="form-panel">
      <div class="form-group">
        <label>Título *</label>
        <input type="text" class="form-control" id="title" placeholder="ej. Simulador de Caída Libre" value="<?= $resource ? h($resource['title']) : '' ?>">
      </div>
      <div class="form-group">
        <label>Descripción</label>
        <textarea class="form-control" id="description" rows="2" placeholder="Breve descripción del recurso..."><?= $resource ? h($resource['description']) : '' ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Tipo de contenido</label>
          <select class="form-control" id="codeType">
            <option value="html" <?= ($resource && $resource['code_type']==='html') ? 'selected' : '' ?>>HTML/JS/CSS</option>
            <option value="url" <?= ($resource && $resource['code_type']==='url') ? 'selected' : '' ?>>URL externa</option>
            <option value="embed" <?= ($resource && $resource['code_type']==='embed') ? 'selected' : '' ?>>Embed</option>
            <option value="python" <?= ($resource && $resource['code_type']==='python') ? 'selected' : '' ?>>Python</option>
            <option value="prompt" <?= ($resource && $resource['code_type']==='prompt') ? 'selected' : '' ?>>AI Prompt</option>
          </select>
        </div>
        <div class="form-group">
          <label>Visibilidad</label>
          <select class="form-control" id="visibility">
            <option value="draft" <?= ($resource && $resource['visibility']==='draft') ? 'selected' : '' ?>>🔒 Borrador</option>
            <option value="community" <?= (!$resource || ($resource && $resource['visibility']==='community')) ? 'selected' : '' ?>>🌍 Comunidad</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Área / Materia</label>
          <input type="text" class="form-control" id="subjectArea" placeholder="ej. Physics" value="<?= $resource ? h($resource['subject_area'] ?? '') : '' ?>">
        </div>
        <div class="form-group">
          <label>Categoría</label>
          <select class="form-control" id="categoryId">
            <option value="">— Sin categoría —</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($resource && $resource['category_id'] == $c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Nivel educativo</label>
          <select class="form-control" id="level">
            <option value="general">General</option>
            <option value="primary" <?= ($resource && $resource['level']==='primary') ? 'selected' : '' ?>>Primaria</option>
            <option value="secondary" <?= ($resource && $resource['level']==='secondary') ? 'selected' : '' ?>>Secundaria</option>
            <option value="ib" <?= ($resource && $resource['level']==='ib') ? 'selected' : '' ?>>IB</option>
            <option value="university" <?= ($resource && $resource['level']==='university') ? 'selected' : '' ?>>Universidad</option>
          </select>
        </div>
        <div class="form-group">
          <label>Idioma</label>
          <select class="form-control" id="lang">
            <option value="es" <?= ($resource && $resource['lang']==='es') ? 'selected' : '' ?>>Español</option>
            <option value="en" <?= ($resource && $resource['lang']==='en') ? 'selected' : '' ?>>English</option>
            <option value="pt" <?= ($resource && $resource['lang']==='pt') ? 'selected' : '' ?>>Português</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Tags <span style="font-weight:400;color:var(--text3);font-size:.8rem">· Enter para agregar · máx. 20</span></label>
        <div class="tag-input-wrap" id="tagWrap" onclick="document.getElementById('tagInput').focus()">
          <input type="text" class="tag-input-field" id="tagInput" placeholder="ej. gravedad, simulación..." maxlength="50" autocomplete="off">
        </div>
      </div>
      <div class="form-group" style="flex:1;display:flex;flex-direction:column">
        <label>Código / Contenido *</label>
        <textarea class="form-control code-editor" id="codeContent" placeholder="Pega tu código HTML aquí..."><?= $resource ? h($resource['code_content']) : '' ?></textarea>
      </div>
    </div>

    <div class="preview-panel">
      <div class="preview-header">
        <span>👁 Vista previa</span>
        <button class="btn btn-secondary" style="padding:4px 12px;font-size:.78rem" id="refreshPreview">↻ Actualizar</button>
      </div>
      <iframe class="preview-frame" id="previewFrame" sandbox="allow-scripts allow-modals allow-popups"></iframe>
    </div>
  </div>

  <div class="actions">
    <a href="/dashboard/" class="btn btn-secondary">Cancelar</a>
    <button class="btn btn-primary" id="saveBtn">💾 <?= $isEdit ? 'Guardar cambios' : 'Publicar recurso' ?></button>
  </div>
  <div class="status-msg" id="statusMsg"></div>
</div>

<script>
const EDIT_ID = <?= $editId ?: 'null' ?>;
const tags = new Set(<?= json_encode($existingTags, JSON_UNESCAPED_UNICODE) ?>);

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
document.getElementById('codeType').addEventListener('change',()=>{
  const type=document.getElementById('codeType').value;
  document.getElementById('codeContent').placeholder=type==='url'?'https://...':'Pega tu código aquí...';
  updatePreview();
});

// Save
document.getElementById('saveBtn').addEventListener('click', async()=>{
  const title=document.getElementById('title').value.trim();
  const code=document.getElementById('codeContent').value;
  if(!title){showStatus('error','El título es obligatorio');return}
  if(!code){showStatus('error','El contenido es obligatorio');return}

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
  btn.disabled=true;btn.textContent='⏳ Guardando...';

  try{
    const url=EDIT_ID?`/api/resources.php?id=${EDIT_ID}`:'/api/resources.php';
    const method=EDIT_ID?'PUT':'POST';
    const res=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const data=await res.json();
    if(!data.ok) throw new Error(data.error);
    showStatus('success',EDIT_ID?'¡Recurso actualizado!':'¡Recurso creado exitosamente!');
    if(!EDIT_ID && data.resource?.id) setTimeout(()=>window.location='/resource/'+data.resource.id,1500);
  }catch(e){showStatus('error',e.message)}
  finally{btn.disabled=false;btn.textContent='💾 '+(EDIT_ID?'Guardar cambios':'Publicar recurso')}
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
