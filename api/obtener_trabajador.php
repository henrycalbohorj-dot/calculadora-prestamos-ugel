<?php
/**
 * Devuelve el detalle completo de un trabajador guardado, más los
 * descuentos, entidades y remuneración adicional de su evaluación más
 * reciente, para precargar el formulario de la calculadora cuando se
 * selecciona en el buscador. La remuneración adicional también se
 * recuerda (si la última evaluación no tenía, simplemente no se
 * precarga) — si un mes no aplica, basta con quitarla y guardar así
 * para que la próxima búsqueda ya no la traiga.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
    exit;
}

$pdo = obtenerConexion();

$stmt = $pdo->prepare(
    'SELECT id, nombres, dni, dependencia, tipo_personal, sueldo_bruto_referencia
     FROM trabajadores WHERE id = :id'
);
$stmt->execute(['id' => $id]);
$trabajador = $stmt->fetch();

if (!$trabajador) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Trabajador no encontrado.']);
    exit;
}

// Última evaluación de este trabajador (si tiene alguna)
$stmtEval = $pdo->prepare(
    'SELECT id, sueldo_bruto_base, sueldo_adicional_concepto, sueldo_adicional_monto FROM evaluaciones
     WHERE trabajador_id = :id
     ORDER BY creado_en DESC
     LIMIT 1'
);
$stmtEval->execute(['id' => $id]);
$ultimaEvaluacion = $stmtEval->fetch();

$descuentos = [];
$entidades = [];
if ($ultimaEvaluacion) {
    $stmtDesc = $pdo->prepare(
        'SELECT nombre, monto FROM evaluacion_descuentos WHERE evaluacion_id = :eid ORDER BY id'
    );
    $stmtDesc->execute(['eid' => $ultimaEvaluacion['id']]);
    $descuentos = $stmtDesc->fetchAll();

    $stmtEnt = $pdo->prepare(
        'SELECT entidad_nombre AS nombre, monto_solicitado AS monto FROM evaluacion_creditos WHERE evaluacion_id = :eid ORDER BY id'
    );
    $stmtEnt->execute(['eid' => $ultimaEvaluacion['id']]);
    $entidades = $stmtEnt->fetchAll();
}

$adicionalMonto = (float)($ultimaEvaluacion['sueldo_adicional_monto'] ?? 0);

echo json_encode([
    'ok' => true,
    'trabajador' => $trabajador,
    'ultimo_sueldo_bruto' => $ultimaEvaluacion['sueldo_bruto_base'] ?? $trabajador['sueldo_bruto_referencia'] ?? null,
    'descuentos' => $descuentos,
    'entidades' => $entidades,
    'sueldo_adicional_concepto' => $adicionalMonto > 0 ? ($ultimaEvaluacion['sueldo_adicional_concepto'] ?? '') : null,
    'sueldo_adicional_monto' => $adicionalMonto > 0 ? $adicionalMonto : null,
]);
