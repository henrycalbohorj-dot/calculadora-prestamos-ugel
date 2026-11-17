<?php
require __DIR__ . '/bootstrap.php';
requerir_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Método no permitido'], 405);
csrf_verificar();
session_write_close();   // no bloquear otras pestañas mientras sincroniza
set_time_limit(300);
ignore_user_abort(true);

try {
    // Comparte el candado con la sincronización automática: si hay una en curso, espera a que termine.
    json_out(Vivo::totales(Vivo::sincronizar(true)));
} catch (Throwable $ex) {
    json_out(['error' => $ex->getMessage()], 500);
}
