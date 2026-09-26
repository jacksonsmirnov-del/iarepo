<?php
// ================================================================
// shared/labels.php — Cómo se NOMBRA cada cosa ante la persona
//
// Una sola fuente para las etiquetas que antes se pintaban en crudo desde la
// BD o se traducían distinto en cada página:
//   · categorías: la tabla guarda "Physics", "Mathematics"… en inglés, y la
//     web en español decía «Más en Mathematics»;
//   · niveles: 'secondary' → «Secundaria · 12–16 años» (un profesor piensa
//     en edades y cursos, no en claves);
//   · tipo: 'url' / 'html' / 'embed' se enseñaban tal cual («url»);
//   · fuente: 30 recursos sin source_name firmaban «Autor: iarepo» aunque
//     fueran de PhET.
//
// Funciones puras que solo dependen de t(). Las usan las páginas (PHP) y
// api/resources.php, que devuelve estas etiquetas YA calculadas en cada fila
// (category_label, level_label, source_label, opens_label): así el JS de la
// portada no duplica ni la lógica ni las traducciones.
// ================================================================

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/asset.php';   // iarepo_thumb(): la captura real, si existe

/** Slug de categoría → nombre visible. Desconocido → el nombre de la BD. */
function iarepo_category_label(?string $slug, ?string $dbName = null): string
{
    $map = [
        'physics'          => t('Física'),
        'mathematics'      => t('Matemáticas'),
        'chemistry'        => t('Química'),
        'biology'          => t('Biología'),
        'languages'        => t('Idiomas'),
        'social-studies'   => t('Ciencias sociales'),
        'computer-science' => t('Informática'),
        'ai-prompts'       => t('Prompts de IA'),
        'art-music'        => t('Arte y música'),
        'health-pe'        => t('Salud y educación física'),
        'general'          => t('Herramientas generales'),
        'space-astronomy'  => t('Espacio y astronomía'),
    ];
    if ($slug !== null && isset($map[$slug]))
        return $map[$slug];
    return trim((string) $dbName) !== '' ? (string) $dbName : t('Otros');
}

/**
 * Clase de color de la materia (assets/css/app.css → .s-<slug>).
 * Solo slugs conocidos: nunca se refleja texto de la BD en un atributo class.
 */
function iarepo_subject_class(?string $slug): string
{
    $known = ['physics', 'mathematics', 'chemistry', 'biology', 'languages', 'social-studies',
              'computer-science', 'ai-prompts', 'art-music', 'health-pe', 'general', 'space-astronomy'];
    return 's-' . (in_array($slug, $known, true) ? $slug : 'general');
}

/**
 * Nivel → etiqueta con edades. `level` es texto libre en la BD: los seeds
 * usan claves en inglés ('secondary') y algunas filas antiguas español
 * ('secundaria', 'bachillerato'). Se aceptan las dos.
 */
function iarepo_level_label(?string $level, bool $withAges = true): string
{
    $k = strtolower(trim((string) $level));
    $alias = ['primaria' => 'primary', 'secundaria' => 'secondary', 'eso' => 'secondary',
              'bachillerato' => 'ib', 'universidad' => 'university', 'todos' => 'general'];
    $k = $alias[$k] ?? $k;

    $names = [
        'primary'    => [t('Primaria'), t('6–12 años')],
        'secondary'  => [t('Secundaria'), t('12–16 años')],
        'ib'         => [t('Bachillerato e IB'), t('16–18 años')],
        'university' => [t('Universidad'), t('18+ años')],
        'general'    => [t('Todos los niveles'), ''],
    ];
    if (!isset($names[$k]))
        return trim((string) $level) === '' ? '' : ucfirst(trim((string) $level));
    [$name, $ages] = $names[$k];
    return ($withAges && $ages !== '') ? "$name · $ages" : $name;
}

/** Opciones del filtro de nivel, en orden de edad: clave de la API → etiqueta. */
function iarepo_level_options(): array
{
    $out = [];
    foreach (['primary', 'secondary', 'ib', 'university'] as $k)
        $out[$k] = iarepo_level_label($k);
    return $out;
}

