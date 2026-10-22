<?php
// Script de importación de partes perdidos - BORRAR DESPUÉS DE USAR
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Leer clientes y partes actuales
$clientes = json_decode($pdo->query("SELECT data FROM app_sections WHERE section='clientes'")->fetchColumn(), true) ?? [];
$partes   = json_decode($pdo->query("SELECT data FROM app_sections WHERE section='partes'")->fetchColumn(), true) ?? [];
$partesV  = (int)$pdo->query("SELECT version FROM app_sections WHERE section='partes'")->fetchColumn();

// Helper: buscar cliente por nombre
function clienteId($clientes, ...$nombres) {
    foreach ($nombres as $nombre) {
        $n = strtolower(trim($nombre));
        foreach ($clientes as $c) {
            if (strtolower($c['nombreEmpresa'] ?? '') === $n || strtolower($c['nombreFiscal'] ?? '') === $n) return $c['id'];
        }
    }
    return null;
}

// Helper: buscar máquina de un cliente por matrícula/modelo
function maquinaId($clientes, $clienteId, $matricula, $modelo='') {
    foreach ($clientes as $c) {
        if ($c['id'] != $clienteId) continue;
        foreach ($c['maquinas'] ?? [] as $m) {
            if ($matricula && strtolower($m['serie'] ?? '') === strtolower($matricula)) return $m['id'];
            if ($modelo && strtolower($m['modelo'] ?? '') === strtolower($modelo)) return $m['id'];
        }
    }
    return null;
}

$maxId = empty($partes) ? 9000 : max(array_column($partes, 'id'));

$nuevos = [
    // P211026-JA01 - Jose Antonio Garcia - Puertas Ginesta - Busellato Jet 100
    [
        'numeroParte'    => 'P211026-JA01',
        'fecha'          => '2026-10-21',
        'tecnicos'       => ['Jose Antonio Garcia'],
        'horasT'         => 2,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => 'Ver problema de no arranque de la máquina le fallan los contactos de las cámaras de los contactores y problema en el final de carrera se le limpia y funciona todo correctamente',
        'materiales'     => '',
        'materialesList' => [],
        'marca'          => 'Busellato',
        'modelo'         => 'Jet 100',
        'matricula'      => '6138',
        'clienteNames'   => ['Puertas Ginesta'],
        'avisoId'        => null,
        'contactoNombre' => 'David',
        'contactoTel'    => '',
        'contactoEmail'  => '',
    ],
    // P211026-JR01 - Juanjo Romero y Luka Cañada - Madentia SL - Casolin Astra Digit
    [
        'numeroParte'    => 'P211026-JR01',
        'fecha'          => '2026-10-21',
        'tecnicos'       => ['Juanjo Romero', 'Luka Cañada'],
        'horasT'         => 2,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => "Ver el problema viendo que el disco esta inclinado a 45 grados y lo ponemos a 90 grados y funciona correctamente.\nSe comprueba el resto de movimientos (altura del disco) y también funciona.\nArrancamos motor sierra y no funciona. El guardamotor no funciona fallando una fase.\nSustituir guardamotor y comprobar funcionamiento.\nSe repasan de nuevo todos los automatismos de la máquina. Todo ok.",
        'materiales'     => 'Guardamotor pkzm0-32. / 26-32 A - 1 ud',
        'materialesList' => [['material'=>'Guardamotor pkzm0-32. / 26-32 A','cantidad'=>'1']],
        'marca'          => 'Casolin',
        'modelo'         => 'Astra Digit',
        'matricula'      => '9651999',
        'clienteNames'   => ['Madentia', 'Madentia SL'],
        'avisoId'        => null,
        'contactoNombre' => 'Pablo Caro',
        'contactoTel'    => '600 867 820',
        'contactoEmail'  => '',
    ],
    // P211026-LC01 - Luka Cañada y Juanjo Romero - Sandro Carpintero - Eberle Manipulador
    [
        'numeroParte'    => 'P211026-LC01',
        'fecha'          => '2026-10-21',
        'tecnicos'       => ['Luka Cañada', 'Juanjo Romero'],
        'horasT'         => 4,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => "Se realizó el montaje del manipulador, se comprobó el funcionamiento y se entró la documentación.\nLos anclajes al suelo los realizó el cliente.",
        'materiales'     => '',
        'materialesList' => [],
        'marca'          => 'Eberle',
        'modelo'         => 'Manipulador ingravidos',
        'matricula'      => '7405',
        'clienteNames'   => ['Sandro Carpintero', 'Sandro Carpintero SL'],
        'avisoId'        => null, // AV191026-02 - buscar si existe
        'contactoNombre' => 'Sandro',
        'contactoTel'    => '633690041',
        'contactoEmail'  => '',
    ],
    // P221026-JA01 - Jose Antonio Garcia - Puertas Los Melgues
    [
        'numeroParte'    => 'P221026-JA01',
        'fecha'          => '2026-10-22',
        'tecnicos'       => ['Jose Antonio Garcia'],
        'horasT'         => 6,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => 'Montar bomba de vacío en la máquina y ajustes varios',
        'materiales'     => 'Bomba depresión - 1 ud; Guardamotor 16 amp - 1 ud',
        'materialesList' => [['material'=>'Bomba depresión','cantidad'=>'1'],['material'=>'Guardamotor 16 amp','cantidad'=>'1']],
        'marca'          => 'MASTERWOOD',
        'modelo'         => 'PROJECT 485L',
        'matricula'      => 'AAAU0105',
        'clienteNames'   => ['Puertas Los Melgues', 'Puertas Los Melgues S.L.'],
        'avisoId'        => null,
        'contactoNombre' => 'Ana',
        'contactoTel'    => '950347422',
        'contactoEmail'  => '',
    ],
    // P221026-JR01 - Juanjo Romero - JMT Carpintería
    [
        'numeroParte'    => 'P221026-JR01',
        'fecha'          => '2026-10-22',
        'tecnicos'       => ['Juanjo Romero'],
        'horasT'         => 1,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => "Ver funcionamiento. El taladro horizontal inferior sale lento y raja fuera de posición. Comprobar circuito, ver guías y todo está correcto. Dar un poco más de presión neumática al regulador de taladros. Comprobando que el movimiento es más rápido y correcto. Poner tablero y hacer agujeros. Todo correcto.",
        'materiales'     => '',
        'materialesList' => [],
        'marca'          => 'Vertimac',
        'modelo'         => 'X bore 2',
        'matricula'      => 'XB2.2.132.-23',
        'clienteNames'   => ['Javier Martinez', 'JMT CARPINTERIA Y DISEÑO S.L', 'JMT Carpintería'],
        'avisoId'        => null, // AV221026-01
        'contactoNombre' => 'Javier Martinez',
        'contactoTel'    => '+34 667 567 458',
        'contactoEmail'  => '',
    ],
    // P221026-JR02 - Juanjo Romero y Luka Cañada - Salvador Zarzo
    [
        'numeroParte'    => 'P221026-JR02',
        'fecha'          => '2026-10-22',
        'tecnicos'       => ['Juanjo Romero', 'Luka Cañada'],
        'horasT'         => 1.5,
        'desplazamiento' => 'si',
        'km'             => 0,
        'descripcion'    => "Ver problema, salta guardamotor. Comprobar contactor cable y guardamotor. Se desmonta el motor y al ver la tapa con orejas de sujeción rotas se abre. Motor quemado. Se lleva al taller.",
        'materiales'     => '',
        'materialesList' => [],
        'marca'          => 'Scm',
        'modelo'         => 'Record 132',
        'matricula'      => 'AA1/013916',
        'clienteNames'   => ['SALVADOR ZARZO', 'Salvador Zarzo', 'SALVADOR ZARZO DOMENECH, S.L.'],
        'avisoId'        => null,
        'contactoNombre' => 'MARTA',
        'contactoTel'    => '962730355',
        'contactoEmail'  => '',
    ],
];

