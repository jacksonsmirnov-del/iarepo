<?php
// ================================================================
// auth/google.php — Google Sign-In callback
//
// Receives the credential (ID token) from Google Identity Services,
// verifies it, creates/updates the user, and starts a session.
// No libraries needed — we verify the token via Google's API.
// ================================================================

session_start();
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/helpers.php';

$env = require dirname(__DIR__) . '/.env.php';
$clientId = $env['GOOGLE_CLIENT_ID'] ?? '';

// ── Receive the credential from POST ──
$credential = $_POST['credential'] ?? '';
if (!$credential) {
    http_response_code(400);
    die('Missing credential');
}

// ── Verify the ID token with Google ──
$tokenInfo = verifyGoogleToken($credential, $clientId);
if (!$tokenInfo) {
    http_response_code(401);
    die('Invalid Google token');
}

// ── Extract user info ──
$googleId  = $tokenInfo['sub'];
$email     = $tokenInfo['email'];
$name      = $tokenInfo['name'] ?? $tokenInfo['email'];
$avatar    = $tokenInfo['picture'] ?? null;

// ── Create or update user ──
$db = getResourcesDB();

$stmt = $db->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
$stmt->execute([$googleId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$isNew = false;
if ($user) {
    // Existing user — update last_login and name/avatar
    $stmt = $db->prepare('UPDATE users SET last_login = NOW(), name = ?, avatar_url = ? WHERE id = ?');
    $stmt->execute([$name, $avatar, $user['id']]);
    $user['name'] = $name;
    $user['avatar_url'] = $avatar;
} else {
    // New user
    $isNew = true;
    $stmt = $db->prepare('INSERT INTO users (google_id, email, name, avatar_url, last_login) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$googleId, $email, $name, $avatar]);
    $user = [
        'id'         => (int) $db->lastInsertId(),
        'google_id'  => $googleId,
        'email'      => $email,
        'name'       => $name,
        'avatar_url' => $avatar,
        'role'       => 'teacher',
    ];
}

// ── Start session ──
$_SESSION['user'] = [
    'id'         => (int) $user['id'],
    'email'      => $email,
    'name'       => $name,
    'avatar_url' => $avatar,
    'role'       => $user['role'] ?? 'teacher',
    'source'     => 'google',
];

// ── Apply a pending favorite intent (conversion flow) ──
// Un invitado hizo clic en ⭐ Guardar; la intención viaja en ?save=ID y
// volvemos a ?return_url. Aplicamos el favorito ANTES de redirigir.
// Llega por ?save/?return_url en el login_uri y, como respaldo, por cookie
// (signin.php la fija con SameSite=None para sobrevivir al POST de Google).
$saveId    = (int) ($_GET['save'] ?? $_COOKIE['fav_intent'] ?? 0);
$returnUrl = safeLocalPath($_GET['return_url'] ?? $_COOKIE['fav_return'] ?? '');

// Limpia las cookies de intención (ya consumidas).
foreach (['fav_intent', 'fav_return'] as $ck) {
    if (isset($_COOKIE[$ck])) {
        setcookie($ck, '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'None', 'secure' => true]);
    }
}

if ($saveId) {
    try {
        $chk = $db->prepare('SELECT id FROM resources WHERE id = ? AND is_active = 1');
        $chk->execute([$saveId]);
        if ($chk->fetch()) {
            $db->prepare('INSERT IGNORE INTO resource_favorites (user_id, resource_id) VALUES (?, ?)')
               ->execute([(int) $user['id'], $saveId]);
        }
    } catch (\Throwable $e) {
        // best-effort: nunca bloquea el login
    }
}

// ── Redirect ──
if ($isNew) {
    // Usuario nuevo → onboarding ligero (rol). Preserva el retorno (p.ej. el
    // recurso que estaba guardando) para volver tras elegir/saltar.
    $to = '/auth/onboarding.php';
    if ($returnUrl) $to .= '?return_url=' . urlencode($returnUrl);
    header('Location: ' . $to);
    exit;
}

// Usuario existente → vuelve a donde estaba si guardaba algo, si no al dashboard.
header('Location: ' . ($returnUrl ?: '/dashboard/'));
exit;

/**
 * Solo permite rutas locales ("/algo"), nunca URLs absolutas ni protocol-relative.
 */
function safeLocalPath(string $url): string {
    $url = urldecode($url);
    return ($url === '/' || preg_match('#^/[^/\\\\]#', $url)) ? $url : '';
}


// ────────────────────────────────────────────────
// Helper: verify Google ID token without libraries
// ────────────────────────────────────────────────
function verifyGoogleToken(string $idToken, string $clientId): ?array {
    // Use Google's tokeninfo endpoint
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response)
        return null;

    $data = json_decode($response, true);
    if (!$data || !isset($data['sub']))
        return null;

    // Verify the audience matches our client ID
    if (($data['aud'] ?? '') !== $clientId)
        return null;

    // Verify the token is not expired
    if (isset($data['exp']) && (int) $data['exp'] < time())
        return null;

    return $data;
}
