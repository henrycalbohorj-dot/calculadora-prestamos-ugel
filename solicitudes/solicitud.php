<?php
require __DIR__ . '/bootstrap.php';
requerir_login();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$cargar = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT s.*, e.nombre AS entidad, e.codigo AS entidad_cod, e.spreadsheet_id, e.grupo AS entidad_grupo
                         FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$s = $cargar();
if (!$s) { http_response_code(404); exit('Solicitud no encontrada.'); }

$obs = json_decode((string)$s['observaciones'], true) ?: [];
$conErrores = Validador::tieneErrores($obs);
// v1.14: solo se decide sobre una solicitud "Por evaluar". Ya aprobada, desaprobada u observada queda cerrada.
$cerrada = $s['estado'] !== 'PENDIENTE';
// v1.16: al terminar de evaluar se vuelve a la bandeja (con los mismos filtros con los que se abrió la solicitud)
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && basename((string)parse_url($ref, PHP_URL_PATH)) === 'bandeja.php') {
    $q = (string)parse_url($ref, PHP_URL_QUERY);
    $_SESSION['sol_volver'][$id] = 'bandeja.php' . ($q !== '' ? '?' . $q : '');
}
$volverA = $_SESSION['sol_volver'][$id] ?? 'bandeja.php';

/* ------------------------------- Acciones ------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';
    $coment = trim((string)($_POST['comentario'] ?? ''));
    $usuario = usuario_actual();

    // Un clic a la vez por solicitud (evita dobles evaluaciones) y se valida contra el estado REAL de la base
    $permitido = ['aprobar' => ['PENDIENTE'], 'desaprobar' => ['PENDIENTE'], 'observar' => ['PENDIENTE'],
                  'duplicada' => ['PENDIENTE', 'OBSERVADA'], 'reabrir' => ['APROBADO', 'DESAPROBADO', 'OBSERVADA', 'DUPLICADA', 'ANULADA'],
                  'anular' => ['PENDIENTE', 'OBSERVADA', 'APROBADO', 'DESAPROBADO']];
    if (isset($permitido[$accion])) {
        $pdo->query('SELECT pg_advisory_lock(7719, ' . $id . ')');
        register_shutdown_function(function () use ($id) { try { db()->query('SELECT pg_advisory_unlock(7719, ' . $id . ')'); } catch (Throwable $e) {} });
        $s = $cargar();
        $obs = json_decode((string)$s['observaciones'], true) ?: [];
        $conErrores = Validador::tieneErrores($obs);
        if (!in_array($s['estado'], $permitido[$accion], true)) {
            flash($accion === 'reabrir' ? 'La solicitud ya está Por evaluar.'
                : 'Esta solicitud ya fue evaluada (' . $s['estado'] . ($s['evaluado_por'] ? ' por ' . $s['evaluado_por'] : '') . ($s['evaluado_en'] ? ' el ' . fecha_txt($s['evaluado_en']) : '')
                  . '). La evaluación está cerrada y no se registró otra.', 'warn');
            header("Location: solicitud.php?id=$id"); exit;
        }
        if ($accion === 'reabrir' && !es_admin()) {
            flash('Solo un usuario administrador puede reabrir una solicitud ya evaluada.', 'err');
            header("Location: solicitud.php?id=$id"); exit;
        }
    }

    if (in_array($accion, ['aprobar', 'desaprobar'], true)) {
        if ($conErrores) { flash('La solicitud tiene errores de datos: la entidad debe corregirlos primero.', 'err'); header("Location: solicitud.php?id=$id"); exit; }
        $num = fn($v) => (float)str_replace(',', '', (string)($v ?? 0));
        $modo = ($_POST['modo'] ?? '') === 'DOCENTE' ? 'DOCENTE' : (($_POST['modo'] ?? '') === 'ADMINISTRATIVO' ? 'ADMINISTRATIVO' : '');
        $decision = $accion === 'aprobar' ? 'APROBADO' : 'DESAPROBADO';
        $volver = function (string $m) use ($id) { flash($m, 'err'); header("Location: solicitud.php?id=$id"); exit; };
        if ($modo === '') $volver('Elige si el trabajador es Docente y/o cesante o Administrativo.');
        // v1.15: el tipo de trabajador lo marca la entidad en el portal; la evaluación debe seguirlo
        $modoEnt = $s['tipo_trabajador'] ?? null;
        if ($modoEnt && $modo !== $modoEnt) $volver('La entidad indicó que el trabajador es ' . ($modoEnt === 'DOCENTE' ? 'Docente y/o cesante' : 'Administrativo')
                                                   . '. Si no corresponde, usa Observar para que la entidad lo corrija.');
        if ($decision === 'DESAPROBADO' && $coment === '') $volver('Para desaprobar escribe el motivo: la entidad lo verá en su Sheet.');
        $cuota = (float)$s['cuota'];

        if ($modo === 'DOCENTE') {
            // Docente y/o cesante: el evaluador solo registra el estado y el monto disponible
            // Monto disponible = hasta cuánto se le puede descontar al trabajador ANTES de esta cuota.
            // Si se aprueba, lo que le queda para otras entidades es ese monto menos la cuota.
            $txt = trim((string)($_POST['disponible_doc'] ?? ''));
            $monto = $txt === '' ? null : round(max($num($txt), 0), 2);
            if ($decision === 'APROBADO' && $monto === null) $volver('Para aprobar indica el monto disponible del trabajador.');
            if ($decision === 'APROBADO' && $cuota > $monto + 0.001) {
                $volver('La cuota ' . soles($cuota) . ' supera el monto disponible ' . soles($monto) . ': no se puede aprobar. Desaprueba con el motivo.');
            }
            $libre = $monto === null ? null : ($decision === 'APROBADO' ? round(max($monto - $cuota, 0), 2) : $monto);
            $r = ['remuneracion' => 0, 'descuentos_ley' => 0, 'ley' => [], 'terceros' => [], 'total_terceros' => 0, 'cuota_liberada' => 0,
                  'aprobadas_sistema' => 0, 'porcentaje' => (float)cfg('evaluacion.porcentaje_tope', 50), 'base' => 0, 'tope' => 0,
                  'disponible' => $monto ?? 0, 'margen' => $monto === null ? null : round($monto - $cuota, 2), 'cuota' => $cuota];
            $calc = null;
            $folio = null;
            $detalle = 'Docente y/o cesante · monto disponible ' . ($monto === null ? 'no indicado' : soles($monto))
                     . ($decision === 'APROBADO' ? ' · le queda disponible ' . soles($libre) : '');
        } else {
            $ley = [];
            foreach ((array)($_POST['d'] ?? []) as $d) $ley[] = ['concepto' => $d['concepto'] ?? '', 'monto' => $num($d['monto'] ?? 0)];
            $terceros = [];
            foreach ((array)($_POST['t'] ?? []) as $t) {
                $terceros[] = ['concepto' => $t['concepto'] ?? '', 'monto' => $num($t['monto'] ?? 0), 'libera' => !empty($t['libera'])];
            }
            // Un solo sistema de pensiones (AFP u ONP)
            $pens = array_values(array_unique(array_filter(array_map(fn($x) => $x['monto'] > 0 && Evaluador::grupoExclusivo((string)$x['concepto']) ? mb_strtoupper(trim($x['concepto'])) : null, $ley))));
            if (count($pens) > 1) $volver('Solo puede haber un sistema de pensiones (' . implode(' / ', $pens) . '): deja uno.');
            $aprob = !empty($_POST['considerar_aprobadas']) ? Evaluador::aprobadasSistema($s['dni'], $s['periodo'], $id) : 0.0;
            $r = Evaluador::calcular(['remuneracion' => $num($_POST['remuneracion'] ?? 0), 'ley' => $ley, 'terceros' => $terceros,
                                      'cuota' => $cuota, 'aprobadas_sistema' => $aprob]);
            if ($r['remuneracion'] <= 0) $volver('Ingresa el sueldo bruto de la boleta.');
            $calc = $r['resultado'];           // el cálculo recomienda; la decisión es del evaluador
            $r['resultado_calculo'] = $calc;
            $r['resultado'] = $decision;
            $libre = Evaluador::libre($r, $decision);
            $detalle = ($decision === $calc ? 'Conforme al cálculo' : 'Distinto al cálculo (' . $calc . ')') . ' · Neto 50% ' . soles($r['tope'])
                     . ' · total ' . soles($r['total_solicitado']) . ' · margen ' . soles($r['margen']);
        }

        $pdo->beginTransaction();
        if ($modo === 'ADMINISTRATIVO') {
            $folio = Evaluador::registrarEnCalculadora($s, $r, 'Solicitud #' . $id . ' de ' . $s['entidad'] . ' · ' . $s['tipo_producto']
                         . ' · periodo ' . $s['periodo'] . ' · Cálculo: ' . $calc . ' · Decisión: ' . $decision . ($coment ? ' · ' . $coment : ''));
        }
        $pdo->prepare('INSERT INTO sol_evaluaciones (solicitud_id, remuneracion, descuentos_ley, ley, terceros, total_terceros, cuota_liberada,
                         aprobadas_sistema, porcentaje, base, tope, disponible, cuota, resultado, resultado_calculo, comentario, usuario, calc_evaluacion_id,
                         modo, disponible_libre)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $r['remuneracion'], $r['descuentos_ley'], json_encode($r['ley'], JSON_UNESCAPED_UNICODE),
                       json_encode($r['terceros'], JSON_UNESCAPED_UNICODE),
                       $r['total_terceros'], $r['cuota_liberada'], $r['aprobadas_sistema'], $r['porcentaje'], $r['base'],
                       $r['tope'], $r['disponible'], $cuota, $decision, $calc, $coment ?: null, $usuario, $folio, $modo, $libre]);
        $pdo->prepare('UPDATE sol_solicitudes SET estado=?, disponible=?, margen=?, evaluado_por=?, evaluado_en=NOW(), comentario=?, auto_regla=FALSE, actualizado=NOW() WHERE id=?')
            ->execute([$decision, $r['disponible'], $r['margen'], $usuario, $coment ?: null, $id]);
        historial($id, $decision, $detalle . ($coment ? ' · ' . $coment : '') . ($libre !== null && $modo === 'ADMINISTRATIVO' ? ' · le queda disponible ' . soles($libre) : '')
                  . ($folio ? ' · folio calculadora #' . $folio : ''));
        $pdo->commit();
        $msg = 'Solicitud ' . ($decision === 'APROBADO' ? 'aprobada' : 'desaprobada') . '.';
    } elseif ($accion === 'observar') {
        if ($coment === '') { flash('Escribe el motivo de la observación: la entidad lo verá en su Sheet.', 'err'); header("Location: solicitud.php?id=$id"); exit; }
        $pdo->prepare("UPDATE sol_solicitudes SET estado='OBSERVADA', comentario=?, evaluado_por=?, evaluado_en=NOW(), actualizado=NOW() WHERE id=?")
            ->execute([$coment, $usuario, $id]);
        historial($id, 'OBSERVADA', $coment);
        $msg = 'Solicitud observada.';
    } elseif ($accion === 'duplicada') {
        $pdo->prepare("UPDATE sol_solicitudes SET estado='DUPLICADA', comentario=?, evaluado_por=?, evaluado_en=NOW(), actualizado=NOW() WHERE id=?")
            ->execute([$coment ?: null, $usuario, $id]);
        historial($id, 'DUPLICADA', 'Marcada como duplicada' . ($s['duplicado_de'] ? ' de la solicitud #' . $s['duplicado_de'] : '') . ($coment ? ': ' . $coment : ''));
        $msg = 'Solicitud marcada como duplicada.';
    } elseif ($accion === 'anular') {
        // v1.18: Informática anula una solicitud ya evaluada (p. ej. el cliente ahora quiere otro monto).
        // Deja de contar: no reserva presupuesto ni figura como "cliente ya registrado"; la entidad registra una nueva.
        $sustento = trim((string)($_POST['sustento'] ?? ''));
        if (mb_strlen($sustento) < 10) { flash('Para anular escribe el sustento (mínimo 10 caracteres): quedará en el historial y la entidad lo verá.', 'err'); header("Location: solicitud.php?id=$id"); exit; }
        $antes = $s['estado'];
        $pdo->prepare("UPDATE sol_solicitudes SET estado='ANULADA', comentario=?, evaluado_por=?, evaluado_en=NOW(), auto_regla=FALSE, duplicado_de=NULL, actualizado=NOW() WHERE id=?")
            ->execute([$sustento, $usuario, $id]);
        historial($id, 'ANULADA', 'Anulada por Informática · estado anterior: ' . $antes . ' · cuota ' . soles($s['cuota']) . ' · Sustento: ' . $sustento);
        // También queda anotada en el Historial de la calculadora (copia de la evaluación)
        try {
            $pdo->prepare("UPDATE evaluaciones SET observaciones = ? || COALESCE(observaciones, '')
                           WHERE id IN (SELECT calc_evaluacion_id FROM sol_evaluaciones WHERE solicitud_id = ? AND calc_evaluacion_id IS NOT NULL)")
                ->execute(['[ANULADA ' . date('d/m/Y H:i') . ' por ' . $usuario . ': ' . $sustento . '] ', $id]);
        } catch (Throwable $e) { /* la calculadora no tiene la tabla: se ignora */ }
        $msg = 'Solicitud anulada. La entidad puede registrar una nueva solicitud para este cliente.';
    } elseif ($accion === 'reabrir') {
        $nuevo = $conErrores ? 'OBSERVADA' : 'PENDIENTE';
        $pdo->prepare('UPDATE sol_solicitudes SET estado=?, comentario=NULL, disponible=NULL, margen=NULL, evaluado_por=NULL, evaluado_en=NULL, auto_regla=FALSE, actualizado=NOW() WHERE id=?')
            ->execute([$nuevo, $id]);
        historial($id, 'REABIERTA', 'Vuelve a ' . $nuevo);
        $msg = 'Solicitud reabierta.';
    } else {
        exit('Acción no válida');
    }

    // Respuesta inmediata en el Sheet de la entidad y monto disponible actualizado en el PANEL
    try {
        $sync = new Sincronizador();
        $ok = $sync->escribirUna($id);
        $autos = 0;
        if (in_array($accion, ['aprobar', 'desaprobar'], true)) $autos = $sync->revalidarPendientes((string)$s['dni'], $id);
        if (in_array($accion, ['aprobar', 'desaprobar', 'reabrir', 'anular'], true)) Panel::publicarSilencioso();
        $quien = trim($s['apellidos'] . ', ' . $s['nombres'], ', ') . ' (DNI ' . $s['dni'] . ') — ';
        flash(($accion === 'reabrir' ? '' : $quien) . $msg . ($ok ? ' La entidad ya lo ve en su Sheet.' : '')
              . ($autos ? ' Además, ' . $autos . ' solicitud(es) pendiente(s) de este DNI en otras entidades quedaron DESAPROBADAS porque exceden su nuevo monto disponible.' : ''), 'ok');
    } catch (Throwable $ex) {
        flash($msg . ' No se pudo escribir en el Sheet ahora (' . $ex->getMessage() . '); se reintentará en la próxima sincronización.', 'warn');
    }
    if ($accion === 'reabrir') { header("Location: solicitud.php?id=$id"); exit; }   // reabierta: se queda para evaluar
    // Decisión registrada: vuelve a la bandeja y resalta la solicitud
    $_SESSION['sol_hecho'] = $id;
    unset($_SESSION['sol_volver'][$id]);
    header('Location: ' . $volverA);
    exit;
}

