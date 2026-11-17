<?php
require_once __DIR__ . '/includes/auth.php';
exigirSesion();

$pdo = obtenerConexion();
$id = (int)($_GET['id'] ?? 0);
$autoimprimir = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

$stmt = $pdo->prepare(
    'SELECT e.*, u.nombre_completo AS registrado_por
     FROM evaluaciones e
     LEFT JOIN usuarios u ON u.id = e.usuario_id
     WHERE e.id = :id'
);
$stmt->execute(['id' => $id]);
$ev = $stmt->fetch();

if (!$ev) {
    http_response_code(404);
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="card__body"><p>No se encontró la evaluación solicitada.</p>
          <a class="btn btn--primary" href="historial.php">Volver al historial</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$stmtDesc = $pdo->prepare('SELECT nombre, monto FROM evaluacion_descuentos WHERE evaluacion_id = :id ORDER BY id');
$stmtDesc->execute(['id' => $id]);
$descuentos = $stmtDesc->fetchAll();

$stmtCred = $pdo->prepare('SELECT entidad_nombre, entidad_tipo, monto_solicitado FROM evaluacion_creditos WHERE evaluacion_id = :id ORDER BY id');
$stmtCred->execute(['id' => $id]);
$creditos = $stmtCred->fetchAll();

function fmt2($n) { return 'S/ ' . number_format((float)$n, 2); }

$tituloPagina = 'Detalle de evaluación';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Evaluación #<?= (int)$ev['id'] ?></h1>
        <p class="muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ev['creado_en']))) ?> · Registrado por <?= htmlspecialchars($ev['registrado_por'] ?? '—') ?></p>
    </div>
    <div class="page-head__actions no-print">
        <a href="historial.php" class="btn btn--ghost">← Volver</a>
        <button type="button" class="btn btn--primary" onclick="window.print()">Imprimir</button>
    </div>
</div>

<!-- Membrete solo visible al imprimir -->
<div class="print-letterhead print-only">
    <img src="ugel.jpg" alt="UGEL" class="print-letterhead__logo">
    <div class="print-letterhead__text">
        <strong>Unidad de Gestión Educativa Local</strong>
        <span>Evaluación de Disponibilidad de Crédito — Uso interno de RRHH / T.I.</span>
    </div>
    <div class="print-letterhead__fecha">
        Folio N.º <?= (int)$ev['id'] ?><br>
        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($ev['creado_en']))) ?>
    </div>
</div>

