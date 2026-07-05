<?php
// ─── Cron: envío programado de partes de trabajo ─────────────────────────────
// Este script debe ejecutarse cada minuto desde el panel de tareas de IONOS.
//
// Configuración en IONOS → Administrar hosting → Tareas programadas (cron):
//   Comando:   /usr/bin/curl -s "https://gestion.europeademaquinaria.com/api/cron-partes.php" -H "X-Api-Key: TU_API_KEY"
//   Intervalo: cada 1 minuto (o cada 5 si el plan no permite 1)
//
// Autenticación: la misma X-Api-Key que usa el resto de la app.
// El script NO hace output visible salvo el JSON de respuesta.

require __DIR__ . '/config.php';
require __DIR__ . '/mailer_smtp.php';

@ini_set('memory_limit', '256M');
@set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');

// ─── Auth ────────────────────────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (!defined('API_KEY') || !hash_equals(API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// ─── DB ──────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection_failed', 'detail' => $e->getMessage()]);
    exit;
}

// ─── Hora actual en España ───────────────────────────────────────────────────
try {
    $nowSpain   = new DateTime('now', new DateTimeZone('Europe/Madrid'));
} catch (Exception $e) {
    $nowSpain   = new DateTime('now');
}
$hoyEspana   = $nowSpain->format('Y-m-d'); // "2026-07-04"
$ahoraEspana = $nowSpain->format('H:i');   // "09:30"

// ─── Leer sección partes ─────────────────────────────────────────────────────
$stmtP = $pdo->query("SELECT data, version FROM app_sections WHERE section = 'partes'");
$rowP  = $stmtP->fetch(PDO::FETCH_ASSOC);
if (!$rowP) {
    echo json_encode(['ok' => true, 'msg' => 'no partes section', 'ts' => "$hoyEspana $ahoraEspana"]);
    exit;
}
$partes  = json_decode($rowP['data'], true) ?? [];
$version = (int)$rowP['version'];

// ─── Leer SMTP desde sección config ─────────────────────────────────────────
$stmtC  = $pdo->query("SELECT data FROM app_sections WHERE section = 'config'");
$rowC   = $stmtC->fetch(PDO::FETCH_ASSOC);
$config = json_decode($rowC['data'] ?? '{}', true) ?? [];
$smtp   = $config['smtp'] ?? null;

// ─── Leer avisos (para cerrarlos cuando se envíe el email) ───────────────────
$stmtA   = $pdo->query("SELECT data, version FROM app_sections WHERE section = 'avisos'");
$rowA    = $stmtA->fetch(PDO::FETCH_ASSOC);
$avisos  = $rowA ? (json_decode($rowA['data'], true) ?? []) : [];
$versionAvisos = $rowA ? (int)$rowA['version'] : 0;
$avisosChanged = false;

if (!$smtp || empty($smtp['host']) || empty($smtp['user']) || empty($smtp['pass'])) {
    echo json_encode(['ok' => false, 'msg' => 'smtp_not_configured', 'ts' => "$hoyEspana $ahoraEspana"]);
    exit;
}

// ─── Buscar partes programados cuya hora haya llegado ───────────────────────
$pendingIds = [];
foreach ($partes as $p) {
    if (empty($p['envioProgFecha'])) continue;
    if (!empty($p['emailEnviado']))  continue;

    $fecha = $p['envioProgFecha'];
    $hora  = $p['envioProgHora'] ?? '00:00';

    if ($fecha > $hoyEspana) continue;
    if ($fecha === $hoyEspana && $hora > $ahoraEspana) continue;

    // Solo procesar si tiene el PDF pregenerado (nuevo flujo)
    if (empty($p['envioProgPDFBase64'])) continue;

    $pendingIds[] = $p['id'];
}

if (empty($pendingIds)) {
    echo json_encode(['ok' => true, 'processed' => 0, 'ts' => "$hoyEspana $ahoraEspana"]);
    exit;
}

// ─── Índice rápido id→posición ───────────────────────────────────────────────
$idxById = [];
foreach ($partes as $i => $p) {
    $idxById[$p['id']] = $i;
}

// ─── Procesar cada parte pendiente ───────────────────────────────────────────
$processed = 0;
$errors    = [];
$changed   = false;

