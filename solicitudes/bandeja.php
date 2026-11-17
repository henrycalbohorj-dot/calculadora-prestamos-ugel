<?php
require __DIR__ . '/bootstrap.php';
requerir_login();
$pdo = db();
$hecho = (int)($_SESSION['sol_hecho'] ?? 0); unset($_SESSION['sol_hecho']);   // recién evaluada: se resalta

// OTIC confirmados por las entidades: marcar como revisados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'otic_vistos' && Sincronizador::hayOticVisto()) {
    csrf_verificar();
    $n = $pdo->exec("UPDATE sol_solicitudes SET otic_visto = TRUE WHERE otic_recepcion IS NOT NULL AND NOT otic_visto");
    flash($n . ' confirmación(es) de OTIC marcadas como revisadas.', 'ok');
    header('Location: bandeja.php?otic=todos'); exit;
}
$verOtic = in_array($_GET['otic'] ?? '', ['nuevos', 'todos'], true);

[$where, $params] = Consultas::filtros($_GET);
$pag = max(1, (int)($_GET['p'] ?? 1));
$porPag = 50;

$orden = [
    'reciente' => 's.recibido DESC, s.id DESC',
    'antiguo'  => 's.recibido ASC, s.id ASC',
    'cuota'    => 's.cuota DESC NULLS LAST',
    'nombre'   => 's.apellidos, s.nombres',
][$_GET['orden'] ?? 'reciente'] ?? 's.recibido DESC';

$st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(s.cuota),0) FROM sol_solicitudes s WHERE $where");
$st->execute($params);
[$total, $sumCuota] = $st->fetch(PDO::FETCH_NUM);