/**
 * Nombre corto de la fuente original, o null si es propia.
 * source_name manda; si falta, se deduce del dominio de source_url (así los
 * 23 PhET sin source_name dejan de firmar como «iarepo»).
 *
 * Y si tampoco hay source_url pero el recurso es de tipo 'url', la fuente es
 * su propia dirección: 30 recursos antiguos de PhET no tienen source_url y
 * salían en la portada y en la API sin sello de fuente. La dirección llega en
 * code_content (fila completa) o en link_url (listados que no arrastran el
 * HTML de los demás tipos: `IF(r.code_type = 'url', r.code_content, NULL) AS
 * link_url`).
 */
function iarepo_source_label(array $r): ?string
{
    $name = trim((string) ($r['source_name'] ?? ''));
    $short = [
        'PhET Interactive Simulations' => 'PhET',
        'NASA Space Place'             => 'NASA',
    ];
    if ($name !== '')
        return $short[$name] ?? $name;

    $url = trim((string) ($r['source_url'] ?? ''));
    if ($url === '' && ($r['code_type'] ?? '') === 'url')
        $url = trim((string) ($r['link_url'] ?? $r['code_content'] ?? ''));
    // Solo http(s) limpia: de un «javascript:» o de «https://evil\@phet…»
    // no se deduce nada (iarepo_http_host).
    $host = (string) iarepo_http_host($url);
    if ($host === '')
        return null;
    $host = preg_replace('/^www\./', '', $host);
    $byHost = [
        'phet.colorado.edu'        => 'PhET',
        'spaceplace.nasa.gov'      => 'NASA',
        'nasa.gov'                 => 'NASA',
        'geogebra.org'             => 'GeoGebra',
        'physics-simulations.org'  => 'Physics Simulations',
        'ophysics.com'             => 'oPhysics',
        'physicsclassroom.com'     => 'The Physics Classroom',
        'walter-fendt.de'          => 'Walter Fendt',
    ];
    foreach ($byHost as $h => $label)
        if ($host === $h || str_ends_with($host, ".$h"))
            return $label;
    return $host;
}

/**
 * Host de una dirección http(s) LIMPIA, en minúsculas; null si no lo es.
 *
 * «Limpia» = la entienden igual PHP y el navegador. Bastaba con ^https?:// y
 * un host de parse_url, y ahí había una diferencia entre parsers: en
 * «https://evil.example\@phet.colorado.edu/x» PHP ve el host
 * phet.colorado.edu y el navegador (que trata «\» como «/») carga
 * evil.example. La ficha decía «Creado por PhET · phet.colorado.edu» con el
 * iframe en otra web, y la lista negra de dominios se saltaba igual
 * [revisión 2026-09]. Por eso, en ningún sitio de la dirección:
 *   · ni espacios ni controles ni «\»;
 *   · ni usuario/contraseña («alguien@host»);
 * y el host solo con letras, cifras, puntos y guiones.
 * Las 361 direcciones del catálogo local la cumplen (medido).
 */
function iarepo_http_host(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 2048 || !preg_match('#^https?://#i', $url))
        return null;
    if (preg_match('/[\x00-\x20\x7F\\\\]/', $url))
        return null;
    $p = parse_url($url);
    if (!is_array($p) || isset($p['user']) || isset($p['pass']))
        return null;
    $host = strtolower((string) ($p['host'] ?? ''));
    return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) ? $host : null;
}

/** La dirección (recortada) si es http(s) limpia (iarepo_http_host); si no, ''. */
function iarepo_safe_http_url(?string $url): string
{
    return iarepo_http_host($url) !== null ? trim((string) $url) : '';
}

/**
 * Quién puede ver un recurso restringido ('school' o 'area'), dicho con
 * verdad. Con tenant 0 —todas las cuentas de Google de iarepo.com— canView()
 * deja verlo a CUALQUIERA con cuenta, así que «Solo para tu centro» prometía
 * una privacidad que no existe. Lo usan la ficha y Mi panel (antes cada una
 * decía una cosa) [revisión 2026-09].
 */
