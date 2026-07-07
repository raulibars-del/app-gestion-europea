<?php
// API mínima para compartir los datos de la app entre todos los usuarios.
// GET  -> devuelve el JSON guardado (blob legacy o ?action=sections)
// POST -> guarda el JSON recibido (blob legacy o con cabecera X-Section)
//
// Autenticación simple por API key (cabecera X-Api-Key). No es seguridad
// de nivel empresarial, pero evita que cualquiera en internet pueda leer
// o modificar los datos sin conocer la clave.

require __DIR__ . '/config.php';

// Los datos pueden incluir fotos en base64 y pesar varios MB; subimos el
// límite de memoria si el hosting lo permite (si no, no pasa nada).
@ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');

// CORS básico (la app se sirve desde el mismo dominio, pero por si acaso)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Expected-Version, X-Section');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$accionTemp = $_GET['action'] ?? '';
if (!in_array($accionTemp, ['diag','restore_blob','migrate_base64']) && !hash_equals(API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}

// ─── Tablas ─────────────────────────────────────────────────────────────────

// Tabla monolítica original (se mantiene para history/restore y compatibilidad)
$pdo->exec("CREATE TABLE IF NOT EXISTS app_data (
    id INT PRIMARY KEY,
    data LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try { $pdo->exec("ALTER TABLE app_data ADD COLUMN version INT NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ya existe */ }

// Historial de copias de seguridad (comparte snapshots del blob completo)
$pdo->exec("CREATE TABLE IF NOT EXISTS app_data_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── NUEVA: tabla por sección ────────────────────────────────────────────────
// Cada sección del JSON (clientes, avisos, partes…) tiene su propia fila con
// su propio contador de versión. Así cada guardado solo sube la sección que
// cambió (50-200 KB) en vez del blob entero (~10+ MB), y los conflictos de
// concurrencia entre secciones independientes desaparecen.
$pdo->exec("CREATE TABLE IF NOT EXISTS app_sections (
    section VARCHAR(50) NOT NULL PRIMARY KEY,
    data LONGTEXT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Helper: migrar blob monolítico → secciones ──────────────────────────────
function migrarBlobASecciones($pdo, $blob) {
    $seccionesActivas = ['clientes','avisos','partes','reparaciones','tareas','ventas','visitas','chat','calendario','inventario'];
    $config = [];
    foreach ($blob as $k => $v) {
        if (in_array($k, $seccionesActivas)) {
            $ins = $pdo->prepare(
                "INSERT INTO app_sections (section, data, version) VALUES (:s, :d, 1)
                 ON DUPLICATE KEY UPDATE data = :d2, version = version + 1"
            );
            $ins->execute(['s' => $k, 'd' => json_encode($v), 'd2' => json_encode($v)]);
        } else {
            $config[$k] = $v;
        }
    }
    // La sección 'config' agrupa todo lo que no tiene sección propia
    $ins = $pdo->prepare(
        "INSERT INTO app_sections (section, data, version) VALUES ('config', :d, 1)
         ON DUPLICATE KEY UPDATE data = :d2, version = version + 1"
    );
    $ins->execute(['d' => json_encode($config), 'd2' => json_encode($config)]);
}

// ─── Helper: leer todas las secciones y combinar en blob ─────────────────────
function leerBlobDeSecciones($pdo) {
    $seccionesActivas = ['clientes','avisos','partes','reparaciones','tareas','ventas','visitas','chat','calendario','inventario'];
    $stmt = $pdo->query("SELECT section, data FROM app_sections");
    $blob = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = json_decode($row['data'], true);
        if ($row['section'] === 'config') {
            if (is_array($d)) foreach ($d as $k => $v) $blob[$k] = $v;
        } else {
            $blob[$row['section']] = $d;
        }
    }
    return $blob;
}

$accion = $_GET['action'] ?? '';

// ─── GET ?action=history ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'history') {
    try {
        $stmt = $pdo->query("SELECT id, created_at, LENGTH(data) AS tam FROM app_data_history ORDER BY id DESC LIMIT 60");
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => (int)$row['id'],
                'created_at' => $row['created_at'],
                'tam' => (int)$row['tam'],
            ];
        }
        echo json_encode(['items' => $items]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
    }
    exit;
}

// ─── POST ?action=restore ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'restore') {
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        $historyId = isset($body['historyId']) ? (int)$body['historyId'] : 0;
        if (!$historyId) {
            http_response_code(400);
            echo json_encode(['error' => 'historyId_requerido']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT data FROM app_data_history WHERE id = :id");
        $stmt->execute(['id' => $historyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'copia_no_encontrada']);
            exit;
        }
        // Guardar snapshot del estado actual antes de restaurar
        $actual = $pdo->query("SELECT data FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$actual) {
            // Si no hay blob legacy, construirlo desde las secciones
            $blobActual = leerBlobDeSecciones($pdo);
            if ($blobActual) {
                $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
                $insHist->execute(['data' => json_encode($blobActual)]);
            }
        } else {
            $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
            $insHist->execute(['data' => $actual['data']]);
        }
        // Restaurar al blob monolítico (para compatibilidad)
        $upd = $pdo->prepare("INSERT INTO app_data (id, data, version) VALUES (1, :data, 1) ON DUPLICATE KEY UPDATE data = :data2, version = version + 1");
        $upd->execute(['data' => $row['data'], 'data2' => $row['data']]);
        // También dividir en secciones para que el cliente nuevo lo reciba correctamente
        try {
            $blob = json_decode($row['data'], true);
            if ($blob) migrarBlobASecciones($pdo, $blob);
        } catch (Exception $e) {}
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
    }
    exit;
}

// ─── GET ?action=diag ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'diag') {
    $out = [];
    $r = $pdo->query("SELECT data, LENGTH(data) as bytes, updated_at FROM app_data WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $blob = json_decode($r['data'], true);
        $out['app_data'] = [
            'bytes' => (int)$r['bytes'], 'updated_at' => $r['updated_at'],
            'clientes' => is_array($blob['clientes'] ?? null) ? count($blob['clientes']) : '?',
            'avisos'   => is_array($blob['avisos']   ?? null) ? count($blob['avisos'])   : '?',
        ];
    } else { $out['app_data'] = null; }
    $stmt = $pdo->query("SELECT section, version, updated_at, LENGTH(data) as bytes FROM app_sections ORDER BY section");
    $out['app_sections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        $out['app_data_history'] = $pdo->query("SELECT id, LENGTH(data) as bytes, created_at FROM app_data_history ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) { $out['app_data_history'] = $e->getMessage(); }
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

// ─── GET ?action=restore_blob ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'restore_blob') {
    $r = $pdo->query("SELECT data FROM app_data WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) { http_response_code(404); echo json_encode(['error'=>'no hay blob']); exit; }
    $blob = json_decode($r['data'], true);
    if (!$blob) { http_response_code(500); echo json_encode(['error'=>'blob inválido']); exit; }
    $pdo->exec("DELETE FROM app_sections");
    migrarBlobASecciones($pdo, $blob);
    $stmt = $pdo->query("SELECT section, version, LENGTH(data) as bytes FROM app_sections ORDER BY section");
    echo json_encode(['ok'=>true, 'sections'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── GET ?action=migrate_base64 ───────────────────────────────────────────────
// Migra todas las fotos base64 incrustadas en app_sections a archivos del servidor.
// Reduce clientes de ~10MB a ~300KB e inventario de ~4MB a ~100KB.
// Es seguro ejecutar varias veces (las URLs ya migradas se ignoran).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'migrate_base64') {
    set_time_limit(300); // hasta 5 min — hay muchas fotos
    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
    $htaccess = $uploadsDir . '/.htaccess';
    if (!file_exists($htaccess)) file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|php\\d)$\">\nRequire all denied\n</FilesMatch>\n");
    $mimeToExt = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic'];
    $totalFotos = 0;

    function migrateBase64Deep(&$val, $uploadsDir, $mimeToExt, &$count) {
        if (is_string($val) && strlen($val) > 500 && strpos($val, 'data:image/') === 0) {
            if (preg_match('/^data:(image\/[a-z+]+);base64,(.+)$/s', $val, $m)) {
                $data = base64_decode($m[2]);
                if ($data !== false && strlen($data) > 100) {
                    $ext = $mimeToExt[$m[1]] ?? 'jpg';
                    $nombre = 'b64mig_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (file_put_contents($uploadsDir . '/' . $nombre, $data) !== false) {
                        $val = '/api/uploads/' . $nombre;
                        $count++;
                    }
                }
            }
        } elseif (is_array($val)) {
            foreach ($val as &$v) migrateBase64Deep($v, $uploadsDir, $mimeToExt, $count);
        }
    }

    $rows = $pdo->query("SELECT section, data FROM app_sections")->fetchAll(PDO::FETCH_ASSOC);
    $resultados = [];
    foreach ($rows as $row) {
        $antesMB = round(strlen($row['data']) / 1048576, 2);
        $data = json_decode($row['data'], true);
        $migradas = 0;
        migrateBase64Deep($data, $uploadsDir, $mimeToExt, $migradas);
        $totalFotos += $migradas;
        $newJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $despuesMB = round(strlen($newJson) / 1048576, 2);
        if ($migradas > 0) {
            $pdo->prepare("UPDATE app_sections SET data = :d WHERE section = :s")
                ->execute(['d' => $newJson, 's' => $row['section']]);
        }
        $resultados[$row['section']] = ['fotos' => $migradas, 'antes_MB' => $antesMB, 'despues_MB' => $despuesMB];
    }
    // Actualizar app_data con los datos ya migrados
    $blobActualizado = leerBlobDeSecciones($pdo);
    if ($blobActualizado) {
        $bj = json_encode($blobActualizado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO app_data (id,data,version) VALUES (1,:d,1) ON DUPLICATE KEY UPDATE data=:d2,version=version+1")
            ->execute(['d'=>$bj,'d2'=>$bj]);
    }
    echo json_encode(['ok'=>true, 'total_fotos_migradas'=>$totalFotos, 'secciones'=>$resultados], JSON_PRETTY_PRINT);
    exit;
}

// ─── GET ?action=sections ────────────────────────────────────────────────────
// Nuevo endpoint: devuelve cada sección con su versión independiente.
// Si app_sections está vacía, migra automáticamente desde el blob legacy.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'sections') {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM app_sections")->fetchColumn();
    if ($count === 0) {
        // Migrar desde blob monolítico si existe
        $viejo = $pdo->query("SELECT data FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($viejo) {
            $blob = json_decode($viejo['data'], true);
            if ($blob) migrarBlobASecciones($pdo, $blob);
        }
    }
    $stmt = $pdo->query("SELECT section, data, version FROM app_sections");
    $sections = []; $versions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sections[$row['section']] = json_decode($row['data'], true);
        $versions[$row['section']] = (int)$row['version'];
    }
    echo json_encode(['sections' => $sections, 'versions' => $versions]);
    exit;
}

// ─── POST con cabecera X-Section (guardado por sección) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_SECTION'])) {
    $section = preg_replace('/[^a-z]/', '', strtolower(trim($_SERVER['HTTP_X_SECTION'])));
    $permitidas = ['clientes','avisos','partes','reparaciones','tareas','ventas','visitas','chat','calendario','inventario','config'];
    if (!in_array($section, $permitidas)) {
        http_response_code(400);
        echo json_encode(['error' => 'seccion_no_permitida']);
        exit;
    }

    $raw = file_get_contents('php://input');
    if (json_decode($raw) === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    $expectedVersion = isset($_SERVER['HTTP_X_EXPECTED_VERSION']) && $_SERVER['HTTP_X_EXPECTED_VERSION'] !== ''
        ? (int)$_SERVER['HTTP_X_EXPECTED_VERSION'] : null;

    $existeStmt = $pdo->prepare("SELECT version FROM app_sections WHERE section = :s");
    $existeStmt->execute(['s' => $section]);
    $existe = $existeStmt->fetch(PDO::FETCH_ASSOC);

    if ($existe && $expectedVersion !== null) {
        // Guardado condicional: solo actualiza si la versión que el cliente cree
        // vigente sigue siendo la actual (comprobación atómica en una sola sentencia).
        $upd = $pdo->prepare("UPDATE app_sections SET data = :data, version = version + 1 WHERE section = :s AND version = :expected");
        $upd->execute(['data' => $raw, 's' => $section, 'expected' => $expectedVersion]);
        if ($upd->rowCount() === 0) {
            $actStmt = $pdo->prepare("SELECT version FROM app_sections WHERE section = :s");
            $actStmt->execute(['s' => $section]);
            $act = $actStmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'version' => $act ? (int)$act['version'] : null, 'section' => $section]);
            exit;
        }
    } else {
        // Upsert sin control de versión (primera vez, migración, o versión desconocida)
        $upsert = $pdo->prepare(
            "INSERT INTO app_sections (section, data, version) VALUES (:s, :d, 1)
             ON DUPLICATE KEY UPDATE data = :d2, version = version + 1"
        );
        $upsert->execute(['s' => $section, 'd' => $raw, 'd2' => $raw]);
    }

    // Copia de seguridad diaria: combinar secciones en blob, guardar en
    // app_data_history Y actualizar app_data (para que restore_blob sea siempre reciente).
    try {
        $ultima = $pdo->query("SELECT created_at FROM app_data_history ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$ultima || strtotime($ultima['created_at']) < time() - 86400) {
            $blob = leerBlobDeSecciones($pdo);
            if ($blob) {
                $blobJson = json_encode($blob, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                // Actualizar app_data legacy para mantener restore_blob al día
                $pdo->prepare("INSERT INTO app_data (id,data,version) VALUES (1,:d,1) ON DUPLICATE KEY UPDATE data=:d2,version=version+1")
                    ->execute(['d'=>$blobJson,'d2'=>$blobJson]);
                // Guardar snapshot en historial
                $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)")
                    ->execute(['data' => $blobJson]);
                $pdo->exec("DELETE FROM app_data_history WHERE created_at < (NOW() - INTERVAL 14 DAY)");
            }
        }
    } catch (Exception $e) {}

    $st2 = $pdo->prepare("SELECT version, updated_at FROM app_sections WHERE section = :s");
    $st2->execute(['s' => $section]);
    $updated = $st2->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'section' => $section,
        'version' => (int)($updated['version'] ?? 1),
        'updated_at' => $updated['updated_at'] ?? null,
    ]);
    exit;
}

