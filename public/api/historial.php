<?php
// Consulta y restauración de copias de seguridad de app_data_history.
// GET  ?listar=1   -> lista de copias (id, fecha, tamaño)
// GET  ?id=123     -> contenido completo de una copia concreta
// POST {"restaurar": 123} -> restaura app_data a partir de esa copia
//                            (y guarda antes una copia del estado actual,
//                            para poder deshacer la restauración si hace falta)

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT data, created_at FROM app_data_history WHERE id = :id");
        $stmt->execute(['id' => (int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'no_encontrado']);
            exit;
        }
        echo json_encode(['data' => json_decode($row['data']), 'created_at' => $row['created_at']]);
        exit;
    }

    // Listado: no incluye el contenido completo (puede ser muy grande), solo metadatos.
    $stmt = $pdo->query("SELECT id, created_at, LENGTH(data) AS tamano FROM app_data_history ORDER BY id DESC LIMIT 200");
    echo json_encode(['copias' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $idRestaurar = $input['restaurar'] ?? null;
    if (!$idRestaurar) {
        http_response_code(400);
        echo json_encode(['error' => 'falta_id_restaurar']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT data FROM app_data_history WHERE id = :id");
    $stmt->execute(['id' => (int)$idRestaurar]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'no_encontrado']);
        exit;
    }

    // Antes de restaurar, guardamos el estado actual como copia (sin importar
    // el límite de 30 min: una restauración es un momento crítico y conviene
    // poder deshacerla).
    $actual = $pdo->query("SELECT data FROM app_data WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($actual) {
        $pdo->prepare("INSERT INTO app_data_history (data) VALUES (:data)")->execute(['data' => $actual['data']]);
    }

    $upd = $pdo->prepare("INSERT INTO app_data (id, data) VALUES (1, :data) ON DUPLICATE KEY UPDATE data = :data2");
    $upd->execute(['data' => $row['data'], 'data2' => $row['data']]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
