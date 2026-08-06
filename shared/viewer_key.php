<?php
// ================================================================
// shared/viewer_key.php — Identidad anónima y caducable del visitante
//
// Funciones PURAS salvo por el PDO que reciben. Su ÚNICA dependencia es la
// tabla `view_salts` (migration_012).
//
// ⚠️ NO hace `require` de nada, y en particular NO de shared/helpers.php.
// Ese fichero arrastra shared/error_handler.php, cuyos handlers hacen
// echo json_encode(...) + exit(1): si alguna vez una página HTML necesitara
// calcular un viewer_key, cargarlo con helpers dentro la sacaría a medio
// renderizar con un JSON incrustado. Manteniéndolo limpio, este módulo es
// seguro en cualquier contexto. Es la misma regla que cumple shared/search.php.
//
// ── QUÉ RESUELVE ──────────────────────────────────────────────
// Identificar a un visitante lo justo para no contarlo dos veces el mismo día,
// y ni un milímetro más:
//
//   · NO se usa la IP. No es sólo privacidad: los alumnos de un colegio salen
//     por el NAT del centro —una IP para toda el aula, y con los equipos del
//     centro hasta el User-Agent coincide—, así que deduplicar por IP habría
//     colapsado una clase entera en un único visitante. Era exactamente el
//     caso que originó todo esto: 20 alumnos trabajando, 8 visitas contadas.
//
//   · El identificador anónimo lo genera el propio navegador
//     (assets/js/track.js, 32 hex en localStorage) y aquí sólo se guarda su
//     hash con una sal que se BORRA a los pocos días.
//
//   · Con sesión manda la identidad real, que es estable entre dispositivos:
//     el mismo profesor en el móvil y en el portátil cuenta una vez.
//
// ── LA CADUCIDAD ES LA GARANTÍA ───────────────────────────────
// Pasada la ventana de retención, la sal ya no existe: ni con acceso total a
// la base de datos se puede volver a ligar una fila con el identificador que
// la produjo, ni cruzar dos días de la misma persona. La anonimización no
// depende de una promesa — depende de que el dato ya no exista.
//
// Contrapartida buscada: al rotar la sal, las métricas cuentan PERSONA-DÍA y
// no «personas distintas de siempre». Es justo la pregunta que se quiere poder
// responder, y hace imposible la que no.
// ================================================================

// Días que sobrevive una sal.
if (!defined('IAREPO_SALT_RETENTION_DAYS')) {
    define('IAREPO_SALT_RETENTION_DAYS', 2);
}

/**
 * Formato del identificador que genera el navegador: 32 hex.
 *
 * Se VALIDA en vez de aceptar lo que llegue para que nadie pueda elegir su
 * propio viewer_key y, por ejemplo, fijar el de otra persona o fabricar
 * colisiones.
 */
function iarepo_valid_vid(string $vid): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $vid);
}

/**
 * Sal del día, creándola si es la primera visita.
 *
 * ── POR QUÉ LA PURGA VA AQUÍ DENTRO ───────────────────────────
 * Colgada del alta de la sal, ocurre EXACTAMENTE una vez al día, cuando llega
 * el primer visitante. La alternativa —un sorteo por petición, como el
 * `random_int(1,100) === 1` de rateLimit()— con el tráfico de este sitio
 * podría no salir en semanas, y la ventana de retención dejaría de cumplirse
 * justo en el caso silencioso: nadie se enteraría de que hay sales vivas de
 * hace un mes.
 */
function iarepo_daily_salt(PDO $db, string $day): string
{
    $sel = $db->prepare('SELECT salt FROM view_salts WHERE view_day = ?');
    $sel->execute([$day]);
    $salt = $sel->fetchColumn();
    if (is_string($salt) && $salt !== '') return $salt;

    // INSERT IGNORE, no INSERT: dos visitantes simultáneos el primer segundo
    // del día generan dos sales distintas y una pierde la carrera. Con IGNORE,
    // quien pierde no revienta; simplemente relee la que ganó.
    $db->prepare('INSERT IGNORE INTO view_salts (view_day, salt) VALUES (?, ?)')
       ->execute([$day, bin2hex(random_bytes(32))]);

    $db->prepare('DELETE FROM view_salts WHERE view_day < DATE_SUB(?, INTERVAL ? DAY)')
       ->execute([$day, IAREPO_SALT_RETENTION_DAYS]);

    $sel->execute([$day]);
    return (string) $sel->fetchColumn();
}

/**
 * Clave del visitante para un día concreto.
 *
 * El prefijo separa los dos espacios de identidad para que un identificador
 * anónimo nunca pueda colisionar con el de un usuario con sesión.
 *
 * @param array|null $user  Resultado de authenticate(), o null si es anónimo.
 * @param string     $vid   Identificador del navegador (sólo se usa si $user es null).
 * @return string|null      64 hex, o null si el visitante anónimo no trae un vid válido.
 */
function iarepo_viewer_key(PDO $db, ?array $user, string $vid, string $day): ?string
{
    if ($user) {
        $raw = 'u:' . (int) ($user['tenant_id'] ?? 0) . ':' . (int) ($user['user_id'] ?? 0);
    } else {
        if (!iarepo_valid_vid($vid)) return null;
        $raw = 'a:' . $vid;
    }

    return hash('sha256', $raw . ':' . iarepo_daily_salt($db, $day));
}
