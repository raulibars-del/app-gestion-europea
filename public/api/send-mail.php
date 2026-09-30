<?php
// Envía un email (con adjunto PDF opcional) usando la configuración SMTP
// guardada por el usuario en Ajustes (data.smtp), que vive en la misma fila
// compartida de la tabla app_data que usa data.php.

require __DIR__ . '/config.php';
require __DIR__ . '/mailer_smtp.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['to']) || empty($input['subject'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Leer SMTP de app_sections (donde React guarda los ajustes en tiempo real),
    // no de app_data (que solo se reconstruye cada 4 horas).
    $stmt = $pdo->query("SELECT data FROM app_sections WHERE section = 'config'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $config = $row ? json_decode($row['data'], true) : null;
    $smtp = $config['smtp'] ?? null;

    if (!$smtp || empty($smtp['host']) || empty($smtp['user']) || empty($smtp['pass'])) {
        http_response_code(400);
        echo json_encode(['error' => 'smtp_not_configured']);
        exit;
    }

    $attachment = null;
    if (!empty($input['attachmentBase64'])) {
        $attachment = [
            'base64' => $input['attachmentBase64'],
            'name'   => $input['attachmentName'] ?? 'documento.pdf',
            'mime'   => $input['attachmentMime'] ?? 'application/pdf',
        ];
    }

    smtp_send_mail(
        $smtp,
        $input['to'],
        $input['toName'] ?? '',
        $input['subject'],
        $input['html'] ?? '',
        $attachment,
        $input['cc'] ?? null,
        $input['replyTo'] ?? null,
        $input['fromName'] ?? null
    );

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'send_failed', 'detail' => $e->getMessage()]);
}
