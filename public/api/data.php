<?php
// API mínima para compartir los datos de la app entre todos los usuarios.
// GET  -> devuelve el JSON guardado
// POST -> guarda el JSON recibido (sobrescribe el anterior)
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
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Expected-Version');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(API_KEY, $apiKey)) {
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

// Crear la tabla si todavía no existe (primera ejecución)
$pdo->exec("CREATE TABLE IF NOT EXISTS app_data (
    id INT PRIMARY KEY,
    data LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Columna de versión para guardado optimista de verdad (a nivel de base de
// datos, no solo de comprobación previa en el cliente): cada guardado indica
// la versión que creía vigente y el UPDATE solo se aplica si esa versión
// sigue siendo la actual ("WHERE version = :esperada"). Si otro dispositivo
// guardó justo antes, la versión ya cambió, 0 filas se actualizan y
// devolvemos 409 en vez de sobrescribir su trabajo. Esto cierra el hueco que
// quedaba entre "comprobar" y "guardar" con dos peticiones HTTP separadas,
// que por sí solas no pueden ser atómicas. La tabla ya existía, así que se
// añade con ALTER; si la columna ya existe, el ALTER falla y se ignora.
try { $pdo->exec("ALTER TABLE app_data ADD COLUMN version INT NOT NULL DEFAULT 0"); } catch (Exception $e) { /* ya existe */ }

// Copias de seguridad periódicas (red de seguridad ante sobrescrituras
// accidentales, p.ej. por condiciones de carrera entre varios dispositivos).
// Como mucho una copia al día, y se conservan 14 días.
$pdo->exec("CREATE TABLE IF NOT EXISTS app_data_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$accion = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'history') {
    try {
        // Lista de copias de seguridad disponibles. NO seleccionamos la columna
        // `data` aquí (puede pesar varios MB por las fotos en base64): traerla
        // y decodificarla 60 veces en una sola petición puede agotar la memoria
        // de PHP en hosting compartido. Con el tamaño en bytes basta para
        // distinguir a simple vista una copia "vacía"/incompleta de una normal.
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
        // Guardamos primero una copia del estado actual (antes de restaurar) por
        // si la restauración fuese un error, sin importar el límite de 30 min.
        $actual = $pdo->query("SELECT data FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($actual) {
            $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
            $insHist->execute(['data' => $actual['data']]);
        }
        $upd = $pdo->prepare("INSERT INTO app_data (id, data, version) VALUES (1, :data, 1) ON DUPLICATE KEY UPDATE data = :data2, version = version + 1");
        $upd->execute(['data' => $row['data'], 'data2' => $row['data']]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
    }
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    // Versión que el cliente cree vigente (la última que leyó).
    $expectedVersion = isset($_SERVER['HTTP_X_EXPECTED_VERSION']) && $_SERVER['HTTP_X_EXPECTED_VERSION'] !== ''
        ? (int)$_SERVER['HTTP_X_EXPECTED_VERSION'] : null;

    $existe = $pdo->query("SELECT version FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    if ($existe && $expectedVersion === null) {
        // Ya hay datos guardados pero esta petición no manda versión: viene de
        // una pestaña con JS tan antiguo que ni siquiera sabe de este mecanismo
        // (anterior a este fix). Antes esto se sobrescribía a ciegas sin
        // comprobar nada, que es justo el agujero por el que se perdían datos.
        // Ahora se rechaza: ese dispositivo no sube nada hasta que actualice.
        http_response_code(426);
        echo json_encode(['error' => 'upgrade_required', 'version' => (int)$existe['version']]);
        exit;
    }

    if ($existe) {
        $upd = $pdo->prepare("UPDATE app_data SET data = :data, version = version + 1 WHERE id = 1 AND version = :expected");
        $upd->execute(['data' => $raw, 'expected' => $expectedVersion]);
        if ($upd->rowCount() === 0) {
            // Alguien guardó una versión más nueva justo antes que nosotros: no
            // sobrescribimos su trabajo, avisamos para que el cliente recargue.
            $actual = $pdo->query("SELECT version FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'version' => $actual ? (int)$actual['version'] : null]);
            exit;
        }
    } else {
        // Primera vez que se guarda algo (fila todavía no existe): no hay nada
        // que proteger, se crea sin más.
        $stmt = $pdo->prepare(
            "INSERT INTO app_data (id, data, version) VALUES (1, :data, 1)
             ON DUPLICATE KEY UPDATE data = :data2, version = version + 1"
        );
        $stmt->execute(['data' => $raw, 'data2' => $raw]);
    }

    // Copia de seguridad: como máximo una vez al día, para no acumular una
    // fila por cada guardado (que puede ser cada pocos segundos mientras
    // alguien está trabajando).
    try {
        $ultima = $pdo->query("SELECT created_at FROM app_data_history ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $debeCopiar = !$ultima || (strtotime($ultima['created_at']) < time() - 86400);
        if ($debeCopiar) {
            $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
            $insHist->execute(['data' => $raw]);
            $pdo->exec("DELETE FROM app_data_history WHERE created_at < (NOW() - INTERVAL 14 DAY)");
        }
    } catch (Exception $e) {
        // La copia de seguridad nunca debe impedir que el guardado principal funcione.
    }

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
