<?php
// ================================================================
// tests/unit/jwt_test.php — shared/jwt.php
//
// Es el contrato de autenticación con Campus: Campus firma, Resources
// verifica. Un fallo aquí no da error 500, da acceso indebido o corta
// el login de todo el mundo. shared/jwt.php es puro (no hace ningún
// require ni toca la BD), así que se puede cargar en el runner.
//
// Los tests cubren las tres formas de romper un verificador de JWT:
// aceptar una firma que no cuadra, aceptar 'alg: none' y no mirar exp.
// ================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('forbidden'); }

require_once IAREPO_ROOT . '/shared/jwt.php';

const JT_SECRET = 'secreto-de-test-no-usar-jamas-en-produccion';

/** Payload típico del que manda Campus. */
function jt_payload(): array
{
    return ['user_id' => 42, 'name' => 'Ana Pérez', 'role' => 'teacher', 'tenant_id' => 7];
}

/** Rearma un token cambiando una de sus tres partes. */
function jt_retoken(string $token, ?string $header = null, ?string $body = null, ?string $sig = null): string
{
    [$h, $b, $s] = explode('.', $token);

    return ($header ?? $h) . '.' . ($body ?? $b) . '.' . ($sig ?? $s);
}

// ================================================================
// base64url (RFC 7515)
// ================================================================

function test_base64url_ida_y_vuelta(): void
{
    $casos = [
        'vacío'      => '',
        'ascii'      => 'hola',
        'acentos'    => 'Ana Pérez — física',
        'un byte'    => "\x00",
        'binario'    => "\x00\xff\xfe\x01\x7f",
        'json'       => '{"user_id":42,"role":"teacher"}',
        'largo'      => str_repeat('a', 1000),
    ];
    foreach ($casos as $label => $raw) {
        subtest($label, static function () use ($raw): void {
            assert_eq($raw, base64url_decode(base64url_encode($raw)));
        });
    }
}

function test_base64url_no_usa_el_alfabeto_de_base64_normal(): void
{
    // Si se colara un +, / o = el token no sobreviviría a una URL ni a
    // una cabecera Authorization sin volver a codificarse.
    for ($i = 0; $i < 256; $i++) {
        $enc = base64url_encode(random_bytes(32));
        if (str_contains($enc, '+') || str_contains($enc, '/') || str_contains($enc, '=')) {
            test_fail('base64url_encode ha emitido un carácter no seguro para URL: ' . $enc);
        }
    }
    assert_matches('/^[A-Za-z0-9_-]+$/', base64url_encode(random_bytes(64)));
}

// ================================================================
// Camino feliz
// ================================================================

function test_ida_y_vuelta_conserva_las_claims(): void
{
    $token = jwt_encode(jt_payload(), JT_SECRET);
    assert_count(3, explode('.', $token), 'un JWT tiene tres partes separadas por punto');

    $out = jwt_decode($token, JT_SECRET);
    assert_not_null($out, 'un token recién firmado debe validar');
    foreach (jt_payload() as $k => $v) {
        subtest($k, static function () use ($out, $k, $v): void {
            assert_eq($v, $out[$k]);
        });
    }
}

function test_anade_iat_y_exp(): void
{
    $antes = time();
    $out   = jwt_decode(jwt_encode(jt_payload(), JT_SECRET, 3600), JT_SECRET);

    assert_not_null($out);
    assert_true(isset($out['iat']), 'falta iat');
    assert_true(isset($out['exp']), 'falta exp');
    assert_true($out['iat'] >= $antes && $out['iat'] <= time() + 1, 'iat fuera de rango');
    assert_eq(3600, $out['exp'] - $out['iat'], 'exp debe ser iat + ttl');
}

function test_la_cabecera_declara_hs256(): void
{
    $header = json_decode(base64url_decode(explode('.', jwt_encode([], JT_SECRET))[0]), true);
    assert_eq('HS256', $header['alg']);
    assert_eq('JWT', $header['typ']);
}

// ================================================================
// Rechazos — cada uno es un agujero de autenticación si falla
// ================================================================

function test_rechaza_secreto_distinto(): void
{
    $token = jwt_encode(jt_payload(), JT_SECRET);
    assert_null(jwt_decode($token, 'otro-secreto'), 'un token firmado con otra clave NO puede validar');
    assert_null(jwt_decode($token, ''), 'ni con el secreto vacío');
    assert_null(jwt_decode($token, JT_SECRET . ' '), 'ni con un secreto casi igual');
}

function test_rechaza_payload_manipulado(): void
{
    // El ataque obvio: subirse el rol sin tocar la firma.
    $token   = jwt_encode(['user_id' => 42, 'role' => 'student'], JT_SECRET);
    $body    = json_decode(base64url_decode(explode('.', $token)[1]), true);
    $body['role'] = 'admin';
    $forjado = jt_retoken($token, null, base64url_encode(json_encode($body)));

    assert_neq($token, $forjado, 'el token forjado debe ser distinto');
    assert_null(jwt_decode($forjado, JT_SECRET), 'un payload alterado invalida la firma');
}

function test_rechaza_firma_manipulada(): void
{
    $token = jwt_encode(jt_payload(), JT_SECRET);
    $sig   = explode('.', $token)[2];

    $casos = [
        'firma vacía'      => '',
        'firma truncada'   => substr($sig, 0, -1),
        'un carácter menos'=> substr($sig, 1),
        'firma invertida'  => strrev($sig),
        'firma basura'     => str_repeat('A', strlen($sig)),
        'con padding ='    => $sig . '=',
    ];
    foreach ($casos as $label => $mala) {
        subtest($label, static function () use ($token, $mala): void {
            assert_null(jwt_decode(jt_retoken($token, null, null, $mala), JT_SECRET));
        });
    }
}