foreach ($pendingIds as $pid) {
    if (!isset($idxById[$pid])) continue;
    $p = $partes[$idxById[$pid]];

    $to        = $p['envioProgEmail']  ?? '';
    $pdfB64    = $p['envioProgPDFBase64'] ?? '';
    $emailHtml = $p['envioProgEmailHtml'] ?? '';
    $numero    = $p['numeroParte'] ?? ('PT-' . substr((string)$p['id'], -6));
    $ccUsada   = $smtp['ccPartes'] ?? 'gestion@europeademaquinaria.com';
    $cadenaIds = $p['envioProgCadenaIds'] ?? [$p['id']];

    if (!$to || !$pdfB64) {
        $errors[] = "parte $pid: falta email o PDF";
        continue;
    }

    // HTML de fallback si no se guardó al programar
    if (!$emailHtml) {
        $numVis = count($cadenaIds);
        $cadenaText = $numVis > 1 ? " (incluye las $numVis visitas realizadas)" : "";
        $firma = "<table cellpadding=\"0\" cellspacing=\"0\" style=\"margin-top:10px;background-color:#0f9b6e;border-radius:10px;width:100%;\"><tr><td style=\"padding:18px 20px;\"><table cellpadding=\"0\" cellspacing=\"0\"><tr><td style=\"color:#ffffff;font-family:Arial,Helvetica,sans-serif;vertical-align:middle;\"><div style=\"font-weight:700;font-size:14px;margin-bottom:3px;\">Europea de Maquinaria, PMM, S.L.</div><div style=\"font-size:12.5px;line-height:1.5;\">C/ Mas del Jutge, 33 — 46900</div><div style=\"font-size:12.5px;line-height:1.5;margin-bottom:5px;\">CIF B98527583 · Tel. +34 96 155 07 07</div><div style=\"font-size:12.5px;line-height:1.5;\">Información general: info@europeademaquinaria.com</div><div style=\"font-size:12.5px;line-height:1.5;\">Servicio y asistencia: servicio@europeademaquinaria.com</div></td></tr></table></td></tr></table>";
        $emailHtml = "<div style=\"font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;\"><p>Buenas,</p><p>Adjuntamos documento relativo a la gestión de trabajo realizada, parte de trabajo <strong>$numero</strong>$cadenaText.</p><p>Esta cuenta es solo para el envío de documentación y no es una vía de contacto válida; para cualquier consulta, escríbanos usando los datos de abajo.</p>$firma</div>";
    }

    $attachment = [
        'base64' => $pdfB64,
        'name'   => 'parte-' . $numero . '.pdf',
        'mime'   => 'application/pdf',
    ];

    try {
        smtp_send_mail(
            $smtp,
            $to,
            '',
            'Parte de trabajo ' . $numero . ' — Europea de Maquinaria',
            $emailHtml,
            $attachment,
            $ccUsada
        );

        // Marcar todos los partes de la cadena como enviados y limpiar campos prog
        $idsAfectados = is_array($cadenaIds) ? $cadenaIds : [$p['id']];
        foreach ($idsAfectados as $afId) {
            if (!isset($idxById[$afId])) continue;
            $j = $idxById[$afId];
            $partes[$j]['firmaNombre']       = $p['envioProgFirmaNombre'] ?? '';
            $partes[$j]['firmaImagen']       = $p['envioProgFirmaImagen'] ?? null;
            $partes[$j]['conforme']          = $p['envioProgConforme']    ?? true;
            $partes[$j]['notasConformidad']  = $p['envioProgNotas']       ?? '';
            $partes[$j]['fechaFirma']        = $hoyEspana;
            $partes[$j]['emailEnviado']      = true;
            $partes[$j]['emailEnviadoA']     = $to;
            $partes[$j]['emailEnviadoCC']    = $ccUsada;
            $partes[$j]['fechaEnvio']        = $hoyEspana;
            // Limpiar todos los campos de programación
            foreach (['envioProgFecha','envioProgHora','envioProgEmail','envioProgFirmaNombre',
                      'envioProgFirmaImagen','envioProgConforme','envioProgNotas',
                      'envioProgPDFBase64','envioProgEmailHtml','envioProgCadenaIds'] as $campo) {
                $partes[$j][$campo] = null;
            }
        }

        // Cerrar el aviso vinculado ahora que el email ha llegado al cliente
        if (!empty($p['avisoId'])) {
            foreach ($avisos as &$av) {
                if ($av['id'] == $p['avisoId']) {
                    $av['estado']                  = 'Resuelto';
                    $av['fechaResuelto']           = $hoyEspana;
                    $av['fechaUltimaIntervencion'] = $hoyEspana;
                    $avisosChanged = true;
                    break;
                }
            }
            unset($av);
        }

        $processed++;
        $changed = true;

    } catch (Exception $e) {
        $errors[] = "parte $pid: " . $e->getMessage();
        error_log("cron-partes.php ERROR parte $pid: " . $e->getMessage());
    }
}

// ─── Guardar sección partes actualizada (con version locking) ────────────────
if ($changed) {
    $newVersion = $version + 1;
    $stmt = $pdo->prepare(
        "UPDATE app_sections SET data = :data, version = :v
         WHERE section = 'partes' AND version = :oldv"
    );
    $ok = $stmt->execute([
        'data' => json_encode($partes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'v'    => $newVersion,
        'oldv' => $version,
    ]);
    if (!$ok || $stmt->rowCount() === 0) {
        // Conflicto de versión: otro proceso guardó mientras tanto.
        // No es crítico: la próxima ejecución del cron detectará los mismos partes
        // aún con envioProgFecha y lo intentará de nuevo. El emailEnviado=true
        // que debería estar puesto no se ha guardado, así que hay riesgo de doble envío.
        // Para evitarlo, reintentamos sin version locking (último escritor gana).
        $stmt2 = $pdo->prepare(
            "UPDATE app_sections SET data = :data, version = :v WHERE section = 'partes'"
        );
        $stmt2->execute([
            'data' => json_encode($partes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'v'    => $newVersion,
        ]);
        $errors[] = 'version_conflict_forzado';
    }
}

// ─── Guardar sección avisos actualizada ─────────────────────────────────────
if ($avisosChanged && $versionAvisos > 0) {
    $newVersionAvisos = $versionAvisos + 1;
    $stmtAv = $pdo->prepare(
        "UPDATE app_sections SET data = :data, version = :v
         WHERE section = 'avisos' AND version = :oldv"
    );
    $okAv = $stmtAv->execute([
        'data' => json_encode($avisos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'v'    => $newVersionAvisos,
        'oldv' => $versionAvisos,
    ]);
    if (!$okAv || $stmtAv->rowCount() === 0) {
        // Conflicto de versión: guardar sin locking
        $stmtAv2 = $pdo->prepare(
            "UPDATE app_sections SET data = :data, version = :v WHERE section = 'avisos'"
        );
        $stmtAv2->execute([
            'data' => json_encode($avisos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'v'    => $newVersionAvisos,
        ]);
        $errors[] = 'avisos_version_conflict_forzado';
    }
}

echo json_encode([
    'ok'        => true,
    'processed' => $processed,
    'ts'        => "$hoyEspana $ahoraEspana",
    'errors'    => $errors,
]);
