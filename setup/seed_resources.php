<?php
// ================================================================
// setup/seed_resources.php — siembra los recursos propios (_clases)
//
// Run: IAREPO_CLASES_PATH=/ruta/a/_clases php setup/seed_resources.php
//
// Credenciales: se leen de .env.php vía shared/db.php, como todo lo demás.
// NUNCA se escriben aquí: este repo es público (se espeja en GitHub).
// Lo mismo vale para la ruta de los ficheros fuente, que depende del
// servidor y llega por IAREPO_CLASES_PATH.
// ================================================================

$basePath = getenv('IAREPO_CLASES_PATH') ?: '';
if ($basePath === '') {
    fwrite(STDERR, "❌ Falta IAREPO_CLASES_PATH: la ruta de la carpeta _clases en este servidor.\n");
    fwrite(STDERR, "   Ej.: IAREPO_CLASES_PATH=\"\$HOME/dominios/…/public_html/_clases\" php setup/seed_resources.php\n");
    exit(1);
}
$basePath = rtrim($basePath, '/');

require_once __DIR__ . '/../shared/db.php';
$db = getResourcesDB();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$resources = [
    // IB Physics
    ['title' => 'B.1 Thermal Energy Transfers',
     'desc'  => 'Interactive data booklet for IB Physics Topic B.1 — Thermal energy transfers with formulas, diagrams and calculations.',
     'area'  => 'Physics', 'topic' => 'Thermal Energy',
     'file'  => "$basePath/IB/B.1_Thermal_energy_transfers.html"],

    ['title' => 'IB Data Guide',
     'desc'  => 'Complete IB Physics Data Booklet — interactive version with all constants, equations and units.',
     'area'  => 'Physics', 'topic' => 'Data Booklet',
     'file'  => "$basePath/IB/Data_guide.html"],

    ['title' => 'Simple Harmonic Motion (SHM)',
     'desc'  => 'Interactive SHM simulator and guide with displacement, velocity and acceleration graphs.',
     'area'  => 'Physics', 'topic' => 'Oscillations',
     'file'  => "$basePath/IB/SHM.html"],

    ['title' => 'Waves C.2-C.3',
     'desc'  => 'IB Physics Wave phenomena — standing waves, diffraction, interference and resolution.',
     'area'  => 'Physics', 'topic' => 'Waves',
     'file'  => "$basePath/IB/waves-C2-C3.html"],

    // SHM simulator
    ['title' => 'SHM Simulator',
     'desc'  => 'Interactive Simple Harmonic Motion simulator with real-time graphs.',
     'area'  => 'Physics', 'topic' => 'Oscillations',
     'file'  => "$basePath/IB/shm-simulator/index.html"],

    // EBR
    ['title' => 'Combinación de Lentes',
     'desc'  => 'Simulador interactivo de combinación de lentes convergentes y divergentes para óptica geométrica.',
     'area'  => 'Física', 'topic' => 'Óptica',
     'file'  => "$basePath/ebr/Combinacion_Lentes.html"],

    // General
    ['title' => 'Guía LaTeX',
     'desc'  => 'Tutorial interactivo de LaTeX para escritura científica y fórmulas matemáticas.',
     'area'  => 'General', 'topic' => 'LaTeX',
     'file'  => "$basePath/recursos/Latex.html"],

    ['title' => 'Presentación Profesional',
     'desc'  => 'Guía interactiva para crear presentaciones profesionales y efectivas.',
     'area'  => 'General', 'topic' => 'Presentaciones',
     'file'  => "$basePath/recursos/presentacion.html"],
];

$stmt = $db->prepare("
    INSERT INTO resources
        (title, description, code_content, code_type, subject_area, topic_tag,
         visibility, author_user_id, author_tenant_id, author_display_name, author_tenant_name, current_version)
    VALUES (?, ?, ?, 'html', ?, ?, 'community', 1, 1, 'Jackson Smirnov', 'Clase Privada', 1)
");

$verStmt = $db->prepare("
    INSERT INTO resource_versions
        (resource_id, version_number, code_content, editor_user_id,
         editor_display_name, editor_tenant_name, change_description)
    VALUES (?, 1, ?, 1, 'Jackson Smirnov', 'Clase Privada', 'Initial version')
");

$count = 0;
foreach ($resources as $r) {
    if (!file_exists($r['file'])) {
        echo "SKIP: {$r['title']} (file not found: {$r['file']})\n";
        continue;
    }

    $content = file_get_contents($r['file']);
    if (!$content) {
        echo "SKIP: {$r['title']} (empty file)\n";
        continue;
    }

    try {
        $stmt->execute([$r['title'], $r['desc'], $content, $r['area'], $r['topic']]);
        $id = $db->lastInsertId();
        $verStmt->execute([$id, $content]);
        $count++;
        $size = strlen($content);
        echo "✅ {$r['title']} ({$size} bytes) → ID $id\n";
    } catch (Exception $e) {
        echo "ERROR: {$r['title']} — {$e->getMessage()}\n";
    }
}

echo "\n🎉 $count resources seeded!\n";
