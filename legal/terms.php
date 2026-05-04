<?php
// ================================================================
// legal/terms.php — Términos de Servicio, Uso y Atribución
// ================================================================
$pageTitle = 'Términos de Servicio';
$pageDesc  = 'Términos de uso, política de atribución y licencia de iarepo.com — repositorio abierto de recursos educativos.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — iarepo</title>
    <meta name="description" content="<?= $pageDesc ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://iarepo.com/legal/terms.php">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc; --surface: #ffffff; --text: #1e293b;
            --muted: #64748b; --accent: #7c3aed; --border: #e2e8f0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg); color: var(--text);
            line-height: 1.7; font-size: 16px;
        }
        .container {
            max-width: 780px; margin: 0 auto;
            padding: 40px 24px 80px;
        }
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--accent); text-decoration: none; font-size: 14px;
            margin-bottom: 32px; font-weight: 500;
        }
        .back-link:hover { text-decoration: underline; }
        h1 {
            font-size: 2rem; font-weight: 700; margin-bottom: 8px;
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .updated { color: var(--muted); font-size: 14px; margin-bottom: 40px; }
        h2 {
            font-size: 1.3rem; font-weight: 700; margin-top: 40px;
            margin-bottom: 12px; color: var(--text);
            padding-bottom: 8px; border-bottom: 2px solid var(--border);
        }
        h3 { font-size: 1.05rem; font-weight: 600; margin-top: 24px; margin-bottom: 8px; }
        p, li { margin-bottom: 12px; color: #334155; }
        ul, ol { padding-left: 24px; }
        li { margin-bottom: 8px; }
        .highlight {
            background: linear-gradient(135deg, rgba(124,58,237,.06), rgba(6,182,212,.06));
            border-left: 4px solid var(--accent);
            padding: 16px 20px; border-radius: 0 8px 8px 0;
            margin: 20px 0;
        }
        .highlight p { margin-bottom: 0; }
        a { color: var(--accent); }
        code {
            background: #f1f5f9; padding: 2px 6px; border-radius: 4px;
            font-size: 14px; font-family: 'Fira Code', monospace;
        }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 600; margin-right: 6px;
        }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border); font-size: 14px; }
        th { background: #f8fafc; font-weight: 600; }
        footer {
            text-align: center; padding: 32px; color: var(--muted);
            font-size: 13px; border-top: 1px solid var(--border);
            margin-top: 60px;
        }
    </style>
</head>
<body>
<div class="container">
    <a href="/" class="back-link">← Volver a iarepo</a>

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
        <li>No recopilamos datos personales de visitantes anónimos.</li>
        <li>Los usuarios registrados proporcionan solo nombre y correo.</li>
        <li>No vendemos ni compartimos datos con terceros.</li>
        <li>No usamos cookies de rastreo ni publicidad.</li>
    </ul>

    <!-- ═══════════════════════════════════════════ -->
    <h2>11. Contacto</h2>
    <p>Para cualquier consulta legal, solicitud de retiro, o colaboración:</p>
    <ul>
        <li>📧 <strong>legal@iarepo.com</strong></li>
        <li>🐙 <a href="https://github.com/claseprivada/iarepo/issues" target="_blank">GitHub Issues</a></li>
        <li>🌐 <a href="https://iarepo.com">iarepo.com</a></li>
    </ul>

    <footer>
        <p>© 2026 iarepo — Proyecto de código abierto por <a href="https://claseprivada.com">claseprivada.com</a></p>
        <p>Hecho con ❤️ para profesores del mundo.</p>
    </footer>
</div>
</body>
</html>
