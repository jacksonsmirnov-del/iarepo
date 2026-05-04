<?php
// Temporary seed script — run on server then delete
$db = new PDO(
    "mysql:host=localhost;dbname=u403412230_resources;charset=utf8mb4",
    "u403412230_ib_ebr",
    "LXsrtFivPHmc7K"
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$basePath = "/home/u403412230/domains/claseprivada.com/public_html/_clases";

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
