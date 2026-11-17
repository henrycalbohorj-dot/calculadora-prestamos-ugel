<?php
/**
 * Funciones de autenticación y control de sesión.
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Exige que exista una sesión activa. Si no la hay, redirige al login.
 * Debe llamarse al inicio de cualquier página protegida.
 */
function exigirSesion(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Exige que el usuario en sesión tenga rol de administrador.
 */
function exigirAdmin(): void
{
    exigirSesion();
    if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<div style="font-family: system-ui, sans-serif; max-width: 640px; margin: 60px auto; padding: 24px 28px;">' .
            '<h2>Acceso restringido</h2><p>Esta sección requiere permisos de administrador. ' .
            '<a href="index.php">Volver a la calculadora</a></p></div>');
    }
}

/**
 * Intenta autenticar a un usuario. Devuelve true/false.
 */
function intentarLogin(string $usuario, string $password): bool
{
    $pdo = obtenerConexion();
    $stmt = $pdo->prepare(
        'SELECT id, nombre_completo, usuario, password_hash, rol
         FROM usuarios
         WHERE usuario = :usuario AND activo = TRUE
         LIMIT 1'
    );
    $stmt->execute(['usuario' => $usuario]);
    $fila = $stmt->fetch();

    if (!$fila || !password_verify($password, $fila['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id']     = $fila['id'];
    $_SESSION['usuario_nombre'] = $fila['nombre_completo'];
    $_SESSION['usuario_login']  = $fila['usuario'];
    $_SESSION['usuario_rol']    = $fila['rol'];

    return true;
}

function cerrarSesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