// Verificar que no existan ya (por numeroParte)
$numerosExistentes = array_column($partes, 'numeroParte');
$insertados = [];
$saltados   = [];

foreach ($nuevos as $n) {
    if (in_array($n['numeroParte'], $numerosExistentes)) {
        $saltados[] = $n['numeroParte'];
        continue;
    }
    $cId = clienteId($clientes, ...$n['clienteNames']);
    $mId = $cId ? maquinaId($clientes, $cId, $n['matricula'], $n['modelo']) : null;
    $maxId++;
    $parte = [
        'id'              => $maxId,
        'numeroParte'     => $n['numeroParte'],
        'cadenaBase'      => $n['numeroParte'],
        'numContinuacion' => 0,
        'continuaDeId'    => null,
        'fecha'           => $n['fecha'],
        'tecnicos'        => $n['tecnicos'],
        'horasT'          => $n['horasT'],
        'desplazamiento'  => $n['desplazamiento'],
        'km'              => $n['km'],
        'descripcion'     => $n['descripcion'],
        'materiales'      => $n['materiales'],
        'materialesList'  => $n['materialesList'],
        'marca'           => $n['marca'],
        'modelo'          => $n['modelo'],
        'matricula'       => $n['matricula'],
        'clienteDirectoId'=> $cId,
        'maquinaId'       => $mId,
        'avisoId'         => $n['avisoId'],
        'reparacionId'    => null,
        'contactoNombre'  => $n['contactoNombre'],
        'contactoTel'     => $n['contactoTel'],
        'contactoEmail'   => $n['contactoEmail'],
        'estadoParte'     => 'Finalizado',
        'bloqueado'       => true,
        'enviadoPorEmail' => true,
        '_ts'             => time() * 1000,
    ];
    $partes[] = $parte;
    $insertados[] = ['numeroParte'=>$n['numeroParte'], 'clienteId'=>$cId, 'maquinaId'=>$mId];
}

if (!empty($insertados)) {
    $newJson = json_encode($partes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE app_sections SET data=:d, version=version+1 WHERE section='partes'")->execute(['d'=>$newJson]);
}

echo json_encode(['ok'=>true, 'insertados'=>$insertados, 'saltados'=>$saltados, 'totalPartes'=>count($partes)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
