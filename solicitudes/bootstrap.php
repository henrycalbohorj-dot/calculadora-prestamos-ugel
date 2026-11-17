<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_start();
}

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['zona_horaria'] ?? 'America/Lima');

spl_autoload_register(function (string $clase): void {
    $f = __DIR__ . '/lib/' . $clase . '.php';
    if (is_file($f)) require $f;
});

function cfg(string $ruta, $def = null)
{
    global $CFG;
    $v = $CFG;
    foreach (explode('.', $ruta) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $def;
        $v = $v[$k];
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null && !cfg('db') && is_file(dirname(__DIR__) . '/config/database.php')) {
        // Misma conexión que la calculadora: un solo lugar para la contraseña
        require_once dirname(__DIR__) . '/config/database.php';
        $pdo = obtenerConexion();
        $pdo->exec("SET TIME ZONE '" . cfg('zona_horaria', 'America/Lima') . "'");
    }
    if ($pdo === null) {
        $d = cfg('db');
        if (!$d) throw new RuntimeException('No se encontró ../config/database.php: configura "db" en solicitudes/config.php');
        $pdo = new PDO(
            "pgsql:host={$d['host']};port={$d['port']};dbname={$d['dbname']}",
            $d['user'],
            $d['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET TIME ZONE '" . cfg('zona_horaria', 'America/Lima') . "'");
    }
    return $pdo;
}

/* ---------------------------- Autenticación ---------------------------- */
function requerir_login(): void
{
    if (PHP_SAPI === 'cli' || !cfg('auth.requerir', true)) return;
    if (empty($_SESSION[cfg('auth.session_key')])) {
        header('Location: ' . cfg('auth.login_url', '../login.php'));
        exit;
    }
}

function es_admin(): bool
{
    if (PHP_SAPI === 'cli' || !cfg('auth.requerir', true)) return true;
    return ($_SESSION[cfg('auth.rol_key', 'usuario_rol')] ?? '') === cfg('auth.rol_admin', 'admin');
}

function usuario_actual(): string
{
    if (PHP_SAPI === 'cli') return 'sistema';
    $n = $_SESSION[cfg('auth.nombre_key')] ?? $_SESSION[cfg('auth.session_key')] ?? null;
    return $n ? (string)$n : 'informatica';
}

/* ------------------------------- CSRF --------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['sol_csrf'])) $_SESSION['sol_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['sol_csrf'];
}
function csrf_campo(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}
function csrf_verificar(): void
{
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), (string)$t)) {
        http_response_code(419);
        exit('Sesión expirada. Recarga la página.');
    }
}

/* ------------------------------ Helpers -------------------------------- */
function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function soles($n): string
{
    if ($n === null || $n === '') return '—';
    return 'S/ ' . number_format((float)$n, 2, '.', ',');
}

function periodo_txt(?string $p): string
{
    if (!$p || !preg_match('/^(\d{4})(\d{2})$/', $p, $m)) return (string)$p;
    $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $i = (int)$m[2];
    return ($meses[$i] ?? $m[2]) . ' ' . $m[1];
}

function fecha_txt($ts, bool $hora = true): string
{
    if (!$ts) return '—';
    $t = strtotime((string)$ts);
    return $hora ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}

/** Fecha para el Google Sheet de la entidad: AA/MM/DD (configurable con google.formato_fecha). */
function fecha_hoja($ts): string
{
    if (!$ts) return '';
    return date((string)cfg('google.formato_fecha', 'y/m/d'), strtotime((string)$ts));
}

/** Ajustes guardados desde la web (tabla sol_ajustes). Con $valor guarda; sin él, lee. */
function ajuste(string $clave, ?string $valor = null): ?string
{
    static $cache = [];
    try {
        if (func_num_args() > 1) {
            db()->prepare('INSERT INTO sol_ajustes (clave, valor) VALUES (?, ?)
                           ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor, actualizado = NOW()')->execute([$clave, $valor]);
            return $cache[$clave] = $valor;
        }
        if (!array_key_exists($clave, $cache)) {
            $st = db()->prepare('SELECT valor FROM sol_ajustes WHERE clave = ?');
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            $cache[$clave] = $v === false ? null : $v;
        }
        return $cache[$clave];
    } catch (Throwable $e) {
        return null;   // falta ejecutar el SQL v1.8
    }
}

function hace($ts): string
{
    if (!$ts) return 'nunca';
    $s = time() - strtotime((string)$ts);
    if ($s < 60) return 'hace un momento';
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
    return 'hace ' . intdiv($s, 86400) . ' d';
}

const ESTADOS = [
    'PENDIENTE'   => ['Pendiente',   'pend'],
    'OBSERVADA'   => ['Observada',   'obs'],
    'APROBADO'    => ['Aprobado',    'ok'],
    'DESAPROBADO' => ['Desaprobado', 'no'],
    'DUPLICADA'   => ['Duplicada',   'dup'],
    'RETIRADA'    => ['Retirada',    'ret'],
    'ANULADA'     => ['Anulada',     'anu'],
];

function chip_estado(string $estado): string
{
    [$txt, $cls] = ESTADOS[$estado] ?? [$estado, 'pend'];
    return '<span class="chip chip-' . $cls . '">' . e($txt) . '</span>';
}

function historial(int $solicitudId, string $accion, string $detalle = '', ?string $usuario = null): void
{
    db()->prepare('INSERT INTO sol_historial (solicitud_id, accion, detalle, usuario) VALUES (?,?,?,?)')
        ->execute([$solicitudId, $accion, $detalle, $usuario ?? usuario_actual()]);
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['sol_flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['sol_flash'] ?? null;
    unset($_SESSION['sol_flash']);
    return $f;
}

/** Extrae el ID de un Google Sheet desde una URL completa o lo devuelve tal cual. */
function extraer_spreadsheet_id(string $s): string
{
    $s = trim($s);
    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $s, $m)) return $m[1];
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $s);
}
