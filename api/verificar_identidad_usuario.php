<?php
/**
 * Paso 1 de "Olvidé mi contraseña": verifica que el usuario, correo y
 * celular ingresados correspondan a una cuenta activa. NO requiere
 * sesión (es de acceso público, se usa antes de iniciar sesión).
 * No cambia nada — solo confirma si los datos coinciden, para dar una
 * respuesta amigable antes de mostrar el formulario de nueva contraseña.
 * La operación real de cambio se vuelve a validar de manera
 * independiente en restablecer_password_propio.php.
 * POST JSON: { usuario, correo, celular }
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$datos = json_decode(file_get_contents('php://input'), true);
if (!is_array($datos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

$usuario = trim((string)($datos['usuario'] ?? ''));
$correo  = trim((string)($datos['correo'] ?? ''));
$celular = trim((string)($datos['celular'] ?? ''));

if ($usuario === '' || $correo === '' || $celular === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Completa usuario, correo y celular.']);
    exit;
}

$pdo = obtenerConexion();
$stmt = $pdo->prepare(
    "SELECT id, nombre_completo FROM usuarios
     WHERE activo = TRUE
       AND lower(usuario) = lower(:usuario)
       AND lower(correo) = lower(:correo)
       AND celular = :celular
     LIMIT 1"
);
$stmt->execute(['usuario' => $usuario, 'correo' => $correo, 'celular' => $celular]);
$fila = $stmt->fetch();

if (!$fila) {
    // Mensaje genérico a propósito: no decimos cuál dato falló, para no
    // ayudar a alguien a adivinar los datos de otra persona.
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Esos datos no coinciden con ningún usuario activo. Verifica con tu administrador que estén bien registrados.']);
    exit;
}

echo json_encode(['ok' => true, 'nombre' => $fila['nombre_completo']]);
