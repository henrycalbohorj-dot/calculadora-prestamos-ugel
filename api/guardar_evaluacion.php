<?php
/**
 * Guarda una evaluación completa (cabecera + descuentos + créditos)
 * en una sola transacción. Recibe JSON por POST.
 *
 * Además:
 *  - Crea o actualiza el trabajador (por DNI) para poder reutilizarlo
 *    luego desde el buscador de la calculadora.
 *  - "Aprende" los conceptos de descuento y entidades financieras que
 *    no estaban en el catálogo (los agrega automáticamente) y sube el
 *    contador de uso de los que ya existían, para que los accesos
 *    rápidos siempre muestren primero los más usados.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida. Vuelve a iniciar sesión.']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);

if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

$nombre        = trim((string)($datos['trabajador_nombre'] ?? ''));
$dni           = trim((string)($datos['trabajador_dni'] ?? ''));
$dependencia   = trim((string)($datos['trabajador_dependencia'] ?? ''));
$tipoPersonal  = in_array($datos['tipo_personal'] ?? '', ['administrativo', 'docente'], true)
    ? $datos['tipo_personal']
    : 'administrativo';
// Sueldo bruto base + remuneración adicional opcional (ej. bonificaciones).
// Se acepta también "sueldo_bruto" suelto por compatibilidad hacia atrás.
$sueldoBrutoBase          = (float)($datos['sueldo_bruto_base'] ?? $datos['sueldo_bruto'] ?? 0);
$sueldoAdicionalConcepto  = trim((string)($datos['sueldo_adicional_concepto'] ?? ''));
$sueldoAdicionalMonto     = (float)($datos['sueldo_adicional_monto'] ?? 0);
if ($sueldoAdicionalMonto < 0) {
    $sueldoAdicionalMonto = 0;
}
$sueldoBruto   = $sueldoBrutoBase + $sueldoAdicionalMonto;
$observaciones = trim((string)($datos['observaciones'] ?? ''));
$descuentos    = is_array($datos['descuentos'] ?? null) ? $datos['descuentos'] : [];
$entidades     = is_array($datos['entidades'] ?? null) ? $datos['entidades'] : [];

if ($nombre === '' || $sueldoBrutoBase <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Falta el nombre del trabajador o el sueldo bruto.']);
    exit;
}

// --- Recalcular en el servidor (nunca confiar solo en el cliente) ---
$totalDescuentos = 0.0;
$descuentosLimpios = [];
foreach ($descuentos as $d) {
    $n = trim((string)($d['nombre'] ?? ''));
    $m = (float)($d['monto'] ?? 0);
    if ($n === '' && $m == 0) continue;
    $descuentosLimpios[] = ['nombre' => $n !== '' ? $n : '(sin nombre)', 'monto' => $m];
    $totalDescuentos += $m;
}

$netoTotal = max($sueldoBruto - $totalDescuentos, 0);
$neto50    = $netoTotal * 0.5;

$totalSolicitado = 0.0;
$entidadesLimpias = [];
foreach ($entidades as $e) {
    $n = trim((string)($e['nombre'] ?? ''));
    $m = (float)($e['monto'] ?? 0);
    if ($n === '' && $m == 0) continue;
    $entidadesLimpias[] = ['nombre' => $n !== '' ? $n : '(sin nombre)', 'tipo' => $e['tipo'] ?? 'otro', 'monto' => $m];
    $totalSolicitado += $m;
}

$capacidadDisponible = $neto50 - $totalSolicitado;
$excedeLimite = $totalSolicitado > ($neto50 + 0.001);

$pdo = obtenerConexion();

try {
    $pdo->beginTransaction();

    // -----------------------------------------------------------------
    // 1. Crear o actualizar el trabajador (solo si tiene DNI, que es la
    //    clave que permite reconocerlo la próxima vez).
    // -----------------------------------------------------------------
    $trabajadorId = null;
    if ($dni !== '') {
        $stmtUpsertTrab = $pdo->prepare(
            'INSERT INTO trabajadores (nombres, dni, dependencia, tipo_personal, sueldo_bruto_referencia, actualizado_en)
             VALUES (:nombres, :dni, :dependencia, :tipo_personal, :sueldo_bruto, NOW())
             ON CONFLICT (dni) DO UPDATE SET
                nombres = EXCLUDED.nombres,
                dependencia = COALESCE(EXCLUDED.dependencia, trabajadores.dependencia),
                tipo_personal = EXCLUDED.tipo_personal,
                sueldo_bruto_referencia = EXCLUDED.sueldo_bruto_referencia,
                actualizado_en = NOW()
             RETURNING id'
        );
        $stmtUpsertTrab->execute([
            'nombres'      => $nombre,
            'dni'          => $dni,
            'dependencia'  => $dependencia !== '' ? $dependencia : null,
            'tipo_personal'=> $tipoPersonal,
            // Se guarda el sueldo BASE (sin la remuneración adicional), ya
            // que esta última es opcional y variable mes a mes.
            'sueldo_bruto' => $sueldoBrutoBase,
        ]);
        $trabajadorId = (int)$stmtUpsertTrab->fetchColumn();
    }

    // -----------------------------------------------------------------
    // 2. Insertar la evaluación (cabecera)
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare(
        'INSERT INTO evaluaciones
            (usuario_id, trabajador_id, trabajador_nombre, trabajador_dni, trabajador_dependencia,
             tipo_personal, sueldo_bruto, sueldo_bruto_base, sueldo_adicional_concepto, sueldo_adicional_monto,
             total_descuentos, neto_total, neto_50,
             total_solicitado, capacidad_disponible, excede_limite, observaciones)
         VALUES
            (:usuario_id, :trabajador_id, :nombre, :dni, :dependencia,
             :tipo_personal, :sueldo_bruto, :sueldo_bruto_base, :sueldo_adicional_concepto, :sueldo_adicional_monto,
             :total_descuentos, :neto_total, :neto_50,
             :total_solicitado, :capacidad_disponible, :excede_limite, :observaciones)
         RETURNING id'
    );
    $stmt->execute([
        'usuario_id'                => $_SESSION['usuario_id'],
        'trabajador_id'             => $trabajadorId,
        'nombre'                    => $nombre,
        'dni'                       => $dni !== '' ? $dni : null,
        'dependencia'               => $dependencia !== '' ? $dependencia : null,
        'tipo_personal'             => $tipoPersonal,
        'sueldo_bruto'              => $sueldoBruto,
        'sueldo_bruto_base'         => $sueldoBrutoBase,
        'sueldo_adicional_concepto' => $sueldoAdicionalConcepto !== '' ? $sueldoAdicionalConcepto : null,
        'sueldo_adicional_monto'    => $sueldoAdicionalMonto,
        'total_descuentos'          => $totalDescuentos,
        'neto_total'                => $netoTotal,
        'neto_50'                   => $neto50,
        'total_solicitado'          => $totalSolicitado,
        'capacidad_disponible'      => $capacidadDisponible,
        'excede_limite'             => $excedeLimite ? 'true' : 'false',
        'observaciones'             => $observaciones !== '' ? $observaciones : null,
    ]);
    $evaluacionId = (int)$stmt->fetchColumn();

    // -----------------------------------------------------------------
    // 3. Detalle de descuentos + "aprendizaje" del catálogo
    // -----------------------------------------------------------------
    $stmtDesc = $pdo->prepare(
        'INSERT INTO evaluacion_descuentos (evaluacion_id, nombre, monto) VALUES (:eid, :nombre, :monto)'
    );
    // Si el nombre ya existe en el catálogo, solo sube su contador de
    // uso; si no existe, lo agrega nuevo con el contador en 1.
    $stmtAprenderDescuento = $pdo->prepare(
        'INSERT INTO descuentos_catalogo (nombre, tipo, valor_sugerido, veces_usado, orden)
         VALUES (:nombre, \'monto\', 0, 1, (SELECT COALESCE(MAX(orden), 0) + 1 FROM descuentos_catalogo))
         ON CONFLICT (nombre) DO UPDATE SET
            veces_usado = descuentos_catalogo.veces_usado + 1,
            activo = TRUE'
    );
    foreach ($descuentosLimpios as $d) {
        $stmtDesc->execute(['eid' => $evaluacionId, 'nombre' => $d['nombre'], 'monto' => $d['monto']]);

        if ($d['nombre'] !== '(sin nombre)') {
            $stmtAprenderDescuento->execute(['nombre' => $d['nombre']]);
        }
    }

    // -----------------------------------------------------------------
    // 4. Detalle de créditos + "aprendizaje" del catálogo de entidades
    // -----------------------------------------------------------------
    $stmtEnt = $pdo->prepare(
        'INSERT INTO evaluacion_creditos (evaluacion_id, entidad_nombre, entidad_tipo, monto_solicitado)
         VALUES (:eid, :nombre, :tipo, :monto)'
    );
    $stmtAprenderEntidad = $pdo->prepare(
        'INSERT INTO entidades_catalogo (nombre, tipo, veces_usado, orden)
         VALUES (:nombre, :tipo, 1, (SELECT COALESCE(MAX(orden), 0) + 1 FROM entidades_catalogo))
         ON CONFLICT (nombre) DO UPDATE SET
            veces_usado = entidades_catalogo.veces_usado + 1,
            activo = TRUE'
    );
    foreach ($entidadesLimpias as $e) {
        $stmtEnt->execute(['eid' => $evaluacionId, 'nombre' => $e['nombre'], 'tipo' => $e['tipo'], 'monto' => $e['monto']]);

        if ($e['nombre'] !== '(sin nombre)') {
            $tipoValido = in_array($e['tipo'], ['banco', 'cooperativa', 'caja', 'otro'], true) ? $e['tipo'] : 'otro';
            $stmtAprenderEntidad->execute(['nombre' => $e['nombre'], 'tipo' => $tipoValido]);
        }
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'id' => $evaluacionId]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
