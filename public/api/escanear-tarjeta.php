<?php
// Lee una foto de tarjeta de visita usando la API de Gemini (Google) y
// devuelve los campos ya extraídos en JSON. Sustituye al OCR local
// (Tesseract.js + heurísticas), que fallaba con demasiada frecuencia: un
// modelo de visión "entiende" la tarjeta en vez de solo reconocer caracteres
// sueltos, así que acierta muchas más veces con prácticamente cualquier
// diseño de tarjeta.
//
// La llamada a Gemini se hace siempre desde aquí (servidor) y nunca desde el
// navegador, para no exponer la clave de la API en el código del cliente.

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

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'gemini_not_configured']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$imagenBase64 = $input['imagen'] ?? '';
if (!$imagenBase64) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_image']);
    exit;
}
// El frontend manda la foto como data URL ("data:image/jpeg;base64,...");
// Gemini solo quiere la parte en base64.
if (preg_match('/^data:image\/\w+;base64,/', $imagenBase64)) {
    $imagenBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $imagenBase64);
}

$prompt = <<<'EOT'
Eres un asistente que extrae datos de fotos de tarjetas de visita de empresas
en España. Analiza la imagen y devuelve SOLO los datos que aparezcan
literalmente escritos en la tarjeta, sin inventar ni completar nada que no
esté impreso. Si un dato no aparece en la tarjeta, devuelve ese campo como
cadena vacía "".

Campos a devolver:
- nombreEmpresa: nombre comercial de la empresa (el más destacado, normalmente
  el del logo)
- nombreFiscal: SOLO si en la tarjeta aparece literalmente un sufijo
  societario como "S.L.", "S.A.", "S.L.U.", "S.A.U." o "Sociedad Cooperativa";
  si ese sufijo no aparece en ningún sitio de la tarjeta, deja "" (no lo
  deduzcas ni lo añadas tú)
- contactoNombre: nombre y apellidos de la persona de contacto
- puesto: cargo de esa persona (ej. "Director Comercial", "Gerente"...)
- dirFiscal: calle, número, polígono o dirección tal como aparece escrita
- cpFiscal: código postal (5 dígitos)
- localidad: ciudad o localidad
- provinciaFiscal: provincia, solo si aparece explícitamente en la tarjeta
- tel: el teléfono principal (si hay varios, el primero o el de móvil)
- telsExtra: lista con cualquier otro teléfono adicional que aparezca en la
  tarjeta (puede ir vacía)
- email: correo electrónico
- web: página web (sin "http://" ni "https://")

Devuelve únicamente el JSON con esos campos, sin texto adicional.
EOT;

$body = [
    'contents' => [[
        'parts' => [
            ['text' => $prompt],
            ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imagenBase64]],
        ],
    ]],
    'generationConfig' => [
        'response_mime_type' => 'application/json',
        'response_schema' => [
            'type' => 'OBJECT',
            'properties' => [
                'nombreEmpresa'   => ['type' => 'STRING'],
                'nombreFiscal'    => ['type' => 'STRING'],
                'contactoNombre'  => ['type' => 'STRING'],
                'puesto'          => ['type' => 'STRING'],
                'dirFiscal'       => ['type' => 'STRING'],
                'cpFiscal'        => ['type' => 'STRING'],
                'localidad'       => ['type' => 'STRING'],
                'provinciaFiscal' => ['type' => 'STRING'],
                'tel'             => ['type' => 'STRING'],
                'telsExtra'       => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'email'           => ['type' => 'STRING'],
                'web'             => ['type' => 'STRING'],
            ],
        ],
    ],
];

$modelo = 'gemini-3.1-flash-lite';
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent?key=' . GEMINI_API_KEY;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_TIMEOUT => 30,
]);
$respuesta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'gemini_request_failed', 'detail' => $curlError]);
    exit;
}

$json = json_decode($respuesta, true);

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'gemini_error', 'detail' => $json['error']['message'] ?? $respuesta]);
    exit;
}

$textoJson = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
if (!$textoJson) {
    http_response_code(502);
    echo json_encode(['error' => 'gemini_empty_response', 'detail' => $respuesta]);
    exit;
}

$campos = json_decode($textoJson, true);
if (!is_array($campos)) {
    http_response_code(502);
    echo json_encode(['error' => 'gemini_invalid_json', 'detail' => $textoJson]);
    exit;
}

echo json_encode(['ok' => true, 'campos' => $campos, 'textoCrudo' => $textoJson]);
