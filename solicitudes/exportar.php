<?php
require __DIR__ . '/bootstrap.php';
requerir_login();

[$where, $params] = Consultas::filtros($_GET);
$st = db()->prepare("SELECT s.*, COALESCE(NULLIF(s.entidad_texto,''), e.nombre) AS entidad
                     FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id
                     WHERE $where ORDER BY e.nombre, s.apellidos, s.nombres");
$st->execute($params);
$filas = $st->fetchAll();

$archivo = Exportador::formatoDre($filas);
$nombre = 'FORMATO_DRE_50' . (!empty($_GET['estado']) ? '_' . $_GET['estado'] : '') . (!empty($_GET['periodo']) ? '_' . $_GET['periodo'] : '') . '_' . date('Ymd_Hi') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $nombre) . '"');
header('Content-Length: ' . filesize($archivo));
readfile($archivo);
@unlink($archivo);
