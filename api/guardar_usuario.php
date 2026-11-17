<?php
/**
 * Crea un nuevo usuario del sistema (para que el personal pueda ingresar
 * con su propio usuario y contraseña). Solo administradores.
 * POST JSON: { nombre_completo, usuario, password, rol, correo, celular,
 *              direccion?, cargo?, area? }
 * Correo y celular son obligatorios: son los datos con los que el propio
 * usuario podrá verificar su identidad en "Olvidé mi contraseña" sin
 * depender del administrador.
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

$nombreCompleto = trim((string)($datos['nombre_completo'] ?? ''));
$usuario        = trim((string)($datos['usuario'] ?? ''));
$password       = (string)($datos['password'] ?? '');
$rol            = in_array($datos['rol'] ?? '', ['admin', 'operador'], true) ? $datos['rol'] : 'operador';
$correo         = trim((string)($datos['correo'] ?? ''));
$celular        = trim((string)($datos['celular'] ?? ''));
$direccion      = trim((string)($datos['direccion'] ?? ''));
$cargo          = trim((string)($datos['cargo'] ?? ''));
$area           = trim((string)($datos['area'] ?? ''));

if ($nombreCompleto === '' || $usuario === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El nombre y el usuario son obligatorios.']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El usuario solo puede tener letras, números, puntos, guiones y guion bajo (3 a 50 caracteres).']);
    exit;
}
if (strlen($password) < 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.']);
    exit;
}
if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ingresa un correo electrónico válido (lo necesita para "Olvidé mi contraseña").']);
    exit;
}
if ($celular === '' || !preg_match('/^[0-9+\s-]{6,20}$/', $celular)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ingresa un número de celular válido (lo necesita para "Olvidé mi contraseña").']);
    exit;
}

$pdo = obtenerConexion();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre_completo, usuario, password_hash, rol, correo, celular, direccion, cargo, area)
         VALUES (:nombre_completo, :usuario, :password_hash, :rol, :correo, :celular, :direccion, :cargo, :area)
         RETURNING id'
    );
    $stmt->execute([
        'nombre_completo' => $nombreCompleto,
        'usuario'         => $usuario,
        'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
        'rol'             => $rol,
        'correo'          => $correo,
        'celular'         => $celular,
        'direccion'       => $direccion !== '' ? $direccion : null,
        'cargo'           => $cargo !== '' ? $cargo : null,
        'area'            => $area !== '' ? $area : null,
    ]);
    $id = (int)$stmt->fetchColumn();
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    // Violación de la restricción UNIQUE sobre "usuario"
    if (str_contains($e->getMessage(), 'usuarios_usuario_key') || str_contains($e->getMessage(), 'duplicate key')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe un usuario con ese nombre de ingreso.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
