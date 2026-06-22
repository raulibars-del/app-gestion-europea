<?php
// API mínima para compartir los datos de la app entre todos los usuarios.
// GET  -> devuelve el JSON guardado
// POST -> guarda el JSON recibido (sobrescribe el anterior)
//
// Autenticación simple por API key (cabecera X-Api-Key). No es seguridad
// de nivel empresarial, pero evita que cualquiera en internet pueda leer
// o modificar los datos sin conocer la clave.

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// CORS básico (la app se sirve desde el mismo dominio, pero por si acaso)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

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

// Copias de seguridad periódicas (red de seguridad ante sobrescrituras
// accidentales, p.ej. por condiciones de carrera entre varios dispositivos).
// Como mucho una copia cada 30 minutos, y se conservan 14 días.
$pdo->exec("CREATE TABLE IF NOT EXISTS app_data_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT data, updated_at FROM app_data WHERE id = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo json_encode([
            'data' => json_decode($row['data']),
            'updated_at' => $row['updated_at'],
        ]);
    } else {
        echo json_encode(['data' => null, 'updated_at' => null]);
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

    $stmt = $pdo->prepare(
        "INSERT INTO app_data (id, data) VALUES (1, :data)
         ON DUPLICATE KEY UPDATE data = :data2"
    );
    $stmt->execute(['data' => $raw, 'data2' => $raw]);

    // Copia de seguridad: solo si la última copia tiene más de 30 minutos,
    // para no acumular una fila por cada guardado (que puede ser cada pocos
    // segundos mientras alguien está trabajando).
    try {
        $ultima = $pdo->query("SELECT created_at FROM app_data_history ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $debeCopiar = !$ultima || (strtotime($ultima['created_at']) < time() - 1800);
        if ($debeCopiar) {
            $insHist = $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)");
            $insHist->execute(['data' => $raw]);
            $pdo->exec("DELETE FROM app_data_history WHERE created_at < (NOW() - INTERVAL 14 DAY)");
        }
    } catch (Exception $e) {
        // La copia de seguridad nunca debe impedir que el guardado principal funcione.
    }

    $stmt2 = $pdo->query("SELECT updated_at FROM app_data WHERE id = 1");
    $updated = $stmt2->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'updated_at' => $updated['updated_at'] ?? null]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
