<?php
// ================================================================
// legal/terms.php — Términos de Servicio, Uso y Atribución
//
// ⚖️ El TEXTO de esta página es un documento publicado con el nombre del
// responsable encima, y tests/unit/tracking_test.php y comprehension_test.php
// lo leen: se toca solo con revisión (CLAUDE.md §8). El rediseño de 2026-09
// cambió ÚNICAMENTE el aspecto: cabecera y pie comunes, assets/css/app.css,
// tipografía del sistema (sin Google Fonts: avisaba a un tercero de cada
// visita) y una medida de línea legible. Ni una palabra del texto.
//
// El texto solo existe en español: la página se marca lang="es" en <main>,
// aunque la cabecera y el pie sigan el idioma elegido de la interfaz.
// ================================================================
// Primero de todo: los errores de esta página se registran y se ven (y nunca
// dejan media página). Ver shared/page_errors.php.
require_once __DIR__ . '/../shared/page_errors.php';

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/i18n.php';
require_once __DIR__ . '/../shared/ui.php';
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
lang();

// La sesión solo se lee si YA existe: una página legal no abre sesiones (ni
// deja cookie) a quien solo viene a leerla.
$user = isset($_COOKIE[session_name()]) ? getSessionUser() : null;

$pageTitle = 'Términos de Servicio';
$pageDesc  = 'Términos de uso, política de atribución y licencia de iarepo.com — repositorio abierto de recursos educativos.';
?>
<!DOCTYPE html>
<html lang="<?= lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> — iarepo</title>
    <meta name="description" content="<?= h($pageDesc) ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://iarepo.com/legal/terms.php">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <meta name="theme-color" content="#F6F7F9">
    <?= iarepo_head_assets() ?>
    <style>
        /* Solo lo propio de un texto largo; lo común vive en assets/css/app.css.
           Las clases (.updated, .highlight, .badge-*, table, code) son las que
           ya usaba el texto: se restilan, no se tocan. */
        .legal { max-width: 76ch; padding-top: 32px; padding-bottom: 24px; font-size: 1.0625rem; line-height: 1.7; }
        .legal h1 { font-size: clamp(1.8rem, 1.4rem + 1.6vw, 2.4rem); margin-bottom: 6px; }
        .legal .updated { color: var(--ia-ink-3); font-size: .9rem; margin-bottom: 28px; }
        .legal h2 { font-size: 1.3rem; margin: 40px 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--ia-line); }
        .legal h3 { font-size: 1.08rem; margin: 24px 0 8px; }
        .legal p, .legal li { color: var(--ia-ink-2); }
        .legal li { margin-bottom: 8px; }
        .legal ul, .legal ol { padding-left: 24px; margin: 0 0 1em; }
        .legal strong { color: var(--ia-ink); }
        .legal .highlight { background: var(--ia-accent-soft); border-left: 4px solid var(--ia-accent); padding: 14px 18px; border-radius: 0 10px 10px 0; margin: 20px 0; }
        .legal .highlight p { margin: 0; color: var(--ia-ink); }
        .legal code { background: var(--ia-surface-2); padding: 1px 6px; border-radius: 4px; font: .92em var(--ia-mono); }
        .legal .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8125rem; font-weight: 700; white-space: nowrap; }
        .legal .badge-green { background: var(--ia-ok-soft); color: var(--ia-ok); }
        .legal .badge-blue { background: var(--ia-surface-2); color: var(--ia-ink); }
        .legal .badge-purple { background: var(--ia-accent-soft); color: var(--ia-accent); }
        /* Tablas: en móvil se desplazan dentro de su caja, nunca ensanchan la página. */
        .legal table { display: block; overflow-x: auto; width: 100%; border-collapse: collapse; margin: 16px 0; font-size: .95rem; }
        .legal th, .legal td { padding: 10px 12px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--ia-line); }
        .legal th { background: var(--ia-surface-2); color: var(--ia-ink); font-weight: 700; }
        .legal-colophon { margin-top: 48px; padding-top: 20px; border-top: 1px solid var(--ia-line); text-align: center; font-size: .9rem; }
        .legal-colophon p { color: var(--ia-ink-3); margin-bottom: 4px; }
        .legal-note-wrap { max-width: 76ch; padding-top: 24px; }
        .legal-lang-note { margin: 0; display: flex; gap: 8px; align-items: center; padding: 10px 14px; border-radius: 10px; background: var(--ia-surface-2); color: var(--ia-ink-2); font-size: .95rem; }
        .legal-lang-note svg { width: 18px; height: 18px; flex: none; }
    </style>
    <?php require_once __DIR__ . '/../shared/error_tracker.php'; ?>
