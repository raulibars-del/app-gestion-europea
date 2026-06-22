<?php
// Sube un archivo (imagen o documento) recibido en base64 y devuelve su URL
// pública. Los archivos se guardan en public/api/uploads/, carpeta que el
// despliegue (mirror --delete) excluye explícitamente para no borrarlos en
// cada deploy.

require __DIR__ . '/config.php';

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
if (!$input || empty($input['base64']) || empty($input['filename'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

$mime = $input['mime'] ?? 'application/octet-stream';
$permitidos = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/gif' => 'gif', 'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt',
    'image/svg+xml' => 'svg',
];
// Extensiones permitidas por sufijo del nombre original, para archivos cuyo
// MIME el navegador no informa de forma fiable (p.ej. .dwg).
$extsPorNombre = ['dwg' => 'dwg', 'dxf' => 'dxf'];

$ext = $permitidos[$mime] ?? null;
if ($ext === null) {
    $nombreOriginal = strtolower($input['filename']);
    $puntoPos = strrpos($nombreOriginal, '.');
    $extNombre = $puntoPos !== false ? substr($nombreOriginal, $puntoPos + 1) : '';
    $ext = $extsPorNombre[$extNombre] ?? null;
}
if ($ext === null) {
    http_response_code(400);
    echo json_encode(['error' => 'tipo_no_permitido']);
    exit;
}

$data = base64_decode($input['base64'], true);
if ($data === false) {
    http_response_code(400);
    echo json_encode(['error' => 'base64_invalido']);
    exit;
}

$maxBytes = 25 * 1024 * 1024; // 25 MB
if (strlen($data) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'archivo_demasiado_grande']);
    exit;
}

$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
// El deploy excluye esta carpeta del mirror, así que el .htaccess de
// protección no llega vía git: nos aseguramos de que exista en runtime.
$htaccess = $dir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|php\\d)$\">\nRequire all denied\n</FilesMatch>\n");
}

$nombre = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
$ruta = $dir . '/' . $nombre;

if (file_put_contents($ruta, $data) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'no_se_pudo_guardar']);
    exit;
}

echo json_encode([
    'ok' => true,
    'url' => '/api/uploads/' . $nombre,
    'nombre' => basename($input['filename']),
    'mime' => $mime,
]);