/* ------------------------------- Datos ------------------------------- */
$ultEval = $pdo->prepare('SELECT * FROM sol_evaluaciones WHERE solicitud_id = ? ORDER BY id DESC LIMIT 1');
$ultEval->execute([$id]);
$ev = $ultEval->fetch();
$evPropia = (bool)$ev;
if (!empty($s['otic_recepcion']) && Sincronizador::hayOticVisto() && empty($s['otic_visto'])) {
    $pdo->prepare('UPDATE sol_solicitudes SET otic_visto = TRUE WHERE id = ?')->execute([$id]);
}
$prefillOrigen = null;
$original = null; $evalOriginal = null; $duplicadosPosteriores = [];
if (!empty($s['duplicado_de'])) {
    $st = $pdo->prepare('SELECT s.*, e.nombre AS entidad FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id WHERE s.id = ?');
    $st->execute([$s['duplicado_de']]);
    $original = $st->fetch();
    $st = $pdo->prepare('SELECT * FROM sol_evaluaciones WHERE solicitud_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$s['duplicado_de']]);
    $evalOriginal = $st->fetch() ?: null;
}
$st = $pdo->prepare("SELECT * FROM sol_solicitudes WHERE duplicado_de = ? ORDER BY recibido, fila");
$st->execute([$id]);
$duplicadosPosteriores = $st->fetchAll();

if (!$ev && $evalOriginal) {
    // Cliente ingresado otra vez por la misma entidad: se parte de los datos reales de la evaluación anterior
    $ev = $evalOriginal;
    $prefillOrigen = 'Boleta tomada de la evaluación de la solicitud #' . $original['id'] . ' (fila ' . $original['fila'] . ', mismo cliente y entidad), '
                   . 'decidida ' . $evalOriginal['resultado'] . ' el ' . fecha_txt($evalOriginal['creado']) . ' por ' . $evalOriginal['usuario'] . '.';
    $t = json_decode((string)$ev['terceros'], true) ?: [];
    foreach ($t as &$x) $x['libera'] = false;
    unset($x);
    $ev['terceros'] = json_encode($t);
}
if (!$ev && $s['dni']) {
    // Reutiliza la última boleta registrada para este DNI (otra solicitud)
    $st = $pdo->prepare('SELECT v.*, s2.periodo, e2.nombre AS entidad FROM sol_evaluaciones v JOIN sol_solicitudes s2 ON s2.id = v.solicitud_id
                         JOIN sol_entidades e2 ON e2.id = s2.entidad_id WHERE s2.dni = ? ORDER BY v.id DESC LIMIT 1');
    $st->execute([$s['dni']]);
    if ($prev = $st->fetch()) {
        $ev = $prev;
        $prefillOrigen = 'Datos de boleta tomados de la evaluación del ' . fecha_txt($prev['creado'], false) . ' (' . $prev['entidad'] . '). Verifícalos.';
        $t = json_decode((string)$ev['terceros'], true) ?: [];
        foreach ($t as &$x) $x['libera'] = false;
        $ev['terceros'] = json_encode($t);
    }
}
$terceros = $ev ? (json_decode((string)$ev['terceros'], true) ?: []) : [];
if (!$terceros) $terceros = [['concepto' => '', 'monto' => '', 'libera' => false]];
$catDesc = Evaluador::catalogo('descuentos');
$catEnt  = Evaluador::catalogo('entidades');
$ley = $ev ? (json_decode((string)($ev['ley'] ?? ''), true) ?: []) : [];
if (!$ley && $ev && (float)$ev['descuentos_ley'] > 0) $ley = [['concepto' => 'Descuentos de ley', 'monto' => $ev['descuentos_ley']]];
if (!$ley) $ley = array_map(fn($n) => ['concepto' => $n, 'monto' => ''], array_slice($catDesc, 0, 4)) ?: [['concepto' => '', 'monto' => '']];

$aprobSistema = Evaluador::aprobadasSistema((string)$s['dni'], (string)$s['periodo'], $id);

// v1.8 · Tipo de trabajador: Docente y/o cesante (solo estado + monto) o Administrativo (cálculo con boleta)
$modoSel = $ev['modo'] ?? '';
if ($modoSel === '' && $s['condicion'] === 'C') $modoSel = 'DOCENTE';
$modoEnt = $s['tipo_trabajador'] ?? null;            // v1.15: lo marca la entidad → se aplica solo y no se cambia aquí
if ($modoEnt) $modoSel = $modoEnt;
if (($ev['modo'] ?? '') === 'DOCENTE') {          // una evaluación docente no trae boleta
    $ley = []; $terceros = [['concepto' => '', 'monto' => '', 'libera' => false]];
}
$dispDoc = ($evPropia && ($ev['modo'] ?? '') === 'DOCENTE') ? ($ev['disponible'] ?? '') : '';
$remuPrefill = (($ev['modo'] ?? '') === 'DOCENTE' || !$ev) ? '' : $ev['remuneracion'];

// Descuentos de ley: un botón por concepto del catálogo; los que no están en el catálogo van como "otros"
$leyMap = [];
foreach ($ley as $d) if ((float)($d['monto'] ?? 0) > 0) $leyMap[mb_strtoupper(trim((string)$d['concepto']), 'UTF-8')] = $d['monto'];
$leyTiles = [];
foreach ($catDesc as $c) {
    $k = mb_strtoupper(trim($c), 'UTF-8');
    $leyTiles[] = ['concepto' => $c, 'monto' => $leyMap[$k] ?? '', 'on' => isset($leyMap[$k]), 'excl' => Evaluador::grupoExclusivo($c)];
    unset($leyMap[$k]);
}
$leyOtros = [];
foreach ($ley as $d) {
    $k = mb_strtoupper(trim((string)$d['concepto']), 'UTF-8');
    if (isset($leyMap[$k])) { $leyOtros[] = $d; unset($leyMap[$k]); }
}

// Regla del monto disponible (última evaluación de la UGEL para este DNI en cualquier entidad)
$ud = Evaluador::ultimoDisponible((string)$s['dni'], $id);
$udExcede = $ud && (float)$s['cuota'] > (float)$ud['libre'] + 0.001;

$otras = $pdo->prepare('SELECT s.id, s.periodo, s.cuota, s.estado, s.tipo_producto, s.recibido, e.nombre AS entidad
                        FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id
                        WHERE s.dni = ? AND s.id <> ? ORDER BY s.recibido DESC LIMIT 12');
$otras->execute([$s['dni'], $id]);
$otras = $otras->fetchAll();

$hist = $pdo->prepare('SELECT * FROM sol_historial WHERE solicitud_id = ? ORDER BY id DESC');
$hist->execute([$id]);
$hist = $hist->fetchAll();

$evals = $pdo->prepare('SELECT * FROM sol_evaluaciones WHERE solicitud_id = ? ORDER BY id DESC');
$evals->execute([$id]);
$evals = $evals->fetchAll();

$esCompra = in_array($s['tipo_producto'], cfg('tipos_compra'), true);
$esAmpl   = str_starts_with((string)$s['tipo_producto'], 'AMPLIACION');
$evaluada = in_array($s['estado'], ['APROBADO', 'DESAPROBADO'], true);
$pct = (float)cfg('evaluacion.porcentaje_tope', 50);

$titulo = trim($s['apellidos'] . ', ' . $s['nombres'], ', ');
$activo = 'bandeja';
$subtitulo = chip_estado($s['estado']) . ' <span class="mono">DNI ' . e($s['dni']) . '</span> · ' . (!empty($s['entidad_cod']) ? '<span class="cod-ent">' . e($s['entidad_cod']) . '</span> ' : '') . e($s['entidad']) . ' · fila ' . (int)$s['fila'] . ' del Sheet'
           . (!$s['en_hoja'] ? ' · <span class="t-no">ya no está en el Sheet</span>' : '');
$acciones = '<a class="btn btn-ghost" href="' . e($volverA) . '">← Bandeja</a>'
          . '<a class="btn" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/' . e($s['spreadsheet_id']) . '/edit">Abrir Sheet</a>';
require __DIR__ . '/partials/cabecera.php';
?>
<?php if ($original): ?>
<section class="alerta-dup">
  <div class="ad-ico">⚠</div>
  <div class="ad-txt">
    <b>Posible duplicado: este cliente ya fue ingresado por <?= e($s['entidad']) ?></b>
    <p>El DNI <span class="mono"><?= e($s['dni']) ?></span> ya está en la <b>fila <?= (int)$original['fila'] ?></b> del mismo Sheet<?php if (!empty($original['registrado_por'])): ?>,
      registrada por <b><?= e($original['registrado_por']) ?></b><?php endif; ?>,
      recibida el <?= e(fecha_txt($original['recibido'])) ?> · cuota <?= soles($original['cuota']) ?> · periodo <?= e(periodo_txt($original['periodo'])) ?> ·
      estado <?= chip_estado($original['estado']) ?>.</p>
    <?php if ($evalOriginal): ?>
      <div class="ad-eval">
        <span>Última evaluación de ese ingreso</span>
        <div><small>Sueldo neto</small><b><?= soles($evalOriginal['base']) ?></b></div>
        <div><small>Capacidad 50%</small><b><?= soles($evalOriginal['tope']) ?></b></div>
        <div><small>Disponible</small><b><?= soles($evalOriginal['disponible']) ?></b></div>
        <div><small>Decisión</small><b><?= chip_estado($evalOriginal['resultado']) ?></b></div>
        <div><small>Por</small><b class="xs"><?= e($evalOriginal['usuario']) ?> · <?= e(fecha_txt($evalOriginal['creado'])) ?></b></div>
      </div>
      <p class="xs">Sus datos de boleta ya están cargados en la evaluación<?= $original['estado'] === 'APROBADO' && $original['periodo'] === $s['periodo'] ? ' y su cuota aprobada se suma como compromiso' : '' ?>.</p>
    <?php else: ?>
      <p class="xs">Ese ingreso aún no ha sido evaluado.</p>
    <?php endif; ?>
  </div>
  <div class="ad-act">
    <a class="btn btn-sm" href="solicitud.php?id=<?= (int)$original['id'] ?>">Ver fila <?= (int)$original['fila'] ?></a>
    <?php if (in_array($s['estado'], ['PENDIENTE', 'OBSERVADA'], true)): ?>
    <form method="post" class="inline"><?= csrf_campo() ?>
      <button class="btn btn-sm btn-dup" name="accion" value="duplicada">Marcar como duplicada</button></form>
    <?php endif; ?>
  </div>
</section>
<?php elseif ($duplicadosPosteriores): ?>
<section class="alerta-dup alerta-dup-info">
  <div class="ad-ico">ℹ</div>
  <div class="ad-txt"><b>Este cliente fue ingresado otra vez por la misma entidad</b>
    <p><?php foreach ($duplicadosPosteriores as $d): ?><a href="solicitud.php?id=<?= (int)$d['id'] ?>">Fila <?= (int)$d['fila'] ?></a> (<?= !empty($d['registrado_por']) ? e($d['registrado_por']) . ', ' : '' ?><?= e(fecha_txt($d['recibido'])) ?>, <?= e(ESTADOS[$d['estado']][0] ?? $d['estado']) ?>) &nbsp; <?php endforeach; ?></p></div>
</section>
<?php endif; ?>
<div class="grid-detail">
  <div class="col">
    <section class="card">
      <div class="card-h"><h2>Datos enviados por la entidad</h2><span class="muted xs">Recibido <?= e(fecha_txt($s['recibido'])) ?></span></div>
      <dl class="dl">
        <div><dt>Cód. modular</dt><dd class="mono"><?= e($s['cod_modular']) ?></dd></div>
        <div><dt>Cód. cargo</dt><dd class="mono"><?= e($s['cod_cargo']) ?></dd></div>
        <div><dt>Condición</dt><dd><?= $s['condicion'] === 'C' ? 'Cesante' : ($s['condicion'] === 'A' ? 'Activo' : e($s['condicion'])) ?></dd></div>
        <div><dt>Tipo de trabajador</dt><dd><?= !empty($s['tipo_trabajador']) ? '<span class="tt-tag tt-' . strtolower($s['tipo_trabajador']) . '">' . ($s['tipo_trabajador'] === 'DOCENTE' ? 'Docente y/o cesante' : 'Administrativo') . '</span>' : '<span class="muted">— no indicado</span>' ?></dd></div>
        <div><dt>Periodo</dt><dd><?= e(periodo_txt($s['periodo'])) ?> <span class="muted mono xs"><?= e($s['periodo']) ?></span></dd></div>
        <div><dt>Tipo de producto</dt><dd><?= e($s['tipo_producto']) ?></dd></div>
        <div><dt>Grupo de descuento</dt><dd><?= e($s['grupo_descuento']) ?></dd></div>
        <div><dt>Compra de deuda a</dt><dd><?= e($s['entidad_compra'] ?: '—') ?></dd></div>
        <?php if (Sincronizador::oticAplica($s, $ev['modo'] ?? null)): ?>
        <div><dt>Recepción del OTIC</dt><dd><?= !empty($s['otic_recepcion']) ? '<span class="otic-si">✓ Recibido</span><div class="muted xs">' . e($s['otic_recepcion']) . '</div>' : '<span class="muted">Aún no confirmada por la entidad</span>' ?></dd></div>
        <?php endif; ?>
        <div><dt>Registrado por</dt><dd><?= !empty($s['registrado_por']) ? e($s['registrado_por']) : '<span class="muted">— (fila escrita directo en el Sheet)</span>' ?><?php if (!empty($s['registrado_fecha'])): ?><div class="muted xs"><?= e($s['registrado_fecha']) ?></div><?php endif; ?></dd></div>
        <?php if (Padron::existe()):
            $pad = Padron::buscar((string)$s['dni']);
            if ($pad) {
                $dif = [];
                $n = fn($x) => preg_replace('/[^A-Z0-9Ñ]/u', '', Padron::norm((string)$x));
                if ($n($pad['apellido_paterno'] . $pad['apellido_materno']) !== $n($s['apellidos'])) $dif[] = 'apellidos: ' . trim($pad['apellido_paterno'] . ' ' . $pad['apellido_materno']);
                if ($n($pad['nombres']) !== $n($s['nombres'])) $dif[] = 'nombres: ' . $pad['nombres'];
                if ($pad['cod_modular'] && $pad['cod_modular'] !== (string)$s['cod_modular']) $dif[] = 'cód. modular: ' . $pad['cod_modular'];
            } ?>
        <div><dt>Padrón UGEL</dt><dd><?php if (!$pad): ?><span class="muted">El DNI no figura en el padrón</span>
          <?php elseif (!$dif): ?><span class="otic-si">✓ Coincide</span><?php if (!$pad['cod_modular']): ?><div class="muted xs">sin código modular en el padrón</div><?php endif; ?>
          <?php else: ?><span class="txt-no">⚠ Difiere del padrón</span><div class="xs muted"><?= e(implode(' · ', $dif)) ?></div><?php endif; ?></dd></div>
        <?php endif; ?>
        <div class="dl-big"><dt>Cuota mensual solicitada</dt><dd class="num"><?= soles($s['cuota']) ?></dd></div>
      </dl>
    </section>

    <section class="card">
      <div class="card-h"><h2>Filtros automáticos</h2></div>
      <?php if (!$obs): ?>
        <p class="check-ok">Todos los datos pasaron las validaciones.</p>
      <?php else: ?>
        <ul class="checks"><?php foreach ($obs as $o): ?>
          <li class="<?= $o['nivel'] === 'error' ? 'c-err' : 'c-warn' ?>"><?= e($o['msg']) ?></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($s['comentario']): ?><div class="note"><b>Comentario de Informática:</b> <?= e($s['comentario']) ?></div><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>Otras solicitudes del mismo DNI</h2></div>
      <?php if ($otras): ?>
      <table class="tbl"><tbody>
        <?php foreach ($otras as $o): ?>
          <tr class="rowlink" data-href="solicitud.php?id=<?= (int)$o['id'] ?>">
            <td><?= e($o['entidad']) ?><div class="muted xs"><?= e($o['tipo_producto']) ?></div></td>
            <td class="nowrap"><?= e(periodo_txt($o['periodo'])) ?><?= $o['periodo'] === $s['periodo'] ? ' <span class="tag tag-warn">mismo</span>' : '' ?></td>
            <td class="r num"><?= soles($o['cuota']) ?></td><td><?= chip_estado($o['estado']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php else: ?><p class="empty">No tiene otras solicitudes registradas.</p><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-h"><h2>Historial</h2></div>
      <ul class="timeline">
        <?php foreach ($hist as $h): ?>
          <li><span class="tl-a"><?= e($h['accion']) ?></span> <span class="muted xs"><?= e(fecha_txt($h['creado'])) ?> · <?= e($h['usuario']) ?></span>
            <?php if ($h['detalle']): ?><div class="xs"><?= e($h['detalle']) ?></div><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div class="col">
    <section class="card eval eval3d" id="evaluacion">
      <div class="eval-head">
        <div><span class="eyebrow">Evaluación de disponibilidad</span>
          <h2 id="evalTitulo" data-admin="Regla del <?= rtrim(rtrim(number_format($pct, 2), '0'), '.') ?>%" data-doc="Monto disponible"><?= $modoSel === 'DOCENTE' ? 'Monto disponible' : 'Regla del ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%' ?></h2>
          <?php if ($evaluada): ?><span class="muted xs">Última decisión: <?= e($s['evaluado_por']) ?> · <?= e(fecha_txt($s['evaluado_en'])) ?></span><?php endif; ?></div>
        <div class="eval-cuota"><small>Cuota solicitada</small><b class="num"><?= soles($s['cuota']) ?></b><span class="xs"><?= e(periodo_txt($s['periodo'])) ?></span></div>
      </div>

      <?php if ($conErrores): ?>
        <div class="note note-warn">No se puede aprobar ni desaprobar: la entidad debe corregir los errores de datos. Al sincronizar, la solicitud vuelve sola a <b>Por evaluar</b>.</div>
      <?php endif; ?>
      <?php if (!empty($s['auto_regla'])): ?>
        <div class="regla regla-no"><b>⛔ Desaprobada automáticamente</b><span><?= e($s['comentario']) ?> Si corresponde, usa <b>Reabrir</b> y evalúala.</span></div>
      <?php elseif ($ud): ?>
        <div class="regla <?= $udExcede ? 'regla-no' : 'regla-info' ?>">
          <b><?= $udExcede ? '⛔ La cuota excede el monto disponible' : 'Monto disponible según la última evaluación' ?></b>
          <span><span class="num strong"><?= soles($ud['libre']) ?></span> disponible · <?= e(($ud['codigo'] ? $ud['codigo'] . ' · ' : '') . $ud['entidad']) ?>
            · <?= e(fecha_hoja($ud['creado'])) ?> · <?= chip_estado($ud['resultado']) ?> <a href="solicitud.php?id=<?= (int)$ud['solicitud_id'] ?>">ver</a>
            <?= $udExcede ? '<br>Esta solicitud pide ' . soles($s['cuota']) . ': por la regla del monto disponible corresponde <b>DESAPROBAR</b>.' : '' ?></span>
        </div>
      <?php endif; ?>
      <?php if ($prefillOrigen): ?><div class="note"><?= e($prefillOrigen) ?></div><?php endif; ?>

      <?php if ($cerrada): ?>
        <div class="eval-cerrada-aviso"><span class="ec-ico">🔒</span><div><b>Evaluación cerrada · <?= chip_estado($s['estado']) ?></b>
          <span><?= $s['evaluado_por'] ? 'Decidida por ' . e($s['evaluado_por']) . ' el ' . e(fecha_txt($s['evaluado_en'])) . '. ' : '' ?>Las opciones están bloqueadas para no registrar otra evaluación.
          <?= $s['estado'] === 'OBSERVADA' ? 'Cuando la entidad corrija los datos, volverá sola a <b>Por evaluar</b>.' : '' ?></span></div></div>
      <?php endif; ?>
      <form method="post" id="fEval" class="<?= $cerrada ? 'eval-cerrada' : '' ?>" data-pct="<?= $pct ?>" data-cuota="<?= (float)$s['cuota'] ?>" data-aprob="<?= $aprobSistema ?>">
        <?= csrf_campo() ?>
        <fieldset class="fs-eval" <?= $cerrada ? 'disabled' : '' ?>>

        <div class="step"><span class="step-n">1</span><div><b>Tipo de trabajador</b><small><?= $modoEnt ? 'Marcado por la entidad en el portal' : 'Define qué datos necesitas para evaluar' ?></small></div></div>
        <?php if (!Sincronizador::hayTipoTrab()): ?><div class="note note-warn">⚠ <b>Falta actualizar la base de datos (v1.15).</b> Ejecute <code>sql/001_solicitudes.sql</code> en pgAdmin y pulse <b>Sincronizar Sheets</b>: desde entonces el tipo de trabajador que marca la entidad se aplicará solo.</div>
        <?php elseif (!$modoEnt && !$cerrada): ?><div class="modo-ent modo-ent-no">ℹ La entidad no indicó el tipo de trabajador (solicitud registrada antes de la actualización del portal o escrita directo en el Sheet). Elígelo aquí.</div><?php endif; ?>
        <?php if ($modoEnt): ?><div class="modo-ent">🔗 La entidad indicó <b><?= $modoEnt === 'DOCENTE' ? 'Docente y/o cesante' : 'Administrativo' ?></b>: la evaluación se habilitó sola. Si no corresponde, usa <b>Observar</b>.</div><?php endif; ?>
        <div class="modo-sel<?= $modoEnt ? ' modo-fijo' : '' ?>" role="radiogroup">
          <label class="modo-op<?= $modoEnt && $modoEnt !== 'DOCENTE' ? ' modo-bloq' : '' ?>" data-m="DOCENTE">
            <input type="radio" name="modo" value="DOCENTE" <?= $modoSel === 'DOCENTE' ? 'checked' : '' ?> <?= $modoEnt && $modoEnt !== 'DOCENTE' ? 'disabled' : '' ?>>
            <span class="mo-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg></span>
            <span class="mo-txt"><b>Docente y/o cesante</b><small>Solo estado y monto disponible</small></span><span class="mo-check"></span>
          </label>
          <label class="modo-op<?= $modoEnt && $modoEnt !== 'ADMINISTRATIVO' ? ' modo-bloq' : '' ?>" data-m="ADMINISTRATIVO">
            <input type="radio" name="modo" value="ADMINISTRATIVO" <?= $modoSel === 'ADMINISTRATIVO' ? 'checked' : '' ?> <?= $modoEnt && $modoEnt !== 'ADMINISTRATIVO' ? 'disabled' : '' ?>>
            <span class="mo-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/></svg></span>
            <span class="mo-txt"><b>Administrativo</b><small>Cálculo completo con la boleta</small></span><span class="mo-check"></span>
          </label>
        </div>

        <!-- ============ DOCENTE Y/O CESANTE ============ -->
        <fieldset class="modo-panel" data-panel="DOCENTE" <?= $modoSel === 'DOCENTE' ? '' : 'disabled hidden' ?>>
          <div class="step"><span class="step-n">2</span><div><b>Monto disponible</b><small>Hasta cuánto se le puede descontar al trabajador (antes de esta cuota)</small></div></div>
          <div class="doc-box">
            <label class="big-money"><span>S/</span><input type="number" step="0.01" min="0" name="disponible_doc" id="dispDoc" value="<?= e($dispDoc) ?>" placeholder="0.00"></label>
            <div class="doc-help">
              <p><b>Obligatorio para aprobar.</b> Opcional si desapruebas.</p>
              <p class="doc-queda" id="dispQueda" data-cuota="<?= e((float)$s['cuota']) ?>"></p>
              <p class="xs muted">Al aprobar, a este trabajador le queda <b>monto disponible − cuota</b>. Si otra entidad pide más que eso, el portal no le dejará registrar la solicitud.</p>
              <?php if ($ud): ?><button type="button" class="btn btn-sm" data-usar-disp="<?= e($ud['libre']) ?>">Usar <?= soles($ud['libre']) ?> (última evaluación)</button><?php endif; ?>
            </div>
          </div>
        </fieldset>

        <!-- ============ ADMINISTRATIVO ============ -->
        <fieldset class="modo-panel" data-panel="ADMINISTRATIVO" <?= $modoSel === 'ADMINISTRATIVO' ? '' : 'disabled hidden' ?>>
          <div class="step"><span class="step-n">2</span><div><b>Boleta</b><small>Sueldo bruto del mes</small></div></div>
          <label class="big-money"><span>S/</span><input type="number" step="0.01" min="0" name="remuneracion" class="calc" required value="<?= e($remuPrefill) ?>" placeholder="0.00"></label>

          <div class="step"><span class="step-n">3</span><div><b>Descuentos de ley</b><small>Toca un concepto para activarlo. AFP y ONP se excluyen entre sí.</small></div></div>
          <div class="ley-grid" id="leyBox">
            <?php foreach ($leyTiles as $i => $t): ?>
            <div class="ley-tile<?= $t['on'] ? ' on' : '' ?>" data-excl="<?= e($t['excl']) ?>" data-concepto="<?= e($t['concepto']) ?>">
              <button type="button" class="lt-btn"><span class="lt-dot"></span><span class="lt-name"><?= e($t['concepto']) ?></span><?php if ($t['excl']): ?><span class="lt-tag">Pensión</span><?php endif; ?></button>
              <div class="lt-amt"><span>S/</span><input type="number" step="0.01" min="0" class="calc ley-m" name="d[<?= $i ?>][monto]" value="<?= e($t['monto']) ?>" placeholder="0.00" <?= $t['on'] ? '' : 'disabled' ?>></div>
              <input type="hidden" name="d[<?= $i ?>][concepto]" value="<?= e($t['concepto']) ?>" <?= $t['on'] ? '' : 'disabled' ?>>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="leyLista" class="otros">
            <?php foreach ($leyOtros as $i => $d): ?>
            <div class="terc-row ley-row">
              <input type="text" name="d[<?= 500 + $i ?>][concepto]" value="<?= e($d['concepto'] ?? '') ?>" placeholder="Otro descuento de ley">
              <input type="number" step="0.01" min="0" name="d[<?= 500 + $i ?>][monto]" class="calc money ley-m" value="<?= e($d['monto'] ?? '') ?>" placeholder="0.00">
              <button type="button" class="icon-btn" data-quitar title="Quitar">×</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-sm btn-ghost" data-agregar="ley">+ Otro descuento de ley</button>
          <div class="sum-line"><span>Total descuentos de ley</span><b class="num" id="sumLey">S/ 0.00</b></div>

          <div class="step"><span class="step-n">4</span><div><b>Créditos vigentes</b><small>Bancos, cajas y cooperativas que ya descuentan en la boleta. Marca <b>Se libera</b> la deuda que <?= $esCompra ? 'se compra' : ($esAmpl ? 'se amplía' : 'desaparece con este crédito') ?>.</small></div></div>
          <?php if ($catEnt): ?><div class="chips-cat" id="chipsTerc"><?php foreach ($catEnt as $c): ?><button type="button" class="chip-cat" data-add="terc" data-nombre="<?= e($c) ?>">+ <?= e($c) ?></button><?php endforeach; ?></div><?php endif; ?>
          <div id="tercLista" class="otros">
            <?php foreach ($terceros as $i => $t): ?>
            <div class="terc-row terc-row-t">
              <input type="text" name="t[<?= $i ?>][concepto]" value="<?= e($t['concepto'] ?? '') ?>" placeholder="Entidad / concepto">
              <input type="number" step="0.01" min="0" name="t[<?= $i ?>][monto]" class="calc money" value="<?= e($t['monto'] ?? '') ?>" placeholder="0.00">
              <label class="sw"><input type="checkbox" name="t[<?= $i ?>][libera]" value="1" class="calc" <?= !empty($t['libera']) ? 'checked' : '' ?>><i></i>Se libera</label>
              <button type="button" class="icon-btn" data-quitar title="Quitar">×</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-sm btn-ghost" data-agregar="terc">+ Agregar crédito vigente</button>

          <label class="chk chk-line"><input type="checkbox" name="considerar_aprobadas" value="1" class="calc" <?= cfg('evaluacion.considerar_aprobadas', true) ? 'checked' : '' ?>>
            Sumar cuotas ya aprobadas en este sistema para el mismo DNI y periodo (<?= soles($aprobSistema) ?>)</label>

          <div class="step"><span class="step-n">5</span><div><b>Resultado del cálculo</b><small>Referencia: la decisión es tuya</small></div></div>
          <div class="result" id="resultado">
            <div class="res-3d">
              <div class="cyl" id="cyl" style="--h:0%">
                <div class="cyl-body"><div class="cyl-fill"><i class="cyl-cap"></i></div><i class="cyl-line"></i></div>
                <i class="cyl-top"></i><i class="cyl-base"></i>
                <span class="cyl-pct" id="cylPct">0%</span>
              </div>
              <div class="res-main">
                <div class="res-head"><span class="res-badge" id="rBadge">—</span><span class="res-margen" id="rMargen"></span></div>
                <div class="gauge"><span class="g-comp" id="gComp"></span><span class="g-cuota" id="gCuota"></span><span class="g-over" id="gOver"></span></div>
                <div class="gauge-l"><span><i class="g-comp"></i>Comprometido</span><span><i class="g-cuota"></i>Cuota solicitada</span><span id="rUso"></span></div>
                <dl class="calc-dl">
                  <div><dt>Sueldo bruto</dt><dd id="cRem">—</dd></div>
                  <div><dt>(−) Descuentos de ley</dt><dd id="cLey">—</dd></div>
                  <div class="sum"><dt>= Sueldo neto total</dt><dd id="cBase">—</dd></div>
                  <div class="sum"><dt>Capacidad máxima (<?= $pct ?>% del neto)</dt><dd id="cTope">—</dd></div>
                  <div><dt>(−) Créditos vigentes</dt><dd id="cTerc">—</dd></div>
                  <div><dt>(−) Aprobadas en este módulo</dt><dd id="cAprob">—</dd></div>
                  <div><dt>(−) Cuota solicitada</dt><dd><?= soles($s['cuota']) ?></dd></div>
                  <div class="muted"><dt>Liberado por compra/ampliación</dt><dd id="cLib">—</dd></div>
                  <div class="sum big"><dt>= Monto disponible</dt><dd id="cDisp">—</dd></div>
                </dl>
              </div>
            </div>
          </div>
        </fieldset>

        <div class="modo-vacio" id="modoVacio" <?= $modoSel ? 'hidden' : '' ?>>Elige el <b>tipo de trabajador</b> para continuar.</div>

        <div class="step"><span class="step-n" id="decN"><?= $modoSel === 'ADMINISTRATIVO' ? 6 : 3 ?></span><div><b>Estado</b><small>La entidad verá la respuesta en su Sheet y en el portal</small></div></div>
        <label class="full"><span>Motivo o mensaje para la entidad <small>(obligatorio para desaprobar u observar · al aprobar, por ejemplo, el aviso del OTIC)</small></span>
          <div class="msg-chips" id="msgChips">
            <button type="button" class="chip-cat" data-msg="Se le envió el OTIC por correo electrónico." data-solo="DOCENTE" title="Solo docentes y cesantes reciben OTIC">✉ OTIC enviado por correo</button>
            <button type="button" class="chip-cat" data-msg="La cuota excede el 50% del sueldo neto.">Excede el 50%</button>
            <button type="button" class="chip-cat" data-msg="Los datos no coinciden con la planilla. Verifique y corrija.">Datos no coinciden</button>
          </div>
          <textarea name="comentario" id="txtComentario" rows="2" placeholder="Ej.: Se le envió el OTIC por correo electrónico."><?= e(!empty($s['auto_regla']) ? '' : ($s['comentario'] ?? '')) ?></textarea></label>

        </fieldset>
        <div class="decision">
          <div class="dec-btns">
            <button class="dec dec-ok" id="bAprobar" name="accion" value="aprobar" <?= $conErrores || $cerrada ? 'disabled data-bloq' : '' ?>>
              <span class="dec-i">✓</span><span><b>Aprobado</b><small>Mensaje opcional</small></span></button>
            <button class="dec dec-no" id="bDesaprobar" name="accion" value="desaprobar" <?= $conErrores || $cerrada ? 'disabled data-bloq' : '' ?>>
              <span class="dec-i">✕</span><span><b>Desaprobado</b><small>Requiere motivo</small></span></button>
            <button class="dec dec-obs" name="accion" value="observar" formnovalidate <?= $cerrada ? 'disabled' : '' ?>>
              <span class="dec-i">!</span><span><b>Observar</b><small>Pedir corrección</small></span></button>
          </div>
          <div class="dec-aviso" id="decAviso" hidden></div>
          <?php if ($cerrada): ?>
            <div class="dec-foot"><span class="muted xs">Estado actual: <?= chip_estado($s['estado']) ?></span>
              <?php if (es_admin() && $s['estado'] !== 'RETIRADA'): ?>
              <button class="btn btn-sm btn-ghost" name="accion" value="reabrir" formnovalidate
                      onclick="return confirm('¿Reabrir esta solicitud?\n\nVolverá a Por evaluar y se podrá registrar una nueva decisión. Úselo solo para corregir un error.')">🔓 Reabrir (solo administrador)</button>
              <?php endif; ?></div>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <?php if (in_array($s['estado'], ['PENDIENTE', 'OBSERVADA', 'APROBADO', 'DESAPROBADO'], true)): ?>
    <details class="card anular-card">
      <summary><span class="an-ico">🚫</span><div><b>Anular solicitud</b><small>Solo si la entidad lo pide (p. ej. el cliente ahora quiere otro monto). Deja de reservar presupuesto y la entidad podrá registrar una nueva.</small></div></summary>
      <form method="post" class="anular-form" onsubmit="return confirm('¿Anular esta solicitud?\n\nQuedará como ANULADA en el Sheet de la entidad y en el historial. Esta acción no se deshace (solo un administrador puede reabrirla).')">
        <?= csrf_campo() ?>
        <label><span>Sustento de la anulación <b class="t-no">*</b></span>
          <textarea name="sustento" rows="3" minlength="10" required placeholder="Ej.: La entidad informa que la clienta solicita un monto mayor; se anula para que registren una nueva solicitud."></textarea></label>
        <div class="an-act"><button class="btn btn-danger" name="accion" value="anular">Anular solicitud</button></div>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($evals): ?>
    <section class="card">
      <div class="card-h"><h2>Evaluaciones anteriores</h2></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Fecha</th><th>Tipo</th><th class="r">Tope 50%</th><th class="r">Le queda</th><th>Decisión</th><th>Cálculo</th><th>Por</th></tr></thead><tbody>
      <?php foreach ($evals as $v): $doc = ($v['modo'] ?? '') === 'DOCENTE'; ?>
        <tr><td class="xs"><?= e(fecha_txt($v['creado'])) ?></td><td class="xs"><?= $doc ? 'Docente/cesante' : 'Administrativo' ?></td>
            <td class="r num"><?= $doc ? '—' : soles($v['tope']) ?></td><td class="r num"><?= isset($v['disponible_libre']) && $v['disponible_libre'] !== null ? soles($v['disponible_libre']) : '—' ?></td>
            <td><?= chip_estado($v['resultado']) ?></td>
            <td class="xs <?= ($v['resultado_calculo'] ?? $v['resultado']) !== $v['resultado'] ? 't-obs' : 'muted' ?>"><?= $doc ? '—' : e($v['resultado_calculo'] ?? $v['resultado']) ?></td>
            <td class="xs"><?= e($v['usuario']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
  </div>
</div>
<template id="tplLey">
  <div class="terc-row ley-row">
    <input type="text" name="d[__i__][concepto]" placeholder="Otro descuento de ley">
    <input type="number" step="0.01" min="0" name="d[__i__][monto]" class="calc money ley-m" placeholder="0.00">
    <button type="button" class="icon-btn" data-quitar title="Quitar">×</button>
  </div>
</template>
<template id="tplTerc">
  <div class="terc-row terc-row-t">
    <input type="text" name="t[__i__][concepto]" placeholder="Entidad / concepto">
    <input type="number" step="0.01" min="0" name="t[__i__][monto]" class="calc money" placeholder="0.00">
    <label class="sw"><input type="checkbox" name="t[__i__][libera]" value="1" class="calc"><i></i>Se libera</label>
    <button type="button" class="icon-btn" data-quitar title="Quitar">×</button>
  </div>
</template>
<?php require __DIR__ . '/partials/pie.php';
