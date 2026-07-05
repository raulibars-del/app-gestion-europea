<?php
// Endpoint PÚBLICO para que el cliente acepte un presupuesto mediante un enlace único.
// No requiere API key: la seguridad viene del token aleatorio incluido en la URL.
//
// GET  ?token=TOKEN  → devuelve datos del presupuesto (para mostrar al cliente)
// POST {token, nombre} → marca el presupuesto como Aceptado y notifica a la empresa

require __DIR__ . '/config.php';
require __DIR__ . '/mailer_smtp.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}

// Leer la sección config (contiene contabilidad, smtp, empresa, etc.)
function leerConfig($pdo) {
    $stmt = $pdo->prepare("SELECT data FROM app_sections WHERE section = 'config'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? json_decode($row['data'], true) : [];
}

function guardarConfig($pdo, $config) {
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        "INSERT INTO app_sections (section, data, version) VALUES ('config', :d, 1)
         ON DUPLICATE KEY UPDATE data = :d2, version = version + 1"
    );
    $stmt->execute(['d' => $json, 'd2' => $json]);
}

function buscarPresupuestoPorToken($presupuestos, $token) {
    foreach ($presupuestos as $i => $p) {
        if (($p['aceptacionToken'] ?? '') === $token) return [$i, $p];
    }
    return [null, null];
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if (strlen($token) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'token_invalido']);
        exit;
    }

    $config = leerConfig($pdo);
    $presupuestos = $config['contabilidad']['presupuestos'] ?? [];
    [, $prs] = buscarPresupuestoPorToken($presupuestos, $token);

    if (!$prs) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    $empresa = $config['empresa'] ?? [];

    echo json_encode([
        'numero'            => $prs['numero'] ?? '',
        'fecha'             => $prs['fecha'] ?? '',
        'validezHasta'      => $prs['validezHasta'] ?? null,
        'clienteRazonSocial'=> $prs['clienteRazonSocial'] ?? '',
        'lineas'            => $prs['lineas'] ?? [],
        'baseImponible'     => (float)($prs['baseImponible'] ?? 0),
        'cuotaIVA'          => (float)($prs['cuotaIVA'] ?? 0),
        'total'             => (float)($prs['total'] ?? 0),
        'tipoIVA'           => (int)($prs['tipoIVA'] ?? 21),
        'notas'             => $prs['notas'] ?? '',
        'formaPago'         => $prs['formaPago'] ?? '',
        'estado'            => $prs['estado'] ?? 'Pendiente',
        'yaAceptado'        => isset($prs['aceptadoPor']),
        'aceptadoPor'       => $prs['aceptadoPor'] ?? null,
        'aceptadoEn'        => $prs['aceptadoEn'] ?? null,
        'empresa' => [
            'razonSocial' => $empresa['razonSocial'] ?? 'Europea de Maquinaria PMM SL',
            'nif'         => $empresa['nif'] ?? '',
            'email'       => $empresa['email'] ?? '',
            'web'         => $empresa['web'] ?? '',
            'telefono'    => $empresa['telefono'] ?? '',
        ],
    ]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token  = trim($input['token'] ?? '');
    $nombre = trim($input['nombre'] ?? '');

    if (strlen($token) < 8 || strlen($nombre) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'datos_incompletos']);
        exit;
    }

    $config = leerConfig($pdo);
    $presupuestos = &$config['contabilidad']['presupuestos'];
    [$idx, $prs] = buscarPresupuestoPorToken($presupuestos, $token);

    if ($idx === null) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    if (isset($presupuestos[$idx]['aceptadoPor'])) {
        http_response_code(409);
        echo json_encode(['error' => 'ya_aceptado', 'por' => $presupuestos[$idx]['aceptadoPor']]);
        exit;
    }

    // Actualizar presupuesto
    $presupuestos[$idx]['estado']     = 'Aceptado';
    $presupuestos[$idx]['aceptadoPor']= $nombre;
    $presupuestos[$idx]['aceptadoEn'] = date('Y-m-d H:i:s');
    $presupuestos[$idx]['aceptadoIP'] = $_SERVER['REMOTE_ADDR'] ?? '';

    guardarConfig($pdo, $config);

    // Enviar notificación por email a la empresa
    $smtp   = $config['smtp'] ?? [];
    $empresa= $config['empresa'] ?? [];
    $numero = $prs['numero'] ?? '—';
    $total  = number_format((float)($prs['total'] ?? 0), 2, ',', '.') . ' €';
    $emailEmpresa = $empresa['email'] ?? ($smtp['from'] ?? '');

    if (!empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass']) && $emailEmpresa) {
        try {
            $html = "
              <div style='font-family:Arial,sans-serif;color:#1a1a1a;max-width:560px'>
                <div style='background:#16a34a;padding:18px 22px;border-radius:8px 8px 0 0'>
                  <p style='color:#fff;font-weight:700;font-size:18px;margin:0'>✅ Presupuesto aceptado</p>
                </div>
                <div style='border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:22px'>
                  <p>El presupuesto <strong>{$numero}</strong> ha sido <strong>aceptado por el cliente</strong>.</p>
                  <table style='width:100%;border-collapse:collapse;margin:14px 0'>
                    <tr><td style='color:#64748b;padding:6px 0;width:160px'>Aceptado por</td><td style='font-weight:700'>{$nombre}</td></tr>
                    <tr><td style='color:#64748b;padding:6px 0'>Importe</td><td style='font-weight:700;color:#16a34a'>{$total}</td></tr>
                    <tr><td style='color:#64748b;padding:6px 0'>Fecha y hora</td><td>".date('d/m/Y H:i')."</td></tr>
                    <tr><td style='color:#64748b;padding:6px 0'>IP del cliente</td><td style='font-family:monospace'>".($_SERVER['REMOTE_ADDR'] ?? '—')."</td></tr>
                  </table>
                  <p style='color:#64748b;font-size:13px'>Puedes acceder a la aplicación para convertirlo en factura y ponerte en marcha.</p>
                </div>
              </div>";
            smtp_send_mail(
                $smtp,
                $emailEmpresa,
                $empresa['razonSocial'] ?? 'Europea de Maquinaria',
                "✅ Presupuesto {$numero} aceptado por {$nombre}",
                $html
            );
        } catch (Exception $e) {
            // La aceptación ya está guardada; el email de notificación es secundario
        }
    }

    echo json_encode(['ok' => true, 'numero' => $numero]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
