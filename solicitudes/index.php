<?php
require __DIR__ . '/bootstrap.php';
requerir_login();
$pdo = db();

$periodo = preg_replace('/\D/', '', (string)($_GET['periodo'] ?? ''));
$wp = $periodo ? ' AND s.periodo = ' . $pdo->quote($periodo) : '';

/* ------------------------------ Indicadores ------------------------------ */
$k = $pdo->query("SELECT
    COUNT(*) FILTER (WHERE estado <> 'RETIRADA')                          AS total,
    COUNT(*) FILTER (WHERE estado = 'PENDIENTE')                          AS pend,
    COUNT(*) FILTER (WHERE estado = 'OBSERVADA')                          AS obs,
    COUNT(*) FILTER (WHERE estado = 'APROBADO')                           AS ok,
    COUNT(*) FILTER (WHERE estado = 'DESAPROBADO')                        AS no,
    COUNT(*) FILTER (WHERE estado = 'DUPLICADA')                          AS dupl,
    COUNT(*) FILTER (WHERE estado = 'ANULADA')                            AS anu,
    COUNT(*) FILTER (WHERE duplicado_de IS NOT NULL AND estado <> 'DUPLICADA') AS posdup,
    COALESCE(SUM(cuota) FILTER (WHERE estado = 'APROBADO'), 0)            AS monto_ok,
    COALESCE(SUM(cuota) FILTER (WHERE estado = 'PENDIENTE'), 0)           AS monto_pend,
    COUNT(*) FILTER (WHERE recibido >= CURRENT_DATE)                      AS hoy,
    COALESCE(ROUND((AVG(EXTRACT(EPOCH FROM (evaluado_en - recibido)) / 3600)
             FILTER (WHERE estado IN ('APROBADO','DESAPROBADO')))::numeric, 1), 0) AS horas
  FROM sol_solicitudes s WHERE 1=1 $wp")->fetch();
$evaluadas = $k['ok'] + $k['no'];
$tasa = $evaluadas ? round($k['ok'] / $evaluadas * 100) : 0;

/* ------------------------------ Series para gráficos ------------------------------ */
// 3D: últimos 6 periodos × 8 entidades con más solicitudes
$periodos3d = array_reverse($pdo->query("SELECT DISTINCT periodo FROM sol_solicitudes WHERE periodo ~ '^[0-9]{6}$' AND estado <> 'RETIRADA' ORDER BY periodo DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN));
$top = $pdo->query("SELECT e.id, e.nombre FROM sol_entidades e JOIN sol_solicitudes s ON s.entidad_id = e.id
                    WHERE s.estado <> 'RETIRADA' $wp GROUP BY e.id ORDER BY COUNT(*) DESC, e.nombre LIMIT 8")->fetchAll();
$celdas = [];
if ($periodos3d && $top) {
    $topIds = array_map(fn($t) => (int)$t['id'], $top);
    $in = implode(',', array_map(fn($p) => $pdo->quote($p), $periodos3d));
    foreach ($pdo->query("SELECT entidad_id, periodo, COUNT(*) n, COUNT(*) FILTER (WHERE estado='APROBADO') ok
                          FROM sol_solicitudes WHERE entidad_id IN (" . implode(',', $topIds) . ") AND periodo IN ($in) AND estado <> 'RETIRADA'
                          GROUP BY 1, 2") as $c) {
        $celdas[] = [array_search($c['periodo'], $periodos3d), array_search((int)$c['entidad_id'], $topIds), (int)$c['n'], (int)$c['ok']];
    }
}

// Tendencia diaria (últimos 30 días)
$dias = $pdo->query("WITH d AS (SELECT generate_series(CURRENT_DATE - 29, CURRENT_DATE, '1 day')::date AS dia)
    SELECT to_char(d.dia, 'DD/MM') AS dia,
      (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.recibido::date = d.dia $wp) AS recibidas,
      (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.evaluado_en::date = d.dia AND s.estado = 'APROBADO' $wp) AS aprobadas,
      (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.evaluado_en::date = d.dia AND s.estado = 'DESAPROBADO' $wp) AS desaprobadas,
      (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.evaluado_en::date = d.dia AND s.estado = 'ANULADA' $wp) AS anuladas
    FROM d ORDER BY d.dia")->fetchAll();

$porGrupo = $pdo->query("SELECT grupo_descuento AS g, COUNT(*) AS n, COALESCE(SUM(cuota) FILTER (WHERE estado='APROBADO'),0) AS monto
  FROM sol_solicitudes s WHERE estado <> 'RETIRADA' AND grupo_descuento <> '' $wp GROUP BY 1 ORDER BY 2 DESC")->fetchAll();

$topMonto = $pdo->query("SELECT e.nombre, COALESCE(SUM(s.cuota),0) AS monto, COUNT(*) AS n
  FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id WHERE s.estado = 'APROBADO' $wp
  GROUP BY e.nombre ORDER BY monto DESC LIMIT 8")->fetchAll();

/* ------------------------------ Tablas y alertas ------------------------------ */
$porEnt = $pdo->query("SELECT e.id, e.nombre,
    COUNT(s.id) FILTER (WHERE s.estado <> 'RETIRADA') AS total,
    COUNT(s.id) FILTER (WHERE s.estado = 'PENDIENTE')   AS pend,
    COUNT(s.id) FILTER (WHERE s.estado = 'APROBADO')    AS ok,
    COUNT(s.id) FILTER (WHERE s.estado = 'DESAPROBADO') AS no,
    COUNT(s.id) FILTER (WHERE s.estado = 'ANULADA')     AS anu,
    COALESCE(SUM(s.cuota) FILTER (WHERE s.estado = 'APROBADO'),0) AS monto_ok
  FROM sol_entidades e LEFT JOIN sol_solicitudes s ON s.entidad_id = e.id $wp
  WHERE e.activa GROUP BY e.id HAVING COUNT(s.id) FILTER (WHERE s.estado <> 'RETIRADA') > 0
  ORDER BY total DESC, e.nombre LIMIT 10")->fetchAll();

$duplicados = $pdo->query("SELECT s.id, s.fila, s.apellidos || ', ' || s.nombres AS nombre, o.fila AS fila_original, o.estado AS estado_original, e.nombre AS entidad
  FROM sol_solicitudes s JOIN sol_solicitudes o ON o.id = s.duplicado_de JOIN sol_entidades e ON e.id = s.entidad_id
  WHERE s.estado NOT IN ('DUPLICADA','RETIRADA') ORDER BY s.recibido DESC LIMIT 6")->fetchAll();

$concurrentes = $pdo->query("SELECT s.dni, MAX(s.apellidos || ', ' || s.nombres) AS nombre, s.periodo,
    COUNT(DISTINCT s.entidad_id) AS entidades, SUM(s.cuota) AS cuotas
  FROM sol_solicitudes s WHERE s.estado NOT IN ('RETIRADA','DESAPROBADO','DUPLICADA') AND s.dni <> '' $wp
  GROUP BY s.dni, s.periodo HAVING COUNT(DISTINCT s.entidad_id) > 1 ORDER BY SUM(s.cuota) DESC LIMIT 6")->fetchAll();

$actividad = $pdo->query("SELECT h.*, s.apellidos, s.nombres, e.nombre AS entidad
  FROM sol_historial h JOIN sol_solicitudes s ON s.id = h.solicitud_id JOIN sol_entidades e ON e.id = s.entidad_id
  ORDER BY h.id DESC LIMIT 8")->fetchAll();

$ultSync = $pdo->query('SELECT MAX(ultima_sync) FROM sol_entidades WHERE activa')->fetchColumn();
$nEnt = (int)$pdo->query('SELECT COUNT(*) FROM sol_entidades WHERE activa')->fetchColumn();
$periodos = Consultas::periodos();
$corto = fn($t) => mb_strimwidth((string)$t, 0, 30, '…', 'UTF-8');

$datos = [
    'estados'  => [
        ['name' => 'Por evaluar',  'value' => (int)$k['pend'], 'c' => '#3b82f6'],
        ['name' => 'Observadas',   'value' => (int)$k['obs'],  'c' => '#f59e0b'],
        ['name' => 'Aprobadas',    'value' => (int)$k['ok'],   'c' => '#10b981'],
        ['name' => 'Desaprobadas', 'value' => (int)$k['no'],   'c' => '#ef4444'],
        ['name' => 'Duplicadas',   'value' => (int)$k['dupl'], 'c' => '#8b5cf6'],
        ['name' => 'Anuladas',     'value' => (int)$k['anu'],  'c' => '#64748b'],
    ],
    'tasa'      => $tasa,
    'embudo'    => [
        ['name' => 'Recibidas',       'value' => (int)$k['total']],
        ['name' => 'Datos completos', 'value' => max(0, (int)$k['total'] - (int)$k['obs'] - (int)$k['dupl'])],
        ['name' => 'Atendidas',       'value' => $evaluadas + (int)$k['anu'],
         'det' => (int)$k['ok'] . ' aprobadas · ' . (int)$k['no'] . ' desaprobadas · ' . (int)$k['anu'] . ' anuladas'],
        ['name' => 'Aprobadas',       'value' => (int)$k['ok']],
        ['name' => 'Desaprobadas',    'value' => (int)$k['no']],
        ['name' => 'Anuladas',        'value' => (int)$k['anu']],
    ],
    'p3d'       => array_map('periodo_txt', $periodos3d),
    'e3d'       => array_map(fn($t) => $corto($t['nombre']), $top),
    'c3d'       => $celdas,
    'dias'      => array_column($dias, 'dia'),
    'recibidas' => array_map('intval', array_column($dias, 'recibidas')),
    'aprobadas' => array_map('intval', array_column($dias, 'aprobadas')),
    'desaprob'  => array_map('intval', array_column($dias, 'desaprobadas')),
    'anuladas'  => array_map('intval', array_column($dias, 'anuladas')),
    'grupos'    => array_map(fn($g) => ['name' => $g['g'], 'n' => (int)$g['n'], 'monto' => (float)$g['monto']], $porGrupo),
    'topMonto'  => array_map(fn($t) => ['name' => $corto($t['nombre']), 'full' => $t['nombre'], 'monto' => (float)$t['monto'], 'n' => (int)$t['n']], array_reverse($topMonto)),
];

$titulo = 'Panel analítico';
$activo = 'panel';
$sinTop = true;
$scripts = ['assets/vendor/echarts.min.js', 'assets/vendor/echarts-gl.min.js', 'assets/dashboard.js?v=9'];
require __DIR__ . '/partials/cabecera.php';
$q = $periodo ? '&periodo=' . $periodo : '';
?>
<section class="hero">
  <canvas id="heroCanvas" aria-hidden="true"></canvas>
  <div class="hero-in">
    <div>
      <div class="hero-kicker"><span class="live"></span>En vivo · <?= $nEnt ?> entidades conectadas · última sincronización <?= e(hace($ultSync)) ?></div>
      <h1>Centro de control de créditos</h1>
      <p>Solicitudes de entidades terceras evaluadas con la regla del <?= (int)cfg('evaluacion.porcentaje_tope') ?>% · UGEL Chincha</p>
    </div>
    <div class="hero-act">
      <form method="get" class="inline"><select name="periodo" onchange="this.form.submit()"><option value="">Todos los periodos</option>
        <?php foreach ($periodos as $p): ?><option value="<?= e($p) ?>" <?= $p === $periodo ? 'selected' : '' ?>><?= e(periodo_txt($p)) ?></option><?php endforeach; ?></select></form>
      <a class="btn btn-glow" href="bandeja.php?estado=PENDIENTE">Evaluar pendientes<?php if ($k['pend']): ?> <b><?= (int)$k['pend'] ?></b><?php endif; ?></a>
    </div>
  </div>

  <div class="kpis3d">
    <a class="k3 tilt" href="bandeja.php?<?= ltrim($q, '&') ?>"><span class="k3-l">Recibidas</span><span class="k3-v" data-count="<?= (int)$k['total'] ?>">0</span><span class="k3-f"><?= (int)$k['hoy'] ?> hoy</span></a>
    <a class="k3 tilt k3-pend" href="bandeja.php?estado=PENDIENTE<?= $q ?>"><span class="k3-l">Por evaluar</span><span class="k3-v" data-count="<?= (int)$k['pend'] ?>">0</span><span class="k3-f"><?= soles($k['monto_pend']) ?> en cuotas</span></a>
    <a class="k3 tilt k3-ok" href="bandeja.php?estado=APROBADO<?= $q ?>"><span class="k3-l">Aprobadas</span><span class="k3-v" data-count="<?= (int)$k['ok'] ?>">0</span><span class="k3-f"><?= soles($k['monto_ok']) ?> / mes</span></a>
    <a class="k3 tilt k3-no" href="bandeja.php?estado=DESAPROBADO<?= $q ?>"><span class="k3-l">Desaprobadas</span><span class="k3-v" data-count="<?= (int)$k['no'] ?>">0</span><span class="k3-f">exceden el tope</span></a>
    <a class="k3 tilt k3-obs" href="bandeja.php?estado=OBSERVADA<?= $q ?>"><span class="k3-l">Observadas</span><span class="k3-v" data-count="<?= (int)$k['obs'] ?>">0</span><span class="k3-f">esperan corrección</span></a>
    <a class="k3 tilt k3-anu" href="bandeja.php?estado=ANULADA<?= $q ?>"><span class="k3-l">Anuladas</span><span class="k3-v" data-count="<?= (int)$k['anu'] ?>">0</span><span class="k3-f">con sustento de Informática</span></a>
    <a class="k3 tilt k3-dup" href="bandeja.php?dup=1"><span class="k3-l">Posibles duplicados</span><span class="k3-v" data-count="<?= (int)$k['posdup'] ?>">0</span><span class="k3-f"><?= (float)$k['horas'] ?> h de atención promedio</span></a>
  </div>
</section>

<?php if (!$k['total']): ?>
  <section class="card"><p class="empty">Aún no hay solicitudes. Pulsa <b>Sincronizar Sheets</b> cuando las entidades registren sus primeras filas.</p></section>
<?php endif; ?>

<div class="dash-grid">
  <section class="card chart-card span-2">
    <div class="card-h"><h2>Solicitudes por entidad y periodo <span class="pill-3d">3D</span></h2><span class="muted xs">Arrastra para girar · rueda para acercar</span></div>
    <div class="chart" id="ch3d" style="height:430px"></div>
  </section>
  <section class="card chart-card">
    <div class="card-h"><h2>Tasa de aprobación</h2><span class="muted xs"><?= $evaluadas ?> evaluadas<?= $k['anu'] ? ' · ' . (int)$k['anu'] . ' anulada' . ($k['anu'] > 1 ? 's' : '') : '' ?></span></div>
    <div class="chart" id="chGauge" style="height:185px"></div>
    <div class="tasa-res">
      <a href="bandeja.php?estado=APROBADO<?= $q ?>"><i style="background:#10b981"></i><b><?= (int)$k['ok'] ?></b> aprobadas</a>
      <a href="bandeja.php?estado=DESAPROBADO<?= $q ?>"><i style="background:#ef4444"></i><b><?= (int)$k['no'] ?></b> desaprobadas</a>
      <a href="bandeja.php?estado=ANULADA<?= $q ?>"><i style="background:#64748b"></i><b><?= (int)$k['anu'] ?></b> anuladas</a>
    </div>
    <div class="card-h"><h2>Embudo de atención</h2></div>
    <div class="chart" id="chFunnel" style="height:260px"></div>
  </section>

  <section class="card chart-card span-2">
    <div class="card-h"><h2>Actividad de los últimos 30 días</h2><span class="muted xs">Recibidas por día y decisiones tomadas</span></div>
    <div class="chart" id="chTrend" style="height:300px"></div>
  </section>
  <section class="card chart-card">
    <div class="card-h"><h2>Estado actual</h2></div>
    <div class="chart" id="chDonut" style="height:300px"></div>
  </section>

  <section class="card chart-card">
    <div class="card-h"><h2>Por grupo de descuento</h2><span class="muted xs">Solicitudes · cuota aprobada</span></div>
    <div class="chart" id="chGrupo" style="height:300px"></div>
  </section>
  <section class="card chart-card span-2">
    <div class="card-h"><h2>Entidades con mayor cuota aprobada</h2><span class="muted xs">S/ por mes</span></div>
    <div class="chart" id="chTop" style="height:300px"></div>
  </section>

  <section class="card alert-card alert-red">
    <div class="card-h"><h2>⚠ Clientes duplicados</h2><a class="link" href="bandeja.php?dup=1">Ver todos</a></div>
    <?php if ($duplicados): ?><ul class="alist">
      <?php foreach ($duplicados as $d): ?><li><a href="solicitud.php?id=<?= (int)$d['id'] ?>">
        <b><?= e($d['nombre']) ?></b><span><?= e($corto($d['entidad'])) ?> · la fila <?= (int)$d['fila'] ?> repite la fila <?= (int)$d['fila_original'] ?> (<?= e(ESTADOS[$d['estado_original']][0] ?? '') ?>)</span></a></li>
      <?php endforeach; ?></ul>
    <?php else: ?><p class="empty ok-empty">Ninguna entidad ingresó dos veces al mismo cliente.</p><?php endif; ?>
  </section>
  <section class="card alert-card alert-amber">
    <div class="card-h"><h2>Concurrencia entre entidades</h2><span class="muted xs">Mismo DNI · mismo periodo</span></div>
    <?php if ($concurrentes): ?><ul class="alist">
      <?php foreach ($concurrentes as $c): ?><li><a href="bandeja.php?q=<?= e($c['dni']) ?>">
        <b><?= e($c['nombre']) ?></b><span><?= (int)$c['entidades'] ?> entidades · <?= e(periodo_txt($c['periodo'])) ?> · <?= soles($c['cuotas']) ?></span></a></li>
      <?php endforeach; ?></ul>
    <?php else: ?><p class="empty ok-empty">Ningún trabajador pide crédito a varias entidades a la vez.</p><?php endif; ?>
  </section>
  <section class="card">
    <div class="card-h"><h2>Actividad reciente</h2></div>
    <ul class="feed">
      <?php foreach ($actividad as $a): ?>
        <li><a href="solicitud.php?id=<?= (int)$a['solicitud_id'] ?>">
          <span class="feed-a"><?= e($a['accion']) ?></span>
          <span class="feed-d"><?= e($a['apellidos'] . ', ' . $a['nombres']) ?></span>
          <span class="feed-t"><?= e(hace($a['creado'])) ?> · <?= e($corto($a['entidad'])) ?></span></a></li>
      <?php endforeach; ?>
      <?php if (!$actividad): ?><li class="empty">Sin actividad.</li><?php endif; ?>
    </ul>
  </section>

  <section class="card span-3">
    <div class="card-h"><h2>Ranking de entidades</h2><a href="entidades.php" class="link">Todas las entidades</a></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Entidad</th><th class="r">Recibidas</th><th class="r">Por evaluar</th><th class="r">Aprobadas</th><th class="r">Desaprobadas</th><th class="r">Anuladas</th><th style="width:22%">Aprobación</th><th class="r">Cuota aprobada</th></tr></thead>
      <tbody>
      <?php foreach ($porEnt as $i => $r): $ev = $r['ok'] + $r['no']; $pc = $ev ? round($r['ok'] / $ev * 100) : 0; ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td><a href="bandeja.php?entidad=<?= $r['id'] . $q ?>" class="strong"><?= e($r['nombre']) ?></a></td>
          <td class="r num"><?= (int)$r['total'] ?></td>
          <td class="r num"><?= (int)$r['pend'] ?: '<span class="muted">0</span>' ?></td>
          <td class="r num t-ok"><?= (int)$r['ok'] ?></td>
          <td class="r num t-no"><?= (int)$r['no'] ?></td>
          <td class="r num"><?= (int)$r['anu'] ?: '<span class="muted">0</span>' ?></td>
          <td><div class="bar-ap"><span style="width:<?= $pc ?>%"></span><em><?= $ev ? $pc . '%' : '—' ?></em></div></td>
          <td class="r num"><?= soles($r['monto_ok']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$porEnt): ?><tr><td colspan="9" class="empty">Sin solicitudes en este periodo.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</div>
<script id="dashData" type="application/json"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php require __DIR__ . '/partials/pie.php';
