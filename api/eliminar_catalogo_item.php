<?php
/**
 * "Elimina" (desactiva) un ítem del catálogo de descuentos o entidades.
 * No se borra físicamente para no romper evaluaciones históricas.
 * POST JSON: { tipo_catalogo: 'descuento'|'entidad', id }
 * Sólo administradores.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}
if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Requiere permisos de administrador.']);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);
if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

$tipoCatalogo = $datos['tipo_catalogo'] ?? '';
$id           = (int)($datos['id'] ?? 0);

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
    exit;
}

$tabla = match ($tipoCatalogo) {
    'descuento' => 'descuentos_catalogo',
    'entidad'   => 'entidades_catalogo',
    default     => null,
};

if ($tabla === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'tipo_catalogo debe ser "descuento" o "entidad".']);
    exit;
}

$pdo = obtenerConexion();

try {
    $stmt = $pdo->prepare("UPDATE {$tabla} SET activo = FALSE WHERE id = :id");
    $stmt->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
}
