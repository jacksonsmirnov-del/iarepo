<?php
// ================================================================
// admin/errors.php — Visor de errores JS del cliente
// Protegido con ADMIN_PASS del .env.php
// ================================================================

require_once __DIR__ . '/../shared/db.php';

$env = require __DIR__ . '/../.env.php';
$pass = $_GET['pass'] ?? $_POST['pass'] ?? '';
if (!$env['ADMIN_PASS'] || !hash_equals($env['ADMIN_PASS'], $pass)) {
    http_response_code(403);
    die('<h2>Acceso denegado</h2><p>Añade ?pass=TU_ADMIN_PASS</p>');
}

$db = getResourcesDB();

// Agrupar por mensaje + source para no mostrar duplicados
$errors = $db->query("
    SELECT message, source, lineno, page_url,
           COUNT(*) AS occurrences,
           MAX(created_at) AS last_seen,
           MIN(created_at) AS first_seen
    FROM client_error_log
    WHERE created_at > NOW() - INTERVAL 7 DAY
    GROUP BY message, source, lineno, page_url
    ORDER BY last_seen DESC
    LIMIT 100
")->fetchAll();

$total = $db->query("SELECT COUNT(*) FROM client_error_log WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Errores JS — iarepo admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:24px}
h1{font-size:1.2rem;margin-bottom:4px}
.sub{color:#64748b;font-size:.85rem;margin-bottom:24px}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{text-align:left;padding:8px 12px;background:#1e293b;color:#94a3b8;font-weight:600;border-bottom:1px solid #334155}
td{padding:8px 12px;border-bottom:1px solid #1e293b;vertical-align:top}
tr:hover td{background:#1e293b}
.msg{color:#f1f5f9;font-family:monospace;max-width:500px;word-break:break-word}
.source{color:#7c3aed}
.page{color:#06b6d4}
.count{color:#f97316;font-weight:700}
.time{color:#64748b;font-size:.75rem;white-space:nowrap}
.empty{text-align:center;padding:60px;color:#64748b}
.tag{display:inline-block;padding:1px 6px;border-radius:4px;font-size:.7rem;background:#1e293b;color:#94a3b8;margin-left:4px}
</style>
</head>
<body>
<h1>🐛 Errores JS — últimos 7 días</h1>
<div class="sub"><?= (int)$total ?> eventos · <?= count($errors) ?> grupos únicos</div>

<?php if (!$errors): ?>
  <div class="empty">✅ Sin errores registrados en los últimos 7 días</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Mensaje</th>
      <th>Fuente</th>
      <th>Página</th>
      <th>Veces</th>
      <th>Último</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($errors as $e): ?>
    <tr>
      <td class="msg">
        <?= htmlspecialchars($e['message'], ENT_QUOTES) ?>
        <?php if ($e['lineno']): ?><span class="tag">línea <?= (int)$e['lineno'] ?></span><?php endif; ?>
      </td>
      <td class="source"><?= htmlspecialchars($e['source'] ?? '—', ENT_QUOTES) ?></td>
      <td class="page"><?= htmlspecialchars($e['page_url'] ?? '—', ENT_QUOTES) ?></td>
      <td class="count"><?= (int)$e['occurrences'] ?>×</td>
      <td class="time">
        <?= date('d/m H:i', strtotime($e['last_seen'])) ?>
        <?php if ($e['occurrences'] > 1): ?>
          <br><span style="color:#475569">desde <?= date('d/m', strtotime($e['first_seen'])) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</body>
</html>
