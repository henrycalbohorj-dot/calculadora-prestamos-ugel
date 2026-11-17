<?php
/**
 * Paso 2 de "Olvidé mi contraseña": si usuario + correo + celular
 * coinciden con una cuenta activa (se vuelve a comprobar aquí, no se
 * confía en que el paso 1 ya lo haya validado), define la nueva
 * contraseña. NO requiere sesión — es de acceso público.
 * POST JSON: { usuario, correo, celular, password }
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$datos = json_decode(file_get_contents('php://input'), true);
if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

$usuario  = trim((string)($datos['usuario'] ?? ''));
$correo   = trim((string)($datos['correo'] ?? ''));
$celular  = trim((string)($datos['celular'] ?? ''));
$password = (string)($datos['password'] ?? '');

if ($usuario === '' || $correo === '' || $celular === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Completa usuario, correo y celular.']);
    exit;
}
if (strlen($password) < 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
    exit;
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    "SELECT id FROM usuarios
     WHERE activo = TRUE
       AND lower(usuario) = lower(:usuario)
       AND lower(correo) = lower(:correo)
       AND celular = :celular
     LIMIT 1"
);
$stmt->execute(['usuario' => $usuario, 'correo' => $correo, 'celular' => $celular]);
$fila = $stmt->fetch();

if (!$fila) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Esos datos no coinciden con ningún usuario activo. Verifica con tu administrador que estén bien registrados.']);
    exit;
}

try {
    $upd = $pdo->prepare('UPDATE usuarios SET password_hash = :password_hash WHERE id = :id');
    $upd->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id'            => $fila['id'],
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al actualizar la contraseña: ' . $e->getMessage()]);
}