</head>
<body class="ia-page">
<?php iarepo_header($user, ''); ?>
<?php if (lang() !== 'es'): ?>
<div class="ia-container legal-note-wrap"><p class="legal-lang-note" lang="<?= lang() ?>"><i data-lucide="languages" aria-hidden="true"></i><?= h(t('Este texto legal solo está disponible en español.')) ?></p></div>
<?php endif; ?>
<main id="main" class="ia-container legal" lang="es">
    <h1>📜 Términos de Servicio y Uso</h1>
    <p class="updated">Última actualización: 4 de mayo de 2026</p>

    <div class="highlight">
        <p><strong>iarepo.com</strong> es un repositorio <strong>abierto, gratuito y sin fines de lucro</strong> de recursos educativos interactivos.
        No vendemos contenido. No monetizamos recursos de terceros. Existimos para que profesores del mundo
        encuentren y compartan herramientas educativas en un solo lugar.</p>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <h2>1. ¿Qué es iarepo?</h2>
    <p>iarepo es una plataforma que:</p>
    <ul>
        <li><strong>Agrega</strong> enlaces a recursos educativos interactivos de acceso libre (simulaciones, herramientas, visualizaciones).</li>
        <li><strong>Cataloga</strong> estos recursos por materia, nivel educativo e idioma para facilitar su descubrimiento.</li>
        <li><strong>Permite</strong> a profesores registrados subir y compartir sus propios recursos originales.</li>
        <li><strong>NO aloja</strong> copias del contenido de terceros — los recursos externos se muestran mediante enlaces (iframe o enlace directo) al sitio original del autor.</li>
    </ul>

    <!-- ═══════════════════════════════════════════ -->
    <h2>2. Tipos de Contenido</h2>
    <table>
        <thead>
            <tr><th>Tipo</th><th>Descripción</th><th>Propiedad</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-green">Original</span></td>
                <td>Recursos creados y subidos por usuarios de iarepo</td>
                <td>Del autor que lo subió</td>
            </tr>
            <tr>
                <td><span class="badge badge-blue">Enlazado</span></td>
                <td>Recursos externos mostrados vía iframe o enlace al sitio original</td>
                <td>Del autor/institución original</td>
            </tr>
            <tr>
                <td><span class="badge badge-purple">Recreado</span></td>
                <td>Recreaciones HTML5 de simulaciones clásicas descontinuadas (Java/Flash)</td>
                <td>De iarepo, con crédito al concepto original</td>
            </tr>
        </tbody>
    </table>

    <!-- ═══════════════════════════════════════════ -->
    <h2>3. Política de Atribución</h2>
    <p>Respetamos y valoramos el trabajo de todos los creadores. Nuestra política es clara:</p>

    <h3>3.1 Recursos enlazados (externos)</h3>
    <ul>
        <li>Siempre mostramos el <strong>nombre de la fuente original</strong> (ej: "PhET Interactive Simulations", "GeoGebra") junto al recurso.</li>
        <li>Incluimos un <strong>enlace directo al sitio original</strong> del autor en el visor.</li>
        <li><strong>No alojamos copias</strong> del contenido — el contenido se carga directamente desde el servidor del autor original.</li>
        <li><strong>No monetizamos</strong> el contenido de terceros de ninguna forma.</li>
        <li>Si un autor o institución solicita que retiremos un enlace a su recurso, lo haremos de inmediato.</li>
    </ul>

    <h3>3.2 Recursos originales (subidos por usuarios)</h3>
    <ul>
        <li>El autor conserva todos los derechos sobre su contenido.</li>
        <li>Al publicar en iarepo, el autor otorga una licencia no exclusiva para mostrar el recurso en la plataforma.</li>
        <li>El autor puede retirar su contenido en cualquier momento.</li>
    </ul>

    <h3>3.3 Recreaciones de simulaciones clásicas</h3>
    <ul>
        <li>Las recreaciones HTML5 son código original escrito por o para iarepo.</li>
        <li>Siempre acreditamos el <strong>concepto, nombre e institución original</strong> (ej: "Concepto original: NTNU Virtual Physics Lab").</li>
        <li>El <code>source_prompt</code> (prompt de IA utilizado para la recreación) se hace público por transparencia.</li>
    </ul>

    <!-- ═══════════════════════════════════════════ -->
    <h2>4. Licencias de Fuentes Principales</h2>
    <table>
        <thead>
            <tr><th>Fuente</th><th>Licencia</th><th>Nuestro uso</th></tr>
        </thead>
        <tbody>
            <tr><td>PhET (U. Colorado)</td><td>CC-BY 4.0</td><td>Embed con atribución</td></tr>
            <tr><td>GeoGebra</td><td>CC-BY-NC-SA</td><td>Enlace educativo no comercial</td></tr>
            <tr><td>oPhysics</td><td>Libre educativo</td><td>Enlace educativo</td></tr>
            <tr><td>Physics Simulations</td><td>Libre educativo</td><td>Enlace educativo</td></tr>
            <tr><td>Desmos</td><td>Libre educativo</td><td>Enlace educativo</td></tr>
            <tr><td>Concord Consortium</td><td>Libre / OER</td><td>Enlace educativo</td></tr>
        </tbody>
    </table>

    <!-- ═══════════════════════════════════════════ -->
    <h2>5. Uso Aceptable</h2>
    <p>Al usar iarepo, te comprometes a:</p>
    <ul>
        <li>Utilizar los recursos únicamente con <strong>fines educativos</strong>.</li>
        <li><strong>No redistribuir</strong> recursos de terceros como propios.</li>
        <li><strong>No subir</strong> contenido que infrinja derechos de autor, sea ofensivo o ilegal.</li>
        <li><strong>No usar</strong> la plataforma para spam, publicidad o contenido no educativo.</li>
        <li>Respetar la <strong>atribución</strong> de los recursos al compartirlos fuera de iarepo.</li>
    </ul>

    <!-- ═══════════════════════════════════════════ -->
    <h2>6. Sostenibilidad y Publicidad</h2>
    <p>iarepo es y será <strong>gratuito para todos los usuarios</strong>. Sin embargo, mantener servidores y dominios tiene un costo. Para garantizar la continuidad del proyecto:</p>
    <ul>
        <li>Podremos mostrar <strong>publicidad no intrusiva</strong> en páginas de la plataforma (búsqueda, landing, perfil) y en el visor de <strong>recursos originales subidos por usuarios de iarepo</strong>.</li>
        <li><strong>Nunca</strong> mostraremos publicidad en el visor de <strong>recursos externos</strong> (PhET, GeoGebra, oPhysics, etc.) — no monetizamos trabajo ajeno.</li>
        <li>Aceptamos <strong>donaciones voluntarias</strong> como alternativa a la publicidad.</li>
        <li>Si la plataforma es sostenida por otros medios (ej: proyectos hermanos), se mantendrá <strong>100% libre de publicidad</strong>.</li>
    </ul>
    <div class="highlight">
        <p>💚 <strong>Compromiso:</strong> Si algún día iarepo genera ingresos, los excedentes se reinvertirán en mejorar la plataforma y crear más recursos educativos abiertos. Nunca en beneficio privado.</p>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <h2>7. Sin Garantía</h2>
    <p>iarepo se proporciona "tal cual". No garantizamos:</p>
    <ul>
        <li>La disponibilidad continua de recursos externos (dependen de sus servidores originales).</li>
        <li>La exactitud científica de los recursos enlazados (eso es responsabilidad del autor original).</li>
        <li>La disponibilidad ininterrumpida de la plataforma.</li>
    </ul>
    <p>Nuestro <a href="/setup/cron_link_checker.php">verificador automático de enlaces</a> revisa periódicamente que los recursos externos sigan activos, y oculta automáticamente los que dejan de funcionar.</p>

    <!-- ═══════════════════════════════════════════ -->
    <h2>8. Solicitudes de Retiro (Takedown)</h2>
    <p>Si eres el autor o representante legal de un recurso enlazado en iarepo y deseas que lo retiremos:</p>
    <ol>
        <li>Envía un correo a <strong>legal@iarepo.com</strong> (o contacta vía GitHub Issues).</li>
        <li>Indica la URL del recurso en iarepo y la URL original.</li>
        <li>Confirma que eres el titular de los derechos.</li>
        <li>Retiraremos el enlace en un plazo máximo de <strong>48 horas</strong>.</li>
    </ol>

    <!-- ═══════════════════════════════════════════ -->
    <h2>9. Código Abierto</h2>
    <div class="highlight">
        <p>🌍 <strong>iarepo es software de código abierto.</strong><br>
        El código fuente de la plataforma está disponible bajo la licencia <strong>MIT</strong> en
        <a href="https://github.com/claseprivada/iarepo" target="_blank">github.com/claseprivada/iarepo</a>.<br>
        Puedes usarlo, modificarlo y distribuirlo libremente. Las contribuciones son bienvenidas.</p>
    </div>

    <!-- ═══════════════════════════════════════════ -->
    <h2>10. Privacidad</h2>
    <ul>
        <li>
            Los usuarios registrados que entran con Google aportan
            <strong>nombre, correo, foto de perfil</strong> y el identificador que Google
            asigna a su cuenta. Guardamos además la fecha del último acceso.
        </li>
        <li><strong>No vendemos ni compartimos datos con terceros.</strong></li>
        <li>No usamos cookies de rastreo ni publicidad.</li>
    </ul>

    <h3>10.1 Medición de uso de los recursos</h3>
    <p>
        Para saber qué recursos resultan útiles a quien da clase, contamos cuántas
        personas distintas abren cada recurso. Esto es lo único que ocurre, dicho
        con precisión:
    </p>
    <ul>
        <li>
            Tu navegador genera <strong>un identificador aleatorio</strong> y lo guarda
            en el almacenamiento local del propio navegador. <strong>No es una cookie</strong>,
            no viaja a otros sitios web y no está ligado a tu nombre, tu correo ni tu
            dirección IP.
        </li>
        <li>
            Se registra <strong>qué recurso se abrió, en qué fecha, cuánto tiempo estuvo
            visible en pantalla</strong> y si llegó a interactuarse con él. No se registra
            nada de lo que ocurre <em>dentro</em> del recurso: se ejecuta aislado y no
            podemos ver su contenido.
        </li>
        <li>
            Ese identificador <strong>nunca se almacena tal cual</strong>. Se guarda una
            huella criptográfica calculada con una clave que <strong>se destruye a los dos
            días</strong>. Pasado ese plazo, ni nosotros podemos volver a relacionar un
            registro con el navegador que lo produjo, ni enlazar dos días distintos de la
            misma persona.
        </li>
        <li>
            El resultado sólo se usa <strong>de forma agregada</strong> («este recurso lo
            abrieron 34 personas»). No construimos perfiles ni seguimos a nadie entre
            sitios.
        </li>
    </ul>
    <p>
        <strong>Cómo desactivarlo:</strong> borra los datos del sitio desde tu navegador
        o navega en una ventana privada. Si el almacenamiento local no está disponible,
        la web funciona igual: simplemente no se cuenta la visita.
    </p>

    <h3>10.2 La pregunta «¿te quedó claro?»</h3>
    <p>
        Después de usar un recurso durante un rato puede aparecer una pregunta con tres
        respuestas posibles: <em>me quedó claro</em>, <em>más o menos</em> o
        <em>me perdí</em>. Es opcional y se puede cerrar sin contestar.
    </p>
    <ul>
        <li>
            <strong>No es una valoración del recurso ni una nota.</strong> Sirve para que
            quien lo publicó sepa si se entiende y pueda mejorarlo.
        </li>
        <li>
            La respuesta se guarda <strong>de la misma forma anónima</strong> descrita
            arriba, contra la misma huella criptográfica que caduca a los dos días.
            <strong>En ningún momento se registra quién contestó qué</strong>, ni siquiera
            para quien publicó el recurso: sólo ve cuántas respuestas de cada tipo hay.
        </li>
        <li>
            <strong>No hay campo de texto libre</strong>, únicamente esas tres opciones.
        </li>
        <li>
            El resultado <strong>no se muestra públicamente</strong>: sólo lo ve el autor
            del recurso, en su panel.
        </li>
    </ul>

    <h3>10.3 Direcciones IP</h3>
    <p>
        <strong>La medición descrita en 10.1 y 10.2 no usa ni almacena direcciones IP.</strong>
        Ni en claro ni cifradas: la huella con la que se cuenta una visita se calcula a
        partir del identificador que genera tu navegador, nunca de tu conexión.
    </p>
    <p>
        Aparte de eso, y como cualquier servidor web, el nuestro sí ve tu IP y la usa para
        dos cosas <strong>técnicas</strong>, no analíticas:
    </p>
    <ul>
        <li>
            <strong>Limitar el abuso.</strong> Se guarda de forma temporal para contar
            cuántas peticiones llegan desde una misma conexión y frenar ataques. Se
            registra la conexión y el servicio llamado, <strong>no qué recurso has
            visto</strong>, y estos registros se borran automáticamente.
        </li>
        <li>
            <strong>Registro de errores.</strong> Cuando algo falla, el error queda anotado
            en el registro del servidor junto con la dirección desde la que ocurrió, para
            poder diagnosticarlo.
        </li>
    </ul>
    <p>
        Esos registros <strong>no comparten ningún identificador</strong> con los datos de
        uso, y no los cruzamos. Tampoco guardan qué recurso se abrió, así que por sí solos
        no dicen qué ha visto nadie.
    </p>

    <!-- ═══════════════════════════════════════════ -->
    <h2>11. Contacto</h2>
    <p>Para cualquier consulta legal, solicitud de retiro, o colaboración:</p>
    <ul>
        <li>📧 <strong>legal@iarepo.com</strong></li>
        <li>🐙 <a href="https://github.com/claseprivada/iarepo/issues" target="_blank">GitHub Issues</a></li>
        <li>🌐 <a href="https://iarepo.com">iarepo.com</a></li>
    </ul>

    <div class="legal-colophon">
        <p>© 2026 iarepo — Proyecto de código abierto por <a href="https://claseprivada.com">claseprivada.com</a></p>
        <p>Hecho con ❤️ para profesores del mundo.</p>
    </div>
</main>
<?php iarepo_footer($user); ?>
<?= iarepo_body_assets() ?>
</body>
</html>