$st = $pdo->prepare("SELECT s.*, e.nombre AS entidad, e.codigo AS entidad_cod,
        (SELECT COUNT(DISTINCT x.entidad_id) FROM sol_solicitudes x
          WHERE x.dni = s.dni AND x.periodo = s.periodo AND x.estado NOT IN ('RETIRADA','DESAPROBADO','DUPLICADA','ANULADA')) AS concurrencia,
        (SELECT o.fila FROM sol_solicitudes o WHERE o.id = s.duplicado_de) AS fila_original,
        (SELECT v.modo FROM sol_evaluaciones v WHERE v.solicitud_id = s.id ORDER BY v.id DESC LIMIT 1) AS modo_eval
    FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id
    WHERE $where ORDER BY $orden LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$st->execute($params);
$filas = $st->fetchAll();

$entidades = Consultas::entidades();
$periodos  = Consultas::periodos();
$g = fn($k) => (string)($_GET[$k] ?? '');
$qs = function (array $cambios) {
    $a = array_merge($_GET, $cambios);
    return '?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== null));
};

$titulo = $verOtic ? 'OTIC confirmados por las entidades' : 'Bandeja de solicitudes';
$activo = $verOtic ? 'otic' : 'bandeja';
$subtitulo = number_format((int)$total) . ' solicitud(es) · cuotas por ' . soles($sumCuota) . ' al mes';
$acciones = '<a class="btn" href="exportar.php' . e($qs(['p' => null, 'orden' => null])) . '">'
          . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>'
          . 'Exportar formato DRE</a>';
require __DIR__ . '/partials/cabecera.php';
?>
<div class="tabs">
  <?php
  [$wc, $pc] = Consultas::filtros(array_merge($_GET, ['estado' => '', 'dup' => '']));
  $stc = $pdo->prepare("SELECT s.estado, COUNT(*) FROM sol_solicitudes s WHERE $wc GROUP BY s.estado");
  $stc->execute($pc);
  $cnt = $stc->fetchAll(PDO::FETCH_KEY_PAIR);
  $cnt[''] = array_sum($cnt);
  foreach (['' => ['Todas', ''], 'PENDIENTE' => ['Por evaluar', 't-pend'], 'OBSERVADA' => ['Observadas', 't-obs'], 'APROBADO' => ['Aprobadas', 't-ok'], 'DESAPROBADO' => ['Desaprobadas', 't-no'], 'ANULADA' => ['Anuladas', 't-anu']] as $k => [$l, $cl]): ?>
    <a href="<?= e($qs(['estado' => $k, 'p' => null, 'dup' => null])) ?>" class="<?= $cl ?> <?= $g('estado') === $k && !$g('dup') ? 'on' : '' ?>"><?= $l ?> <span class="n"><?= (int)($cnt[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
  <?php $nDup = (int)$pdo->query("SELECT COUNT(*) FROM sol_solicitudes WHERE duplicado_de IS NOT NULL AND estado <> 'DUPLICADA'")->fetchColumn(); ?>
  <a href="<?= e($qs(['dup' => 1, 'estado' => null, 'p' => null])) ?>" class="tab-alerta <?= $g('dup') ? 'on' : '' ?>">⚠ Posibles duplicados <?php if ($nDup): ?><b><?= $nDup ?></b><?php endif; ?></a>
</div>

<?php if ($verOtic): ?>
<section class="otic-aviso card">
  <div><b><?= ($_GET['otic'] ?? '') === 'nuevos' ? 'Confirmaciones nuevas de recepción del OTIC' : 'Todas las confirmaciones de recepción del OTIC' ?></b>
    <span class="muted xs">Las entidades las marcan en el portal. Es solo informativo: esas solicitudes ya están aprobadas.</span></div>
  <div class="otic-acc">
    <a class="btn btn-sm <?= ($_GET['otic'] ?? '') === 'nuevos' ? 'btn-pri' : '' ?>" href="bandeja.php?otic=nuevos">Sin revisar</a>
    <a class="btn btn-sm <?= ($_GET['otic'] ?? '') === 'todos' ? 'btn-pri' : '' ?>" href="bandeja.php?otic=todos">Todas</a>
    <?php if ($oticNuevos ?? 0): ?><form method="post" class="inline"><?= csrf_campo() ?><button class="btn btn-sm" name="accion" value="otic_vistos">✓ Marcar todas como revisadas</button></form><?php endif; ?>
  </div>
</section>
<?php endif; ?>
<form class="filters card" method="get">
  <input type="hidden" name="otic" value="<?= e($g('otic')) ?>">
  <input type="hidden" name="estado" value="<?= e($g('estado')) ?>">
  <input type="hidden" name="dup" value="<?= e($g('dup')) ?>">
  <label class="f-q"><span>Buscar</span><input type="search" name="q" value="<?= e($g('q')) ?>" placeholder="DNI, apellidos, nombres o cód. modular" autofocus></label>
  <label class="f-cod"><span>Cód. entidad</span><input name="cod" inputmode="numeric" list="lstCod" value="<?= e($g('cod')) ?>" placeholder="Ej. 1754">
    <datalist id="lstCod"><?php foreach ($entidades as $en): if ($en['codigo']): ?><option value="<?= e($en['codigo']) ?>"><?= e($en['nombre']) ?></option><?php endif; endforeach; ?></datalist></label>
  <label><span>Entidad</span><select name="entidad"><option value="">Todas</option>
    <?php foreach ($entidades as $en): ?><option value="<?= $en['id'] ?>" <?= $g('entidad') == $en['id'] ? 'selected' : '' ?>><?= e(($en['codigo'] ? $en['codigo'] . ' · ' : '') . $en['nombre']) ?></option><?php endforeach; ?></select></label>
  <label><span>Grupo</span><select name="grupo"><option value="">Todos</option>
    <?php foreach (cfg('grupos') as $x): ?><option <?= $g('grupo') === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
  <label><span>Producto</span><select name="tipo"><option value="">Todos</option>
    <?php foreach (cfg('tipos_producto') as $x): ?><option <?= $g('tipo') === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
  <label><span>Periodo</span><select name="periodo"><option value="">Todos</option>
    <?php foreach ($periodos as $x): ?><option value="<?= e($x) ?>" <?= $g('periodo') === $x ? 'selected' : '' ?>><?= e(periodo_txt($x)) ?></option><?php endforeach; ?></select></label>
  <label><span>Recibido desde</span><input type="date" name="desde" value="<?= e($g('desde')) ?>"></label>
  <label><span>hasta</span><input type="date" name="hasta" value="<?= e($g('hasta')) ?>"></label>
  <label><span>Orden</span><select name="orden">
    <?php foreach (['reciente' => 'Más recientes', 'antiguo' => 'Más antiguas', 'cuota' => 'Mayor cuota', 'nombre' => 'Apellidos A-Z'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $g('orden') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <div class="f-act"><button class="btn btn-pri">Filtrar</button><a class="btn btn-ghost" href="bandeja.php">Limpiar</a></div>
</form>

<section class="card p0">
  <div class="tbl-wrap"><table class="tbl tbl-main">
    <thead><tr>
      <th>Trabajador</th><th>Entidad</th><th>Producto</th><th>Periodo</th><th class="r">Cuota</th><th>Estado</th><th>Recibido</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($filas as $s):
        $obs = json_decode((string)$s['observaciones'], true) ?: [];
        $nErr = count(array_filter($obs, fn($o) => $o['nivel'] === 'error'));
        $nAle = count($obs) - $nErr; ?>
      <tr class="rowlink<?= (int)$s['id'] === $hecho ? ' hecho' : '' ?>" data-href="solicitud.php?id=<?= (int)$s['id'] ?>">
        <td><div class="strong"><?= e($s['apellidos']) ?>, <?= e($s['nombres']) ?></div>
            <div class="muted xs mono">DNI <?= e($s['dni']) ?> · <?= e($s['condicion'] === 'C' ? 'Cesante' : 'Activo') ?> · Mod. <?= e($s['cod_modular']) ?></div>
            <?php if (!empty($s['tipo_trabajador'])): ?><span class="tt-tag tt-<?= strtolower($s['tipo_trabajador']) ?>"><?= $s['tipo_trabajador'] === 'DOCENTE' ? 'Docente / cesante' : 'Administrativo' ?></span><?php endif; ?>
            <?php if (!empty($s['registrado_por'])): ?><div class="xs reg-por" title="Registrado en el portal de entidades">✎ <?= e($s['registrado_por']) ?></div><?php endif; ?></td>
        <td><?php if ($s['entidad_cod']): ?><span class="cod-ent" title="Código de la entidad"><?= e($s['entidad_cod']) ?></span><?php endif; ?><?= e($s['entidad']) ?><?php if ($s['duplicado_de'] && $s['estado'] !== 'DUPLICADA'): ?><span class="tag tag-dup" title="Este DNI ya fue ingresado por la misma entidad">⚠ duplicado de fila <?= (int)$s['fila_original'] ?></span><?php endif; ?><?php if ($s['concurrencia'] > 1): ?><span class="tag tag-warn" title="Este DNI tiene solicitudes en <?= (int)$s['concurrencia'] ?> entidades para el mismo periodo">×<?= (int)$s['concurrencia'] ?> entidades</span><?php endif; ?></td>
        <td><span class="xs"><?= e($s['tipo_producto']) ?></span><div class="muted xs"><?= e($s['grupo_descuento']) ?></div></td>
        <td class="nowrap"><?= e(periodo_txt($s['periodo'])) ?></td>
        <td class="r num strong"><?= soles($s['cuota']) ?></td>
        <td><?= chip_estado($s['estado']) ?><?php if (!empty($s['auto_regla'])): ?><span class="tag tag-auto" title="Regla del monto disponible: la entidad ve POSIBLE DESAPROBACIÓN">posible desaprobación</span><?php endif; ?>
          <?php if ($nErr): ?><div class="xs t-obs"><?= $nErr ?> error(es)</div><?php elseif ($nAle): ?><div class="xs muted"><?= $nAle ?> alerta(s)</div><?php endif; ?>
          <?php if ($s['margen'] !== null && in_array($s['estado'], ['APROBADO', 'DESAPROBADO'], true)): ?>
            <div class="xs <?= $s['margen'] < 0 ? 't-no' : 'muted' ?>">margen <?= soles($s['margen']) ?></div><?php endif; ?>
          <?php if (Sincronizador::oticAplica($s, $s['modo_eval'] ?? null)): ?><?= !empty($s['otic_recepcion'])
                ? '<div class="otic-tag" title="' . e($s['otic_recepcion']) . '">✓ OTIC recibido</div>'
                : '<div class="otic-tag otic-no" title="La entidad aún no confirma la recepción del OTIC">OTIC sin confirmar</div>' ?><?php endif; ?></td>
        <td class="nowrap xs muted"><?= e(fecha_txt($s['recibido'])) ?></td>
        <td class="r"><a class="btn btn-sm <?= $s['estado'] === 'PENDIENTE' ? 'btn-pri' : '' ?>" href="solicitud.php?id=<?= (int)$s['id'] ?>"><?= $s['estado'] === 'PENDIENTE' ? 'Evaluar' : 'Ver' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$filas): ?><tr><td colspan="8" class="empty">No hay solicitudes con estos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php $paginas = (int)ceil($total / $porPag); if ($paginas > 1): ?>
  <div class="pager">
    <?php if ($pag > 1): ?><a class="btn btn-sm" href="<?= e($qs(['p' => $pag - 1])) ?>">← Anterior</a><?php endif; ?>
    <span class="muted">Página <?= $pag ?> de <?= $paginas ?></span>
    <?php if ($pag < $paginas): ?><a class="btn btn-sm" href="<?= e($qs(['p' => $pag + 1])) ?>">Siguiente →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/pie.php';
