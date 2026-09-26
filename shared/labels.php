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

    $host = strtolower((string) parse_url((string) ($r['source_url'] ?? ''), PHP_URL_HOST));
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

/** Iniciales para el sello de la fuente ("PhET" → "Ph", "Physics Simulations" → "PS"). */
function iarepo_source_mono(string $label): string
{
    $words = preg_split('/[\s\-]+/u', trim($label)) ?: [];
    if (count($words) >= 2)
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    return mb_substr($label, 0, 2);
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
    return $r;
}
