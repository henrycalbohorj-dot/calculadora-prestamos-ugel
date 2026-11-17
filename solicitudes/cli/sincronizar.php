<?php
/**
 * Sincronización automática (Programador de tareas de Windows).
 *   C:\xampp\php\php.exe C:\xampp\htdocs\calculadora-prestamos-ugel\solicitudes\cli\sincronizar.php
 * Comparte el candado con la web: si la web ya sincronizó hace menos de 100 s, no repite.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/bootstrap.php';

$r = Vivo::sincronizar(false);
if ($r === null) { echo "Omitida: hay una sincronización en curso o se hizo hace menos de " . Vivo::MINIMO . " s.\n"; exit(0); }
foreach ($r['res'] as $x) {
    printf("[%s] %-35s filas:%d nuevas:%d cambios:%d retiradas:%d escritas:%d duplicados:%d auto-desaprobadas:%d %s\n",
        date('Y-m-d H:i:s'), mb_strimwidth($x['entidad'], 0, 35), $x['leidas'], $x['nuevas'], $x['actualizadas'], $x['retiradas'], $x['escritas'], $x['duplicados'], $x['automaticas'],
        $x['error'] ? 'ERROR: ' . $x['error'] : 'OK');
}
if ($r['panelError']) echo 'PANEL: ' . $r['panelError'] . "\n";
