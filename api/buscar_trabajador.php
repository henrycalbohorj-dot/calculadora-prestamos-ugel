<?php
/**
 * Busca trabajadores ya guardados por nombre o DNI (para la lupa de
 * búsqueda de la calculadora). Devuelve una lista corta de coincidencias.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'resultados' => []]);
    exit;
}

$pdo = obtenerConexion();

$stmt = $pdo->prepare(
    'SELECT id, nombres, dni, dependencia, tipo_personal, sueldo_bruto_referencia
     FROM trabajadores
     WHERE nombres ILIKE :q OR dni ILIKE :q
     ORDER BY nombres
     LIMIT 15'
);
$stmt->execute(['q' => '%' . $q . '%']);
$resultados = $stmt->fetchAll();

echo json_encode(['ok' => true, 'resultados' => $resultados]);
