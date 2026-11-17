<?php
require_once __DIR__ . '/includes/auth.php';
exigirSesion();

$pdo = obtenerConexion();

$busqueda = trim((string)($_GET['q'] ?? ''));

if ($busqueda !== '') {
    $stmt = $pdo->prepare(
        'SELECT e.*, u.nombre_completo AS registrado_por
         FROM evaluaciones e
         LEFT JOIN usuarios u ON u.id = e.usuario_id
         WHERE e.trabajador_nombre ILIKE :q OR e.trabajador_dni ILIKE :q
         ORDER BY e.creado_en DESC
         LIMIT 200'
    );
    $stmt->execute(['q' => '%' . $busqueda . '%']);
} else {
    $stmt = $pdo->query(
        'SELECT e.*, u.nombre_completo AS registrado_por
         FROM evaluaciones e
         LEFT JOIN usuarios u ON u.id = e.usuario_id
         ORDER BY e.creado_en DESC
         LIMIT 200'
    );
}
$evaluaciones = $stmt->fetchAll();

function fmt($n) { return 'S/ ' . number_format((float)$n, 2); }

$tituloPagina = 'Historial';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Historial de evaluaciones</h1>
        <p class="muted">Consulta las evaluaciones de disponibilidad de crédito guardadas.</p>
    </div>
</div>

<form method="get" class="search-bar no-print">
    <input type="text" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre o DNI...">
    <button type="submit" class="btn btn--primary">Buscar</button>
    <?php if ($busqueda !== ''): ?>
    <a href="historial.php" class="btn btn--ghost">Limpiar</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Trabajador</th>
                    <th>DNI</th>
                    <th>Tipo</th>
                    <th>Sueldo bruto</th>
                    <th>Neto (100%)</th>
                    <th>Máximo (50%)</th>
                    <th>Solicitado</th>
                    <th>Disponible</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$evaluaciones): ?>
                <tr><td colspan="11" class="table__empty">No hay evaluaciones registradas todavía.</td></tr>
                <?php endif; ?>
                <?php foreach ($evaluaciones as $ev): ?>
                <tr>
                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ev['creado_en']))) ?></td>
                    <td><strong><?= htmlspecialchars($ev['trabajador_nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($ev['trabajador_dni'] ?? '—') ?></td>
                    <td>
                        <?php if (($ev['tipo_personal'] ?? 'administrativo') === 'docente'): ?>
                        <span class="badge badge--tipo-docente">Docente</span>
                        <?php else: ?>
                        <span class="badge badge--tipo-admin">Administrativo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= fmt($ev['sueldo_bruto']) ?></td>
                    <td><?= fmt($ev['neto_total']) ?></td>
                    <td><?= fmt($ev['neto_50']) ?></td>
                    <td><?= fmt($ev['total_solicitado']) ?></td>
                    <td class="<?= $ev['capacidad_disponible'] < 0 ? 'text-danger' : 'text-success' ?>"><strong><?= fmt($ev['capacidad_disponible']) ?></strong></td>
                    <td>
                        <?php if ($ev['excede_limite']): ?>
                        <span class="badge badge--danger">Excede límite</span>
                        <?php else: ?>
                        <span class="badge badge--success">Dentro del límite</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn--ghost btn--sm" href="detalle.php?id=<?= (int)$ev['id'] ?>">Ver</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