function iarepo_restricted_label(string $visibility, int $tenant): string
{
    if ($tenant <= 0)
        return t('Con cuenta en iarepo');
    return $visibility === 'area' ? t('Solo para tu departamento') : t('Solo para tu centro');
}

/**
 * Iniciales para el sello de la fuente ("PhET" → "Ph", "Physics Simulations"
 * → "PS", "NASA / STScI" → "NS"). Las «palabras» sin letras ni cifras (una
 * barra, un «&») no cuentan: «NASA / STScI» daba «N/».
 */
function iarepo_source_mono(string $label): string
{
    $words = array_values(array_filter(
        preg_split('/[\s\-]+/u', trim($label)) ?: [],
        static fn(string $w): bool => (bool) preg_match('/[\p{L}\p{N}]/u', $w)
    ));
    if (count($words) >= 2)
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    return mb_substr($words[0] ?? '', 0, 2);
}

/**
 * El tema que se pinta en la portada generativa. topic_tag a veces es una
 * lista («waves,introduction»): la portada pintaba «WAVES,INTRODU…». Se
 * enseña solo el primero; '' si no hay.
 */
function iarepo_topic_label(?string $topicTag): string
{
    foreach (explode(',', (string) $topicTag) as $t)
        if (($t = trim($t)) !== '')
            return $t;
    return '';
}

/** Cómo se abre, en palabras de aula (sustituye a los chips «url», «html5»). */
function iarepo_opens_label(?string $codeType): string
{
    return match ((string) $codeType) {
        'url'    => t('Se abre en la web original'),
        'embed'  => t('Se abre aquí'),
        'html'   => t('Simulación incluida'),
        'prompt' => t('Prompt para IA'),
        'python' => t('Código Python'),
        default  => t('Recurso interactivo'),
    };
}

// ── Cifras públicas ──────────────────────────────────────────────
// Ningún contador público a cero ni congelado como protagonista (rediseño
// 2026-09). En público, «Abierto por N personas» solo si N = view_count +
// unique_views ≥ 10, y «Usado en clase por N docentes» solo si use_count ≥ 3:
// un «0 vistas» o un «1 uso» no ayuda a decidir y hace que el recurso parezca
// abandonado. Al AUTOR se le enseñan siempre sus cifras reales. La ficha y el
// perfil leen ESTOS valores: antes cada una tenía los suyos y un test vigilaba
// que no se separasen.
const IAREPO_PROOF_MIN_OPENS    = 10;   // «Abierto por N personas»
const IAREPO_PROOF_MIN_TEACHERS = 3;    // «Usado en clase por N docentes»

/** Un número con el separador de miles del idioma de la interfaz (1,234 / 1.234). */
function iarepo_num(int $n): string
{
    return lang() === 'en' ? number_format($n) : number_format($n, 0, ',', '.');
}

/** Idioma del recurso en palabras. */
function iarepo_lang_label(?string $lang): string
{
    return match ((string) $lang) {
        'es'    => t('En español'),
        'en'    => t('En inglés'),
        'pt'    => t('En portugués'),
        default => '',
    };
}

/**
 * Añade a una fila de recurso las etiquetas ya calculadas (API y páginas).
 * No toca las columnas originales: los consumidores existentes (Campus) no
 * notan nada, solo campos nuevos.
 */
function iarepo_with_labels(array $r): array
{
    $source = iarepo_source_label($r);
    $r['category_label'] = iarepo_category_label($r['category_slug'] ?? null, $r['category_name'] ?? null);
    $r['subject_class']  = iarepo_subject_class($r['category_slug'] ?? null);
    $r['level_label']    = iarepo_level_label($r['level'] ?? null);
    $r['source_label']   = $source;
    $r['source_mono']    = $source !== null ? iarepo_source_mono($source) : null;
    $r['opens_label']    = iarepo_opens_label($r['code_type'] ?? null);
    $r['lang_label']     = iarepo_lang_label($r['lang'] ?? null);
    $r['topic_label']    = iarepo_topic_label($r['topic_tag'] ?? null);
    $r['thumb']          = iarepo_thumb((int) ($r['id'] ?? 0));
    return $r;
}
