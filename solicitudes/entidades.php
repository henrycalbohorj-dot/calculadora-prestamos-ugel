<?php
require __DIR__ . '/bootstrap.php';
requerir_login();
$pdo = db();
$cuenta = GoogleSheets::emailCuenta();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $acc = $_POST['accion'] ?? '';
    try {
        if (in_array($acc, ['guardar', 'activar', 'importar', 'panel_guardar', 'panel_actualizar'], true) && !es_admin()) {
            throw new RuntimeException('Solo un usuario administrador puede registrar o modificar entidades.');
        }
        if ($acc === 'guardar') {
            $nombre = mb_strtoupper(trim((string)$_POST['nombre']), 'UTF-8');
            $sid    = extraer_spreadsheet_id((string)$_POST['sheet']);
            $grupo  = (string)($_POST['grupo'] ?? '');
            $hoja   = trim((string)($_POST['hoja'] ?? '')) ?: 'SOLICITUDES';
            $codigo = preg_replace('/\D/', '', (string)($_POST['codigo'] ?? '')) ?: null;
            if ($nombre === '' || strlen($sid) < 20) throw new RuntimeException('Completa el nombre y pega el enlace del Google Sheet.');

            // Verifica acceso antes de guardar
            $meta = (new GoogleSheets())->metadatos($sid);
            $hojas = array_map(fn($x) => $x['properties']['title'], $meta['sheets'] ?? []);
            if (!in_array($hoja, $hojas, true)) {
                throw new RuntimeException("El Sheet existe pero no tiene la pestaña '$hoja' (tiene: " . implode(', ', $hojas) . '). Ejecuta "Instalar plantilla" en ese Sheet.');
            }
            if (!empty($_POST['id'])) {
                $pdo->prepare('UPDATE sol_entidades SET nombre=?, grupo=?, spreadsheet_id=?, hoja=?, titulo_hoja=?, codigo=? WHERE id=?')
                    ->execute([$nombre, $grupo, $sid, $hoja, $meta['properties']['title'] ?? null, $codigo, (int)$_POST['id']]);
                flash('Entidad actualizada.');
            } else {
                $pdo->prepare('INSERT INTO sol_entidades (nombre, grupo, spreadsheet_id, hoja, titulo_hoja, codigo) VALUES (?,?,?,?,?,?)')
                    ->execute([$nombre, $grupo, $sid, $hoja, $meta['properties']['title'] ?? null, $codigo]);
                flash('Entidad registrada y conexión verificada: "' . ($meta['properties']['title'] ?? $sid) . '".');
            }
        } elseif ($acc === 'importar') {
            // Filas copiadas del PANEL ENTIDADES (COD, NOMBRE, GRUPO, ENLACE) separadas por tabulación
            $gs = new GoogleSheets();
            $grupos = array_map([Validador::class, 'norm'], cfg('grupos'));
            $ok = 0; $errores = [];
            $lineas = preg_split('/\R/', (string)($_POST['lista'] ?? ''));
            $verificar = count($lineas) <= 50;   // respeta el cupo gratuito de Google (60 lecturas/min)
            foreach ($lineas as $n => $linea) {
                $celdas = array_values(array_filter(array_map('trim', explode("\t", $linea)), fn($c) => $c !== ''));
                if (!$celdas || preg_match('/NOMBRE/i', implode(' ', $celdas)) && !preg_match('#/d/#', $linea)) continue;
                $sid = $cod = $grupo = $nombre = null;
                foreach ($celdas as $c) {
                    if (!$sid && preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $c, $m)) { $sid = $m[1]; continue; }
                    if (!$cod && preg_match('/^\d{1,6}$/', $c)) { $cod = $c; continue; }
                    if (!$grupo && in_array(Validador::norm($c), $grupos, true)) { $grupo = cfg('grupos')[array_search(Validador::norm($c), $grupos, true)]; continue; }
                    if (!$nombre || mb_strlen($c) > mb_strlen($nombre)) $nombre = $c;
                }
                if (!$sid || !$nombre) { $errores[] = 'Línea ' . ($n + 1) . ': sin enlace del Sheet o sin nombre'; continue; }
                $titulo = null;
                if ($verificar) {
                    try { $titulo = $gs->metadatos($sid)['properties']['title'] ?? null; }
                    catch (Throwable $ex) { $errores[] = mb_strimwidth($nombre, 0, 40, '…') . ': ' . $ex->getMessage(); continue; }
                }
                Consultas::upsertEntidad($cod, mb_strtoupper($nombre, 'UTF-8'), $grupo, $sid, $titulo);
                $ok++;
            }
            flash("Importadas: $ok entidad(es)." . ($errores ? ' Con problemas: ' . implode(' · ', array_slice($errores, 0, 5)) . (count($errores) > 5 ? ' …' : '') : ''),
                  $errores ? 'warn' : 'ok');
            header('Location: entidades.php');
            exit;
        } elseif ($acc === 'panel_guardar') {
            $pid = extraer_spreadsheet_id((string)($_POST['panel'] ?? ''));
            if (strlen($pid) < 20) throw new RuntimeException('Pega el enlace completo del Google Sheet PANEL ENTIDADES.');
            $meta = (new GoogleSheets())->metadatos($pid);
            $hojas = array_map(fn($x) => $x['properties']['title'], $meta['sheets'] ?? []);
            if (!in_array('ENTIDADES', $hojas, true)) throw new RuntimeException('Ese Sheet no tiene la pestaña ENTIDADES. ¿Es el PANEL ENTIDADES?');
            ajuste('panel_id', $pid);
            $n = Panel::publicarDisponibles();
            flash('PANEL conectado: "' . ($meta['properties']['title'] ?? '') . '". Montos disponibles publicados: ' . (int)$n . '.');
        } elseif ($acc === 'panel_actualizar') {
            $cont = ['nueva' => 0, 'actualizada' => 0, 'igual' => 0]; $errores = [];
            foreach (Panel::entidades() as $x) {
                if (!$x['spreadsheet_id'] && !$x['codigo']) continue;
                try { $cont[Consultas::upsertEntidad($x['codigo'], $x['nombre'], $x['grupo'], $x['spreadsheet_id'])]++; }
                catch (Throwable $ex) { $errores[] = $ex->getMessage(); }
            }
            flash("Desde el PANEL: {$cont['nueva']} nueva(s), {$cont['actualizada']} actualizada(s), {$cont['igual']} sin cambios."
                  . ($errores ? ' · ' . implode(' · ', array_slice($errores, 0, 4)) : ''), $errores ? 'warn' : 'ok');
        } elseif ($acc === 'panel_publicar') {
            $n = Panel::publicarDisponibles();
            flash($n === null ? 'Primero conecta el PANEL ENTIDADES.' : "Montos disponibles publicados en el PANEL: $n trabajador(es).", $n === null ? 'warn' : 'ok');
        } elseif ($acc === 'activar') {
            $pdo->prepare('UPDATE sol_entidades SET activa = NOT activa WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('Estado de la entidad actualizado.');
        } elseif ($acc === 'probar') {
            $st = $pdo->prepare('SELECT * FROM sol_entidades WHERE id = ?');
            $st->execute([(int)$_POST['id']]);
            $en = $st->fetch();
            $meta = (new GoogleSheets())->metadatos($en['spreadsheet_id']);
            flash('Conexión correcta con "' . ($meta['properties']['title'] ?? '') . '".');
        } elseif ($acc === 'sincronizar') {
            $st = $pdo->prepare('SELECT * FROM sol_entidades WHERE id = ?');
            $st->execute([(int)$_POST['id']]);
            $r = (new Sincronizador())->entidad($st->fetch());
            if ($r['error']) throw new RuntimeException($r['error']);
            flash("Sincronizado: {$r['leidas']} filas, {$r['nuevas']} nuevas, {$r['actualizadas']} cambiadas, {$r['retiradas']} retiradas.");
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
    }
    header('Location: entidades.php');
    exit;
}

