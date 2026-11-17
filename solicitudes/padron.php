<?php
require __DIR__ . '/bootstrap.php';
requerir_login();

if (!Padron::existe()) {
    $titulo = 'Padrón'; $activo = 'padron';
    require __DIR__ . '/partials/cabecera.php';
    echo '<section class="card"><h2>Falta actualizar la base de datos</h2><p class="muted">Ejecute en pgAdmin el archivo <code>sql/001_solicitudes.sql</code> (versión 1.11) y vuelva a abrir esta página.</p></section>';
    require __DIR__ . '/partials/pie.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $acc = $_POST['accion'] ?? '';
    try {
        if (!es_admin()) throw new RuntimeException('Solo un usuario administrador puede cargar o publicar el padrón.');
        if ($acc === 'cargar') {
            set_time_limit(300);
            $archivos = [];
            foreach ((array)($_FILES['archivos']['name'] ?? []) as $i => $nom) {
                $err = $_FILES['archivos']['error'][$i];
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) throw new RuntimeException("No se pudo subir $nom (código $err). Si es muy grande, aumente upload_max_filesize en php.ini.");
                $archivos[] = ['ruta' => $_FILES['archivos']['tmp_name'][$i], 'nombre' => $nom];
            }
            if (!$archivos) throw new RuntimeException('Seleccione al menos un archivo Excel (.xls, .xlsx) o CSV.');
            [$gente, $informe] = Padron::leerArchivos($archivos);
            $_SESSION['padron_informe'] = $informe;
            if (!$gente) throw new RuntimeException('Ningún archivo trajo trabajadores con DNI válido. Revise el detalle por hoja.');
            [$nuevos, $act] = Padron::guardar($gente, !empty($_POST['reemplazar']), implode(', ', array_column($archivos, 'nombre')));
            $msg = number_format(count($gente)) . " trabajadores leídos: $nuevos nuevos, $act actualizados.";
            try { $n = Padron::publicar(); flash($msg . " Publicado en el portal ($n personas).");
                  ajuste('padron_error', null); }
            catch (Throwable $e) { ajuste('padron_error', $e->getMessage()); flash($msg . ' No se pudo publicar en el PANEL: ' . $e->getMessage(), 'warn'); }
        } elseif ($acc === 'publicar') {
            $n = Padron::publicar(); ajuste('padron_error', null);
            flash("Padrón publicado en el PANEL: $n personas. El portal ya autocompleta con estos datos.");
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    header('Location: padron.php'); exit;
}

$r = Padron::resumen();
$informe = $_SESSION['padron_informe'] ?? null; unset($_SESSION['padron_informe']);
$q = preg_replace('/\D/', '', (string)($_GET['dni'] ?? ''));
$hallado = $q !== '' ? Padron::buscar($q) : null;
$pubErr = ajuste('padron_error');

$titulo = 'Padrón de trabajadores'; $activo = 'padron';
$subtitulo = 'Con el DNI, el portal de entidades completa solo los apellidos, los nombres y el código modular';
require __DIR__ . '/partials/cabecera.php';
?>
<div class="kpis kpis-4">
  <div class="kpi"><span class="kpi-l">Trabajadores</span><span class="kpi-v"><?= number_format($r['total']) ?></span><span class="kpi-f">DNI únicos en el padrón</span></div>
  <div class="kpi kpi-ok"><span class="kpi-l">Con código modular</span><span class="kpi-v"><?= number_format($r['con_codigo']) ?></span><span class="kpi-f">se autocompleta todo</span></div>
  <div class="kpi kpi-obs"><span class="kpi-l">Sin código modular</span><span class="kpi-v"><?= number_format($r['total'] - $r['con_codigo']) ?></span><span class="kpi-f">la entidad lo escribe</span></div>
  <div class="kpi kpi-pend"><span class="kpi-l">Publicado en el portal</span><span class="kpi-v kpi-v-sm"><?= e(hace(ajuste('padron_publicado'))) ?></span>
    <span class="kpi-f"><?= $r['ultima'] ? 'Última carga: ' . e(fecha_txt($r['ultima']['fecha'])) : 'Aún no se carga' ?></span></div>
</div>
<?php if ($pubErr): ?><div class="flash flash-warn">La última publicación en el PANEL falló: <?= e($pubErr) ?></div><?php endif; ?>

<div class="grid-2">
  <section class="card">
    <div class="card-h"><h2>Cargar archivos</h2><span class="xs muted">.xls · .xlsx · .csv</span></div>
    <?php if (es_admin()): ?>
    <form method="post" enctype="multipart/form-data" class="padron-form">
      <?= csrf_campo() ?>
      <label class="drop" id="drop">
        <input type="file" name="archivos[]" multiple accept=".xls,.xlsx,.csv,.txt" id="archivosIn">
        <b>Elegir o soltar archivos aquí</b>
        <span class="xs muted" id="archivosTxt">Puede subir varios a la vez (NEXUS, PENSIÓN, SOBREVIVIENTE…)</span>
      </label>
      <label class="chk"><input type="checkbox" name="reemplazar" value="1"> Reemplazar el padrón completo (borra a quienes ya no figuren en estos archivos)</label>
      <div class="padron-act">
        <button class="btn btn-pri" name="accion" value="cargar">Cargar y publicar</button>
        <button class="btn btn-ghost" name="accion" value="publicar" formnovalidate <?= $r['total'] ? '' : 'disabled' ?>>Solo volver a publicar</button>
      </div>
    </form>
    <?php else: ?><p class="muted">Solo un administrador puede cargar el padrón.</p><?php endif; ?>
    <details class="ayuda xs muted">
      <summary>¿Qué columnas necesita?</summary>
      <p>Se reconocen por el título, en cualquier orden y en cualquier hoja: <b>DNI</b> (o DOCUMENTO DE IDENTIDAD / NUMERO_DOCUMENTO_IDENTIDAD),
      <b>APELLIDO PATERNO</b>, <b>APELLIDO MATERNO</b> (o una sola columna APELLIDOS), <b>NOMBRES</b> y, si existe, <b>CODIGO MODULAR</b>.
      Un DNI repetido se guarda una sola vez; si un archivo no trae código modular, se conserva el que ya se tenía. Los reportes .lis no traen DNI y no sirven para el padrón.</p>
    </details>
  </section>

  <section class="card">
    <div class="card-h"><h2>Consultar DNI</h2><span class="xs muted">lo mismo que verá la entidad</span></div>
    <form method="get" class="padron-buscar">
      <input name="dni" inputmode="numeric" maxlength="8" placeholder="DNI de 8 dígitos" value="<?= e($q) ?>" autofocus>
      <button class="btn">Buscar</button>
    </form>
    <?php if ($q !== ''): ?>
      <?php if ($hallado): ?>
        <dl class="padron-ficha">
          <dt>Apellidos</dt><dd><?= e(trim($hallado['apellido_paterno'] . ' ' . $hallado['apellido_materno'])) ?></dd>
          <dt>Nombres</dt><dd><?= e($hallado['nombres']) ?></dd>
          <dt>Código modular</dt><dd class="mono"><?= $hallado['cod_modular'] ? e($hallado['cod_modular']) : '<span class="muted">— no figura (la entidad lo escribe)</span>' ?></dd>
          <dt>Fuente</dt><dd class="xs muted"><?= e($hallado['fuente']) ?> · <?= e(fecha_txt($hallado['actualizado'])) ?></dd>
        </dl>
      <?php else: ?>
        <p class="padron-no">El DNI <b class="mono"><?= e($q) ?></b> no figura en el padrón. La entidad deberá escribir los datos a mano.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($r['fuentes']): ?>
      <h3 class="padron-h3">Origen de los datos</h3>
      <table class="tbl"><tbody>
        <?php foreach ($r['fuentes'] as $f): ?><tr><td><?= e($f['fuente'] ?: '—') ?></td><td class="r mono"><?= number_format($f['n']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>
</div>

<?php if ($informe): ?>
<section class="card">
  <div class="card-h"><h2>Detalle de la última carga</h2></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Archivo</th><th>Hoja</th><th class="r">Trabajadores</th><th class="r">Con código modular</th><th class="r">Filas sin DNI válido</th><th>Observación</th></tr></thead><tbody>
  <?php foreach ($informe as $i): ?>
    <tr><td><?= e($i['archivo']) ?></td><td><?= e($i['hoja']) ?></td>
      <td class="r mono"><?= number_format($i['validas'] ?? 0) ?></td><td class="r mono"><?= number_format($i['con_codigo'] ?? 0) ?></td>
      <td class="r mono"><?= number_format($i['sin_dni'] ?? 0) ?></td>
      <td class="<?= $i['error'] ? 'txt-no' : 'muted' ?>"><?= e($i['error'] ?: 'OK') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<script>
(function () {
  var inp = document.getElementById('archivosIn'), txt = document.getElementById('archivosTxt'), drop = document.getElementById('drop');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var n = [].map.call(inp.files, function (f) { return f.name; });
    txt.textContent = n.length ? n.join(' · ') : 'Puede subir varios a la vez';
    drop.classList.toggle('lleno', n.length > 0);
  });
  ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.add('sobre'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('sobre'); }); });
  document.querySelector('.padron-form').addEventListener('submit', function (e) {
    var b = e.submitter; if (b) { b.disabled = true; b.textContent = 'Procesando…'; var h = document.createElement('input'); h.type = 'hidden'; h.name = 'accion'; h.value = b.value; this.appendChild(h); }
  });
})();
</script>
<?php require __DIR__ . '/partials/pie.php';
