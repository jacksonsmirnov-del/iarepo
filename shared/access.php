<?php
// ================================================================
// shared/access.php — Quién puede ver un recurso
//
// Una sola definición para todos los endpoints. Vivía dentro de
// api/resources.php, y por eso api/versions.php —que no puede incluir otro
// endpoint— no comprobaba nada: cualquier usuario con sesión (bastaba entrar
// con Google) leía el código de cualquier versión, borradores ajenos
// incluidos, enumerando ?id=.
//
// Funciones puras: sin require, sin BD, sin salida. Se pueden cargar desde
// una página HTML o desde un test sin arrastrar shared/helpers.php.
// ================================================================

/**
 * ¿Puede $user ver $resource?
 *
 * $resource necesita visibility, author_tenant_id y author_user_id.
 * $user es lo que devuelve authenticate() (o null si es anónimo).
 *
 *   community → cualquiera, también sin sesión
 *   school    → cualquiera del mismo tenant
 *   area      → cualquiera del mismo tenant (fomenta la colaboración)
 *   draft     → solo su autor
 */
function canView(array $resource, ?array $user): bool
{
    $vis = $resource['visibility'] ?? 'draft';

    if ($vis === 'community')
        return true;
    if (!$user)
        return false;

    $authorTenant = (int) ($resource['author_tenant_id'] ?? -1);
    $userTenant   = (int) ($user['tenant_id'] ?? 0);
    $userId       = (int) ($user['user_id'] ?? 0);

    return match ($vis) {
        'school', 'area' => $userTenant === $authorTenant,
        'draft'          => $userTenant === $authorTenant && $userId === (int) ($resource['author_user_id'] ?? 0),
        default          => false,
    };
}