$ents = $pdo->query("SELECT e.*,
        (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.entidad_id = e.id AND s.estado <> 'RETIRADA') AS n,
        (SELECT COUNT(*) FROM sol_solicitudes s WHERE s.entidad_id = e.id AND s.estado = 'PENDIENTE') AS pend
    FROM sol_entidades e ORDER BY e.activa DESC, NULLIF(regexp_replace(COALESCE(e.codigo,''), '\D', '', 'g'), '')::int NULLS LAST, e.nombre")->fetchAll();
$editar = null;
if (!empty($_GET['editar'])) {
    foreach ($ents as $x) if ((int)$x['id'] === (int)$_GET['editar']) $editar = $x;
}
$logs = $pdo->query('SELECT l.*, e.nombre FROM sol_sync_log l LEFT JOIN sol_entidades e ON e.id = l.entidad_id ORDER BY l.id DESC LIMIT 15')->fetchAll();

$porGrupo = [];
foreach ($ents as $x) { $g = $x['grupo'] ?: 'SIN GRUPO'; $porGrupo[$g] = ($porGrupo[$g] ?? 0) + 1; }
$conError = count(array_filter($ents, fn($x) => $x['ultimo_error']));
$inactivas = count(array_filter($ents, fn($x) => !$x['activa']));
$corto = ['COOPERATIVA JUDICIALES' => 'Coop. judiciales', 'FONDO DE BIENESTAR' => 'Fondo de bienestar', 'COOPERATIVAS' => 'Cooperativas',
          'ENTIDADES REGULADAS POR LA SBS' => 'Reguladas SBS', 'SINDICATO' => 'Sindicato', 'SIN GRUPO' => 'Sin grupo'];

$titulo = 'Entidades';
$activo = 'entidades';
$subtitulo = count($ents) . ' entidades · cada una con su propio Google Sheet, visible solo para sus usuarios.';
$acciones = es_admin()
    ? '<button type="button" class="btn" data-panel="pImportar">Importar lista</button><button type="button" class="btn btn-pri" data-panel="pRegistrar">+ Registrar entidad</button>'
    : '';
require __DIR__ . '/partials/cabecera.php';
?>
<?php if (es_admin()): ?>
<section class="card panel-plegable" id="pRegistrar" <?= $editar ? '' : 'hidden' ?>>
  <div class="card-h"><h2><?= $editar ? 'Editar entidad' : 'Registrar entidad' ?></h2><a class="link" href="entidades.php">Cerrar</a></div>
  <form method="post" class="form-row">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <label class="fr-cod"><span>Código</span><input name="codigo" inputmode="numeric" value="<?= e($editar['codigo'] ?? '') ?>" placeholder="1710"></label>
    <label class="fr-nom"><span>Nombre de la entidad</span><input name="nombre" required value="<?= e($editar['nombre'] ?? '') ?>" placeholder="COOPERATIVA …"></label>
    <label class="fr-gru"><span>Grupo</span><select name="grupo"><?php foreach (cfg('grupos') as $x): ?>
      <option <?= ($editar['grupo'] ?? '') === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <label class="fr-url"><span>Enlace del Google Sheet</span><input name="sheet" required value="<?= e($editar ? 'https://docs.google.com/spreadsheets/d/' . $editar['spreadsheet_id'] . '/edit' : '') ?>" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
    <input type="hidden" name="hoja" value="<?= e($editar['hoja'] ?? 'SOLICITUDES') ?>">
    <div class="fr-act"><button class="btn btn-pri">Verificar y guardar</button></div>
  </form>
</section>

<section class="card panel-plegable" id="pImportar" hidden>
  <div class="card-h"><h2>Importar lista</h2><button type="button" class="link btn-link" data-panel="pImportar">Cerrar</button></div>
  <form method="post" class="stack">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
    <label><span>Pega las columnas A a D del <b>PANEL ENTIDADES</b> (COD, NOMBRE, GRUPO, ENLACE). Si una entidad ya existe, se actualiza.</span>
      <textarea name="lista" rows="5" class="mono xs" placeholder="1710&#9;COOPERATIVA DE AHORRO Y CRÉDITO SAN MIGUEL&#9;COOPERATIVAS&#9;https://docs.google.com/spreadsheets/d/…"></textarea></label>
    <div><button class="btn btn-pri">Verificar e importar</button></div>
  </form>
</section>
<?php endif; ?>

<div class="ent-stats">
  <div><b><?= count($ents) ?></b><span>entidades</span></div>
  <div><b><?= count($ents) - $inactivas ?></b><span>activas</span></div>
  <div><b class="<?= $conError ? 't-no' : '' ?>"><?= $conError ?></b><span>con error de conexión</span></div>
  <div class="ent-sa">
    <span class="muted xs">Cuenta de servicio (Editor en cada Sheet y en el PANEL)</span>
    <?php if ($cuenta): ?><div class="copy copy-sm"><code id="sa"><?= e($cuenta) ?></code><button class="btn btn-sm" type="button" data-copiar="sa">Copiar</button></div>
    <?php else: ?><span class="t-no xs">Falta credenciales/service-account.json</span><?php endif; ?>
  </div>
</div>

<?php $panelId = Panel::id(); $panelPub = ajuste('panel_publicado'); $panelErr = ajuste('panel_error'); ?>
<section class="card panel-link <?= $panelId ? 'ok' : '' ?>">
  <div class="pl-ico"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18M9 21V9"/></svg></div>
  <div class="pl-txt">
    <b>PANEL ENTIDADES <?= $panelId ? '<span class="chip chip-ok">Conectado</span>' : '<span class="chip chip-obs">Sin conectar</span>' ?></b>
    <span class="muted xs">Desde aquí se actualizan los enlaces y códigos de las entidades, y se publica el <b>monto disponible</b> de cada trabajador para la regla automática del portal.
      <?php if ($panelPub): ?> Última publicación: <?= e(fecha_txt($panelPub)) ?>.<?php endif; ?>
      <?php if ($panelErr): ?><br><span class="t-no">Error al publicar: <?= e($panelErr) ?></span><?php endif; ?></span>
  </div>
  <?php if (es_admin()): ?>
  <form method="post" class="pl-form">
    <?= csrf_campo() ?>
    <input name="panel" value="<?= e($panelId ? 'https://docs.google.com/spreadsheets/d/' . $panelId . '/edit' : '') ?>" placeholder="Enlace del Google Sheet PANEL ENTIDADES">
    <button class="btn" name="accion" value="panel_guardar">Conectar</button>
    <?php if ($panelId): ?>
    <button class="btn btn-pri" name="accion" value="panel_actualizar" formnovalidate>Actualizar entidades desde el PANEL</button>
    <button class="btn btn-ghost" name="accion" value="panel_publicar" formnovalidate>Publicar disponibles</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</section>

<section class="card p0 ent-card">
  <div class="ent-toolbar">
    <div class="tabs tabs-in" id="entTabs">
      <a href="#" class="on" data-grupo="">Todas <b><?= count($ents) ?></b></a>
      <?php foreach ($porGrupo as $g => $n): ?><a href="#" data-grupo="<?= e($g) ?>"><?= e($corto[$g] ?? $g) ?> <b><?= $n ?></b></a><?php endforeach; ?>
      <?php if ($conError): ?><a href="#" data-grupo="__error" class="t-no">Con error <b><?= $conError ?></b></a><?php endif; ?>
    </div>
    <div class="ent-tools">
      <input type="search" id="entBuscar" placeholder="Buscar por nombre o código…">
      <label class="inline xs muted">Mostrar
        <select id="entMostrar"><option value="15">15</option><option value="30">30</option><option value="0">Todas</option></select></label>
    </div>
  </div>
  <div class="tbl-wrap"><table class="tbl tbl-ent" id="tblEnt">
    <thead><tr>
      <th class="sortable" data-sort="cod" style="width:70px">Cód.</th>
      <th class="sortable" data-sort="nombre">Entidad</th>
      <th class="sortable" data-sort="grupo" style="width:150px">Grupo</th>
      <th class="sortable r" data-sort="n" style="width:110px">Solicitudes</th>
      <th style="width:140px">Sincronización</th>
      <th class="r" style="width:150px">Acciones</th>
    </tr></thead>
    <tbody>
    <?php foreach ($ents as $en):
        $g = $en['grupo'] ?: 'SIN GRUPO'; ?>
      <tr class="<?= $en['activa'] ? '' : 'row-off' ?>"
          data-cod="<?= (int)$en['codigo'] ?>" data-nombre="<?= e($en['nombre']) ?>" data-grupo="<?= e($g) ?>" data-n="<?= (int)$en['n'] ?>"
          data-error="<?= $en['ultimo_error'] ? 1 : 0 ?>" data-buscar="<?= e(mb_strtolower($en['codigo'] . ' ' . $en['nombre'], 'UTF-8')) ?>">
        <td class="mono xs muted"><?= e($en['codigo'] ?: '—') ?></td>
        <td class="ent-nom"><a href="https://docs.google.com/spreadsheets/d/<?= e($en['spreadsheet_id']) ?>/edit" target="_blank" rel="noopener" title="<?= e($en['nombre']) ?> — abrir su Google Sheet">
            <?= e($en['nombre']) ?></a><?php if (!$en['activa']): ?> <span class="tag">inactiva</span><?php endif; ?></td>
        <td><span class="gchip g-<?= substr(md5($g), 0, 1) ?>"><?= e($corto[$g] ?? $g) ?></span></td>
        <td class="r num"><a href="bandeja.php?entidad=<?= $en['id'] ?>"><?= (int)$en['n'] ?></a><?php if ($en['pend']): ?> <span class="tag tag-warn" title="Por evaluar"><?= (int)$en['pend'] ?></span><?php endif; ?></td>
        <td class="xs"><?php if ($en['ultimo_error']): ?><span class="dot dot-err"></span><span class="t-no" title="<?= e($en['ultimo_error']) ?>">Error · ver detalle</span>
            <?php else: ?><span class="dot <?= $en['ultima_sync'] ? 'dot-ok' : 'dot-off' ?>"></span><span class="muted"><?= e(hace($en['ultima_sync'])) ?></span><?php endif; ?></td>
        <td class="r nowrap">
          <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $en['id'] ?>">
            <button class="btn btn-sm" name="accion" value="sincronizar" title="Leer ahora su Sheet">Sincronizar</button>
            <details class="menu">
              <summary class="btn btn-sm btn-ghost" title="Más acciones">⋯</summary>
              <div class="menu-pop">
                <a href="https://docs.google.com/spreadsheets/d/<?= e($en['spreadsheet_id']) ?>/edit" target="_blank" rel="noopener">Abrir Google Sheet ↗</a>
                <button name="accion" value="probar">Probar conexión</button>
                <a href="bandeja.php?entidad=<?= $en['id'] ?>">Ver sus solicitudes</a>
                <?php if (es_admin()): ?>
                <a href="?editar=<?= $en['id'] ?>">Editar</a>
                <button name="accion" value="activar"><?= $en['activa'] ? 'Desactivar' : 'Activar' ?></button>
                <?php endif; ?>
              </div>
            </details>
          </form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ents): ?><tr><td colspan="6" class="empty">Aún no hay entidades registradas.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="pager" id="entPager"></div>
</section>

<details class="card plegable">
  <summary><h2>Registro de sincronizaciones</h2><span class="muted xs">Últimas 15</span></summary>
  <table class="tbl"><thead><tr><th>Fecha</th><th>Entidad</th><th class="r">Filas</th><th class="r">Nuevas</th><th class="r">Cambios</th><th class="r">Escritas</th><th>Resultado</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?>
    <tr><td class="xs nowrap"><?= e(fecha_txt($l['inicio'])) ?></td><td class="xs"><?= e($l['nombre']) ?></td>
      <td class="r num"><?= (int)$l['leidas'] ?></td><td class="r num"><?= (int)$l['nuevas'] ?></td><td class="r num"><?= (int)$l['actualizadas'] ?></td><td class="r num"><?= (int)$l['escritas'] ?></td>
      <td class="xs"><?= $l['error'] ? '<span class="t-no">' . e(mb_strimwidth($l['error'], 0, 110, '…')) . '</span>' : '<span class="t-ok">OK</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$logs): ?><tr><td colspan="7" class="empty">Sin sincronizaciones todavía.</td></tr><?php endif; ?>
  </tbody></table>
</details>
<?php require __DIR__ . '/partials/pie.php';