/**
 * La misma firma reescrita con el alfabeto base64 clásico (+ y / en vez
 * de - y _) NO puede valer: jwt_decode compara cadenas, no bytes.
 *
 * Ojo con la trampa que este test tuvo mientras se escribía: si se coge
 * un token cualquiera y se le aplica strtr('-_', '+/'), una de cada
 * cuatro veces la firma no contiene ningún - ni _, strtr no cambia nada
 * y el "token manipulado" es el original... que valida. El test pasaba
 * el 74 % de las ejecuciones. Por eso aquí se busca explícitamente una
 * firma que SÍ tenga esos caracteres antes de afirmar nada.
 */
function test_rechaza_una_firma_reescrita_en_base64_estandar(): void
{
    for ($i = 0; $i < 500; $i++) {
        $token = jwt_encode(['user_id' => $i], JT_SECRET);
        $sig   = explode('.', $token)[2];
        if (strpbrk($sig, '-_') === false) {
            continue; // strtr no cambiaría nada: el caso no probaría nada
        }
        assert_neq($sig, strtr($sig, '-_', '+/'), 'la firma reescrita debe ser distinta');
        assert_null(
            jwt_decode(jt_retoken($token, null, null, strtr($sig, '-_', '+/')), JT_SECRET),
            'una firma en base64 estándar no puede validar'
        );

        return;
    }
    test_skip('no he encontrado ninguna firma con "-" o "_" en 500 intentos');
}

/** El ataque clásico: cambiar alg a 'none' y quitar la firma. */
function test_rechaza_alg_none(): void
{
    $payload = base64url_encode(json_encode(['user_id' => 1, 'role' => 'admin', 'exp' => time() + 3600]));

    foreach (['none' => 'none', 'None' => 'None', 'NONE' => 'NONE'] as $label => $alg) {
        subtest($label, static function () use ($alg, $payload): void {
            $header = base64url_encode(json_encode(['alg' => $alg, 'typ' => 'JWT']));
            assert_null(jwt_decode($header . '.' . $payload . '.', JT_SECRET), 'alg none con firma vacía');
            assert_null(jwt_decode($header . '.' . $payload . '.x', JT_SECRET), 'alg none con firma cualquiera');
        });
    }
}

function test_rechaza_tokens_malformados(): void
{
    $casos = [
        'vacío'            => '',
        'una parte'        => 'abc',
        'dos partes'       => 'abc.def',
        'cuatro partes'    => 'a.b.c.d',
        'solo puntos'      => '..',
        'cuatro puntos'    => '...',
        'espacios'         => '   ',
        'basura'           => 'no-es-un-jwt',
        'json inválido'    => base64url_encode('{no json') . '.' . base64url_encode('{tampoco') . '.x',
        'null bytes'       => "a\x00b.c.d",
        'muy largo'        => str_repeat('a', 10000) . '.b.c',
    ];
    foreach ($casos as $label => $token) {
        subtest($label, static function () use ($token): void {
            assert_null(jwt_decode($token, JT_SECRET), 'debe devolver null, no lanzar ni aceptar');
        });
    }
}

function test_rechaza_tokens_caducados(): void
{
    // ttl negativo ⇒ exp ya pasó.
    assert_null(jwt_decode(jwt_encode(jt_payload(), JT_SECRET, -1), JT_SECRET), 'exp de hace 1s');
    assert_null(jwt_decode(jwt_encode(jt_payload(), JT_SECRET, -86400), JT_SECRET), 'exp de ayer');

    // Y uno que caduca dentro de un rato sí vale.
    assert_not_null(jwt_decode(jwt_encode(jt_payload(), JT_SECRET, 60), JT_SECRET));
}

/**
 * CARACTERIZACIÓN, no un fallo: jwt_decode() hace `if (!$payload)`, así
 * que un token con payload `{}` (que decodifica a []) se rechaza aunque
 * la firma sea correcta. En la práctica es inalcanzable porque
 * jwt_encode() siempre inyecta iat y exp. Se fija aquí para que, si
 * alguien cambia esa comprobación, sea una decisión y no un accidente.
 */
function test_caracterizacion_payload_vacio_se_rechaza(): void
{
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body   = base64url_encode('{}');
    $sig    = base64url_encode(hash_hmac('sha256', "{$header}.{$body}", JT_SECRET, true));

    assert_null(jwt_decode("{$header}.{$body}.{$sig}", JT_SECRET), 'payload {} se rechaza pese a firmar bien');
}

/** Sin claim exp el token no caduca nunca: conviene saberlo y vigilarlo. */
function test_caracterizacion_sin_exp_el_token_no_caduca(): void
{
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body   = base64url_encode(json_encode(['user_id' => 1]));
    $sig    = base64url_encode(hash_hmac('sha256', "{$header}.{$body}", JT_SECRET, true));

    $out = jwt_decode("{$header}.{$body}.{$sig}", JT_SECRET);
    assert_not_null($out, 'sin exp, jwt_decode acepta el token indefinidamente');
    assert_eq(1, $out['user_id']);
}

function test_no_imprime_nada_ni_lanza(): void
{
    assert_no_output(static function (): void {
        jwt_decode('', JT_SECRET);
        jwt_decode('a.b.c', JT_SECRET);
        jwt_decode(jwt_encode(jt_payload(), JT_SECRET), JT_SECRET);
        jwt_decode(str_repeat('.', 100), JT_SECRET);
    });
}
