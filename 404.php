<?php
// ================================================================
// 404.php — Branded "not found" page (Apache ErrorDocument)
// HTML page → no helpers.php; h() defined locally.
// ================================================================
http_response_code(404);
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$q = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Página no encontrada — iarepo</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="any">
<meta name="theme-color" content="#7c3aed">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f8fafc;color:#1e293b;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.wrap{max-width:480px;width:100%;text-align:center}
.tile{width:84px;height:84px;border-radius:22px;margin:0 auto 26px;background:linear-gradient(135deg,#7c3aed,#06b6d4);
  display:flex;align-items:center;justify-content:center;box-shadow:0 10px 30px rgba(124,58,237,.30)}
.tile svg{width:52px;height:52px}
.code{font-size:5rem;font-weight:800;line-height:1;letter-spacing:-3px;
  background:linear-gradient(135deg,#7c3aed,#06b6d4);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:8px}
h1{font-size:1.4rem;font-weight:700;margin-bottom:10px}
p{color:#475569;font-size:.95rem;line-height:1.6;margin-bottom:26px}
.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-weight:600;font-size:.9rem;
  padding:11px 22px;border-radius:100px;transition:transform .2s}
.btn-primary{background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;box-shadow:0 6px 18px rgba(124,58,237,.3)}
.btn-primary:hover{transform:translateY(-2px)}
.btn-outline{background:#fff;border:1px solid #e2e8f0;color:#475569}
.btn-outline:hover{border-color:#7c3aed;color:#7c3aed}
</style>
</head>
<body>
  <div class="wrap">
    <div class="tile">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <line x1="32" y1="22" x2="19" y2="12" stroke="white" stroke-width="2.4" stroke-linecap="round" stroke-opacity="0.8"/>
        <line x1="32" y1="22" x2="45" y2="12" stroke="white" stroke-width="2.4" stroke-linecap="round" stroke-opacity="0.8"/>
        <circle cx="19" cy="11" r="3.5" fill="white" fill-opacity="0.8"/>
        <circle cx="45" cy="11" r="3.5" fill="white" fill-opacity="0.8"/>
        <circle cx="32" cy="22" r="5.5" fill="white"/>
        <rect x="27" y="31" width="10" height="22" rx="5" fill="white"/>
      </svg>
    </div>
    <div class="code">404</div>
    <h1>Esta página no existe</h1>
    <p>El recurso que buscas se movió, fue eliminado o el enlace está mal escrito. Pero hay cientos de recursos esperándote.</p>
    <div class="actions">
      <a class="btn btn-primary" href="/">🏠 Ir al inicio</a>
      <a class="btn btn-outline" href="/?focus=search">🔍 Explorar recursos</a>
    </div>
  </div>
</body>
</html>
