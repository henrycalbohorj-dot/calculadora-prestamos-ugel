<?php
/**
 * Actualiza un usuario existente: datos de perfil (nombre, correo,
 * celular, dirección, cargo, área), rol, activo/inactivo y/o contraseña.
 * Solo administradores. Incluye resguardos para no dejar el sistema sin
 * ningún administrador activo, y para que un admin no se desactive ni
 * se quite el rol a sí mismo por error.
 * POST JSON: { id, nombre_completo?, correo?, celular?, direccion?,
 * cargo?, area?, rol?, activo?, password? }  (solo se aplican los
 * campos presentes)
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

$id = (int)($datos['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
    exit;
}

$pdo = obtenerConexion();

$stmtActual = $pdo->prepare('SELECT id, rol, activo FROM usuarios WHERE id = :id');
$stmtActual->execute(['id' => $id]);
$actual = $stmtActual->fetch();
if (!$actual) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado.']);
    exit;
}

$esUnoMismo = ((int)$_SESSION['usuario_id'] === $id);

// --- Determinar los cambios solicitados ---
$nuevoRol    = array_key_exists('rol', $datos) && in_array($datos['rol'], ['admin', 'operador'], true) ? $datos['rol'] : null;
$nuevoActivo = array_key_exists('activo', $datos) ? (bool)$datos['activo'] : null;
$nuevoPassword = array_key_exists('password', $datos) ? (string)$datos['password'] : null;

if ($nuevoPassword !== null && $nuevoPassword !== '' && strlen($nuevoPassword) < 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.']);
    exit;
}

// --- Campos de perfil (opcionales, edición libre) ---
$nuevoNombre    = array_key_exists('nombre_completo', $datos) ? trim((string)$datos['nombre_completo']) : null;
$nuevoCorreo    = array_key_exists('correo', $datos) ? trim((string)$datos['correo']) : null;
$nuevoCelular   = array_key_exists('celular', $datos) ? trim((string)$datos['celular']) : null;
$nuevaDireccion = array_key_exists('direccion', $datos) ? trim((string)$datos['direccion']) : null;
$nuevoCargo     = array_key_exists('cargo', $datos) ? trim((string)$datos['cargo']) : null;
$nuevaArea      = array_key_exists('area', $datos) ? trim((string)$datos['area']) : null;

if ($nuevoNombre !== null && $nuevoNombre === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El nombre no puede quedar vacío.']);
    exit;
}
if ($nuevoCorreo !== null && ($nuevoCorreo === '' || !filter_var($nuevoCorreo, FILTER_VALIDATE_EMAIL))) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ingresa un correo electrónico válido.']);
    exit;
}
if ($nuevoCelular !== null && ($nuevoCelular === '' || !preg_match('/^[0-9+\s-]{6,20}$/', $nuevoCelular))) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ingresa un número de celular válido.']);
    exit;
}

// Resultará en admin activo tras el cambio (para las validaciones de abajo)
$quedaraComoAdmin = ($nuevoRol ?? $actual['rol']) === 'admin';
$quedaraActivo    = $nuevoActivo ?? $actual['activo'];

if ($esUnoMismo && (!$quedaraComoAdmin || !$quedaraActivo)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No puedes quitarte a ti mismo el rol de administrador ni desactivar tu propia cuenta.']);
    exit;
}

// Si el cambio implica que este usuario deja de ser admin activo, hay que
// asegurarse de que quede al menos otro administrador activo.
$dejaDeSerAdminActivo = ($actual['rol'] === 'admin' && $actual['activo']) && !($quedaraComoAdmin && $quedaraActivo);
if ($dejaDeSerAdminActivo) {
    $stmtOtros = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = TRUE AND id != :id"
    );
    $stmtOtros->execute(['id' => $id]);
    if ((int)$stmtOtros->fetchColumn() === 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Debe quedar al menos un administrador activo en el sistema.']);
        exit;
    }
}

$campos = [];
$params = ['id' => $id];

if ($nuevoRol !== null) {
    $campos[] = 'rol = :rol';
    $params['rol'] = $nuevoRol;
}
if ($nuevoActivo !== null) {
    $campos[] = 'activo = :activo';
    $params['activo'] = $nuevoActivo ? 'true' : 'false';
}
if ($nuevoPassword !== null && $nuevoPassword !== '') {
    $campos[] = 'password_hash = :password_hash';
    $params['password_hash'] = password_hash($nuevoPassword, PASSWORD_DEFAULT);
}
if ($nuevoNombre !== null) {
    $campos[] = 'nombre_completo = :nombre_completo';
    $params['nombre_completo'] = $nuevoNombre;
}
if ($nuevoCorreo !== null) {
    $campos[] = 'correo = :correo';
    $params['correo'] = $nuevoCorreo;
}
if ($nuevoCelular !== null) {
    $campos[] = 'celular = :celular';
    $params['celular'] = $nuevoCelular;
}
if ($nuevaDireccion !== null) {
    $campos[] = 'direccion = :direccion';
    $params['direccion'] = $nuevaDireccion !== '' ? $nuevaDireccion : null;
}
if ($nuevoCargo !== null) {
    $campos[] = 'cargo = :cargo';
    $params['cargo'] = $nuevoCargo !== '' ? $nuevoCargo : null;
}
if ($nuevaArea !== null) {
    $campos[] = 'area = :area';
    $params['area'] = $nuevaArea !== '' ? $nuevaArea : null;
}

if (!$campos) {
    echo json_encode(['ok' => true, 'sin_cambios' => true]);
    exit;
}

try {
    $sql = 'UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
}
