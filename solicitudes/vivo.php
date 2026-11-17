<?php
/**
 * Web en vivo (Informática).
 *  GET  vivo.php?desde=ID&sol=ID   → estado liviano (solo base de datos, no consulta a Google)
 *  POST vivo.php (X-CSRF)          → sincronización automática (máx. una cada 2 min para todos los usuarios)
 */
require __DIR__ . '/bootstrap.php';
requerir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    session_write_close();          // no bloquear la navegación mientras sincroniza
    set_time_limit(300);
    ignore_user_abort(true);        // si se cierra la pestaña, la sincronización termina igual
    try {
        $r = Vivo::sincronizar(false);
        if ($r === null) json_out(['ok' => true, 'omitida' => true, 'hace' => Vivo::hace()]);
        json_out(Vivo::totales($r) + ['hace' => 0]);
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
}

session_write_close();
$pdo = db();
$otic = Sincronizador::hayOticVisto();
$out = [
    'sello'      => Vivo::sello(),
    'max_id'     => Vivo::maxId(),
    'pendientes' => (int)$pdo->query("SELECT COUNT(*) FROM sol_solicitudes WHERE estado = 'PENDIENTE'")->fetchColumn(),
    'otic_nuevos'=> $otic ? (int)$pdo->query('SELECT COUNT(*) FROM sol_solicitudes WHERE otic_recepcion IS NOT NULL AND NOT otic_visto')->fetchColumn() : 0,
    'hace'       => Vivo::hace(),
    'hace_txt'   => hace($pdo->query('SELECT MAX(ultima_sync) FROM sol_entidades WHERE activa')->fetchColumn()),
    'intervalo'  => Vivo::INTERVALO,
    'nuevas'     => [],
    'otic'       => [],
];
if (($sol = (int)($_GET['sol'] ?? 0)) > 0) $out['sello_sol'] = Vivo::selloSolicitud($sol);

$desde = (int)($_GET['desde'] ?? 0);
if ($desde > 0) {
    $st = $pdo->prepare("SELECT s.id, e.nombre AS entidad, e.codigo, s.apellidos, s.nombres, s.dni, s.estado, s.tipo_producto, s.cuota
                           FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id
                          WHERE s.id > ? ORDER BY s.id LIMIT 20");
    $st->execute([$desde]);
    $out['nuevas'] = $st->fetchAll();
}
if ($otic) {
    $out['otic'] = $pdo->query("SELECT s.id, e.nombre AS entidad, s.apellidos, s.nombres, s.dni, s.otic_recepcion
                                  FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id
                                 WHERE s.otic_recepcion IS NOT NULL AND NOT s.otic_visto ORDER BY s.id DESC LIMIT 10")->fetchAll();
}
json_out($out);