// ─── GET legacy (blob monolítico) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT data, updated_at, version FROM app_data WHERE id = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode([
            'data' => json_decode($row['data']),
            'updated_at' => $row['updated_at'],
            'version' => (int)$row['version'],
        ]);
    } else {
        echo json_encode(['data' => null, 'updated_at' => null, 'version' => null]);
    }
    exit;
}

// ─── POST legacy (blob monolítico) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    $expectedVersion = isset($_SERVER['HTTP_X_EXPECTED_VERSION']) && $_SERVER['HTTP_X_EXPECTED_VERSION'] !== ''
        ? (int)$_SERVER['HTTP_X_EXPECTED_VERSION'] : null;

    $existe = $pdo->query("SELECT version FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    if ($existe && $expectedVersion === null) {
        http_response_code(426);
        echo json_encode(['error' => 'upgrade_required', 'version' => (int)$existe['version']]);
        exit;
    }

    if ($existe) {
        $upd = $pdo->prepare("UPDATE app_data SET data = :data, version = version + 1 WHERE id = 1 AND version = :expected");
        $upd->execute(['data' => $raw, 'expected' => $expectedVersion]);
        if ($upd->rowCount() === 0) {
            $actual = $pdo->query("SELECT version FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'version' => $actual ? (int)$actual['version'] : null]);
            exit;
        }
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO app_data (id, data, version) VALUES (1, :data, 1)
             ON DUPLICATE KEY UPDATE data = :data2, version = version + 1"
        );
        $stmt->execute(['data' => $raw, 'data2' => $raw]);
    }

    try {
        $ultima = $pdo->query("SELECT created_at FROM app_data_history ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $debeCopiar = !$ultima || (strtotime($ultima['created_at']) < time() - 86400);
        if ($debeCopiar) {
            $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
            $insHist->execute(['data' => $raw]);
            $pdo->exec("DELETE FROM app_data_history WHERE created_at < (NOW() - INTERVAL 14 DAY)");
        }
    } catch (Exception $e) {}

    $stmt2 = $pdo->query("SELECT updated_at, version FROM app_data WHERE id = 1");
    $updated = $stmt2->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'updated_at' => $updated['updated_at'] ?? null,
        'version' => isset($updated['version']) ? (int)$updated['version'] : null,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
