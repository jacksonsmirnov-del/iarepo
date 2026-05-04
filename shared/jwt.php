<?php
// ================================================================
// shared/jwt.php — Minimal JWT Implementation (HMAC-SHA256)
//
// No external dependencies. Used for auth between Campus ↔ Resources.
// Campus signs tokens, Resources verifies them (shared secret).
// ================================================================

/**
 * Base64url encode (RFC 7515)
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64url decode (RFC 7515)
 */
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Create a signed JWT token.
 *
 * @param array  $payload  Claims to include (user_id, name, role, etc.)
 * @param string $secret   Shared secret (from .env.php)
 * @param int    $ttl      Token TTL in seconds (default: 1 hour)
 * @return string          Encoded JWT string
 */
function jwt_encode(array $payload, string $secret, int $ttl = 3600): string {
    $header = base64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
    ]));

    $payload['iat'] = time();
    $payload['exp'] = time() + $ttl;
    $body = base64url_encode(json_encode($payload));

    $signature = base64url_encode(
        hash_hmac('sha256', "$header.$body", $secret, true)
    );

    return "$header.$body.$signature";
}

/**
 * Decode and verify a JWT token.
 *
 * @param string $token   The JWT string
 * @param string $secret  Shared secret
 * @return array|null      Decoded payload, or null if invalid/expired
 */
function jwt_decode(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $body, $signature] = $parts;

    // Verify signature
    $valid = base64url_encode(
        hash_hmac('sha256', "$header.$body", $secret, true)
    );
    if (!hash_equals($valid, $signature)) return null;

    // Decode payload
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload) return null;

    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}