<div class="calc-grid">
    <div class="calc-main">
        <section class="card">
            <header class="card__header"><h2>Datos del trabajador</h2></header>
            <div class="card__body grid-4">
                <div><span class="muted">Nombre</span><p><strong><?= htmlspecialchars($ev['trabajador_nombre']) ?></strong></p></div>
                <div><span class="muted">DNI</span><p><strong><?= htmlspecialchars($ev['trabajador_dni'] ?? '—') ?></strong></p></div>
                <div><span class="muted">Área / Dependencia</span><p><strong><?= htmlspecialchars($ev['trabajador_dependencia'] ?? '—') ?></strong></p></div>
                <div><span class="muted">Tipo de personal</span><p><strong><?= ($ev['tipo_personal'] ?? 'administrativo') === 'docente' ? 'Docente' : 'Personal Administrativo' ?></strong></p></div>
            </div>
        </section>

        <section class="card">
            <header class="card__header"><h2>Descuentos de ley aplicados</h2></header>
            <div class="card__body">
                <div class="rows-head"><span>Concepto</span><span>Monto</span><span></span></div>
                <?php if (!$descuentos): ?>
                <p class="muted">No se registraron descuentos.</p>
                <?php endif; ?>
                <?php foreach ($descuentos as $d): ?>
                <div class="row-item row-item--static">
                    <span><?= htmlspecialchars($d['nombre']) ?></span>
                    <span><?= fmt2($d['monto']) ?></span>
                    <span></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <header class="card__header"><h2>Entidades financieras solicitadas</h2></header>
            <div class="card__body">
                <div class="rows-head"><span>Entidad</span><span>Monto solicitado</span><span></span></div>
                <?php if (!$creditos): ?>
                <p class="muted">No se registraron solicitudes.</p>
                <?php endif; ?>
                <?php foreach ($creditos as $c): ?>
                <div class="row-item row-item--static">
                    <span><?= htmlspecialchars($c['entidad_nombre']) ?></span>
                    <span><?= fmt2($c['monto_solicitado']) ?></span>
                    <span></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if (!empty($ev['observaciones'])): ?>
        <section class="card">
            <header class="card__header"><h2>Observaciones</h2></header>
            <div class="card__body"><p><?= nl2br(htmlspecialchars($ev['observaciones'])) ?></p></div>
        </section>
        <?php endif; ?>
    </div>

    <aside class="calc-summary">
        <div class="summary-card">
            <h3>Resumen del cálculo</h3>
            <div class="summary-line summary-line--total">
                <span><?= (!empty($ev['sueldo_adicional_monto']) && (float)$ev['sueldo_adicional_monto'] > 0) ? 'Sueldo bruto total' : 'Sueldo bruto' ?></span>
                <strong><?= fmt2($ev['sueldo_bruto']) ?></strong>
            </div>
            <?php if (!empty($ev['sueldo_adicional_monto']) && (float)$ev['sueldo_adicional_monto'] > 0): ?>
            <p class="summary-subline">Base <?= fmt2($ev['sueldo_bruto_base']) ?> + <?= htmlspecialchars($ev['sueldo_adicional_concepto'] ?: 'Remuneración adicional') ?> <?= fmt2($ev['sueldo_adicional_monto']) ?></p>
            <?php endif; ?>
            <div class="summary-line"><span>Total descuentos de ley</span><strong class="text-danger">− <?= fmt2($ev['total_descuentos']) ?></strong></div>
            <div class="summary-divider"></div>
            <div class="summary-line summary-line--big"><span>Sueldo neto total (100%)</span><strong><?= fmt2($ev['neto_total']) ?></strong></div>
            <div class="summary-highlight"><span>Capacidad máxima disponible (50% del neto)</span><strong><?= fmt2($ev['neto_50']) ?></strong></div>
            <div class="summary-divider"></div>
            <div class="summary-line"><span>Total solicitado en entidades</span><strong class="text-danger">− <?= fmt2($ev['total_solicitado']) ?></strong></div>
            <div class="summary-result <?= $ev['excede_limite'] ? 'summary-result--danger' : 'summary-result--ok' ?>">
                <span>Capacidad disponible para crédito</span>
                <strong><?= fmt2($ev['capacidad_disponible']) ?></strong>
            </div>
            <p class="summary-note <?= $ev['excede_limite'] ? 'text-danger' : 'text-success' ?>">
                <?= $ev['excede_limite'] ? '⚠ Esta evaluación superó el 50% del sueldo neto permitido.' : '✓ Esta evaluación estuvo dentro del límite permitido.' ?>
            </p>
        </div>
    </aside>
</div>

<!-- Firmas: solo visibles al imprimir -->
<div class="print-firmas print-only">
    <div class="print-firmas__linea"><span></span><small>Elaborado por (RRHH / T.I.)</small></div>
    <div class="print-firmas__linea"><span></span><small>V°B° Jefe de Personal</small></div>
    <div class="print-firmas__linea"><span></span><small>Trabajador (recibí conforme)</small></div>
</div>

<?php if ($autoimprimir): ?>
<script>
    // Viene del botón "Imprimir esta evaluación" justo después de guardar
    // en la calculadora — se abre esta pantalla y se lanza el diálogo de
    // impresión de una vez, sin que el usuario tenga que hacer nada más.
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
