<?php
/**
 * Crea un nuevo ítem en el catálogo de descuentos o de entidades.
 * POST JSON: { tipo_catalogo: 'descuento'|'entidad', nombre, tipo? }
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
$nombre       = trim((string)($datos['nombre'] ?? ''));

if ($nombre === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio.']);
    exit;
}

$pdo = obtenerConexion();

try {
    if ($tipoCatalogo === 'descuento') {
        $grupoExclusivo = trim((string)($datos['grupo_exclusivo'] ?? ''));
        $grupoExclusivo = $grupoExclusivo !== '' ? $grupoExclusivo : null;

        $stmt = $pdo->prepare(
            'INSERT INTO descuentos_catalogo (nombre, tipo, valor_sugerido, orden, grupo_exclusivo)
             VALUES (:nombre, \'monto\', 0, (SELECT COALESCE(MAX(orden), 0) + 1 FROM descuentos_catalogo), :grupo_exclusivo)
             ON CONFLICT (nombre) DO UPDATE SET activo = TRUE, grupo_exclusivo = EXCLUDED.grupo_exclusivo
             RETURNING id'
        );
        $stmt->execute(['nombre' => $nombre, 'grupo_exclusivo' => $grupoExclusivo]);
    } elseif ($tipoCatalogo === 'entidad') {
        $tipoEntidad = in_array($datos['tipo'] ?? '', ['banco', 'cooperativa', 'caja', 'otro'], true)
            ? $datos['tipo']
            : 'otro';
        $stmt = $pdo->prepare(
            'INSERT INTO entidades_catalogo (nombre, tipo, orden)
             VALUES (:nombre, :tipo, (SELECT COALESCE(MAX(orden), 0) + 1 FROM entidades_catalogo))
             ON CONFLICT (nombre) DO UPDATE SET activo = TRUE, tipo = EXCLUDED.tipo
             RETURNING id'
        );
        $stmt->execute(['nombre' => $nombre, 'tipo' => $tipoEntidad]);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'tipo_catalogo debe ser "descuento" o "entidad".']);
        exit;
    }

    $id = (int)$stmt->fetchColumn();
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
