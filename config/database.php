<?php
/**
 * Configuración de conexión a PostgreSQL.
 * Ajusta estos valores según tu instalación local o del servidor.
 */

// ---------------------------------------------------------------------
// EDITA ESTOS VALORES SEGÚN TU ENTORNO
// ---------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'calculadora_prestamos');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '');
// ---------------------------------------------------------------------

/**
 * Devuelve una conexión PDO reutilizable a PostgreSQL.
 * Lanza una excepción clara si la conexión falla, en vez de un error
 * genérico de PHP.
 */
function obtenerConexion(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die(
            '<div style="font-family: system-ui, sans-serif; max-width: 640px; margin: 60px auto; ' .
            'padding: 24px 28px; border-radius: 12px; background: #fff3f2; border: 1px solid #ffcdc7; color: #7a1c14;">' .
            '<h2 style="margin-top:0;">No se pudo conectar a la base de datos</h2>' .
            '<p>Revisa los datos de conexión en <code>config/database.php</code> ' .
            '(host, puerto, nombre de la base, usuario y contraseña) y confirma que ' .
            'el servicio de PostgreSQL esté activo.</p>' .
            '<p style="font-size: 13px; color: #a04238;"><strong>Detalle técnico:</strong> ' .
            htmlspecialchars($e->getMessage()) . '</p>' .
            '</div>'
        );
    }
}
