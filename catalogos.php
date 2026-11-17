<?php
require_once __DIR__ . '/includes/auth.php';
exigirAdmin();

$pdo = obtenerConexion();

$descuentos = $pdo->query('SELECT id, nombre, grupo_exclusivo, veces_usado FROM descuentos_catalogo WHERE activo = TRUE ORDER BY orden, nombre')->fetchAll();
$entidades  = $pdo->query('SELECT id, nombre, tipo, veces_usado FROM entidades_catalogo WHERE activo = TRUE ORDER BY orden, nombre')->fetchAll();

$etiquetasTipo = [
    'banco' => 'Banco',
    'cooperativa' => 'Cooperativa',
    'caja' => 'Caja',
    'otro' => 'Otro',
];

$tituloPagina = 'Catálogos';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Gestión de catálogos</h1>
        <p class="muted">Agrega o quita los conceptos de descuento y las entidades financieras disponibles en la calculadora.</p>
    </div>
</div>

<div class="calc-grid calc-grid--split">
    <section class="card">
        <header class="card__header">
            <h2>Descuentos de ley</h2>
            <p class="muted">El "grupo excluyente" evita que dos conceptos se marquen a la vez (ej. AFP y ONP). Déjalo vacío si no aplica.</p>
        </header>
        <div class="card__body">
            <div id="listaDescuentos" class="catalog-list">
                <?php foreach ($descuentos as $d): ?>
                <div class="catalog-item" data-id="<?= (int)$d['id'] ?>">
                    <span>
                        <?= htmlspecialchars($d['nombre']) ?>
                        <?php if (!empty($d['grupo_exclusivo'])): ?>
                        <em class="muted">· excluyente: <?= htmlspecialchars($d['grupo_exclusivo']) ?></em>
                        <?php endif; ?>
                        <span class="badge badge--uso" title="Veces utilizado en evaluaciones">Usado <?= (int)$d['veces_usado'] ?>×</span>
                    </span>
                    <button type="button" class="btn-icon-del" data-tipo="descuento" data-id="<?= (int)$d['id'] ?>" title="Quitar">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <form class="catalog-form catalog-form--descuento" id="formNuevoDescuento">
                <input type="text" id="nuevoDescuentoNombre" placeholder="Nuevo concepto, ej. Fondo Mutual" required>
                <input type="text" id="nuevoDescuentoGrupo" placeholder="Grupo excluyente (opcional), ej. pension">
                <button type="submit" class="btn btn--primary">Agregar</button>
            </form>
        </div>
    </section>

    <section class="card">
        <header class="card__header"><h2>Entidades financieras</h2></header>
        <div class="card__body">
            <div id="listaEntidades" class="catalog-list">
                <?php foreach ($entidades as $e): ?>
                <div class="catalog-item" data-id="<?= (int)$e['id'] ?>">
                    <span>
                        <?= htmlspecialchars($e['nombre']) ?> <em class="muted">(<?= htmlspecialchars($etiquetasTipo[$e['tipo']] ?? $e['tipo']) ?>)</em>
                        <span class="badge badge--uso" title="Veces utilizado en evaluaciones">Usado <?= (int)$e['veces_usado'] ?>×</span>
                    </span>
                    <button type="button" class="btn-icon-del" data-tipo="entidad" data-id="<?= (int)$e['id'] ?>" title="Quitar">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <form class="catalog-form" id="formNuevaEntidad">
                <input type="text" id="nuevaEntidadNombre" placeholder="Nueva entidad, ej. Caja Piura" required>
                <select id="nuevaEntidadTipo">
                    <option value="banco">Banco</option>
                    <option value="cooperativa" selected>Cooperativa</option>
                    <option value="caja">Caja</option>
                    <option value="otro">Otro</option>
                </select>
                <button type="submit" class="btn btn--primary">Agregar</button>
            </form>
        </div>
    </section>
</div>

<script>
(function () {
    async function llamar(url, payload) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        return resp.json();
    }

    function fila(contenedorId, id, html) {
        const div = document.createElement('div');
        div.className = 'catalog-item';
        div.dataset.id = id;
        div.innerHTML = html;
        document.getElementById(contenedorId).appendChild(div);
        return div;
    }

    document.getElementById('formNuevoDescuento').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const input = document.getElementById('nuevoDescuentoNombre');
        const grupoInput = document.getElementById('nuevoDescuentoGrupo');
        const nombre = input.value.trim();
        const grupo = grupoInput.value.trim();
        if (!nombre) return;
        const data = await llamar('api/guardar_catalogo_item.php', { tipo_catalogo: 'descuento', nombre, grupo_exclusivo: grupo });
        if (data.ok) {
            const grupoHtml = grupo ? ` <em class="muted">· excluyente: ${grupo}</em>` : '';
            fila('listaDescuentos', data.id, `<span>${nombre}${grupoHtml} <span class="badge badge--uso" title="Veces utilizado en evaluaciones">Usado 0×</span></span><button type="button" class="btn-icon-del" data-tipo="descuento" data-id="${data.id}" title="Quitar">✕</button>`);
            input.value = '';
            grupoInput.value = '';
        } else {
            alert(data.error || 'No se pudo agregar.');
        }
    });

    document.getElementById('formNuevaEntidad').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const input = document.getElementById('nuevaEntidadNombre');
        const tipoSel = document.getElementById('nuevaEntidadTipo');
        const nombre = input.value.trim();
        if (!nombre) return;
        const data = await llamar('api/guardar_catalogo_item.php', { tipo_catalogo: 'entidad', nombre, tipo: tipoSel.value });
        if (data.ok) {
            const etiquetas = { banco: 'Banco', cooperativa: 'Cooperativa', caja: 'Caja', otro: 'Otro' };
            fila('listaEntidades', data.id, `<span>${nombre} <em class="muted">(${etiquetas[tipoSel.value]})</em> <span class="badge badge--uso" title="Veces utilizado en evaluaciones">Usado 0×</span></span><button type="button" class="btn-icon-del" data-tipo="entidad" data-id="${data.id}" title="Quitar">✕</button>`);
            input.value = '';
        } else {
            alert(data.error || 'No se pudo agregar.');
        }
    });

    document.body.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.btn-icon-del');
        if (!btn) return;
        if (!confirm('¿Quitar este ítem del catálogo? (No afecta evaluaciones ya guardadas)')) return;
        const data = await llamar('api/eliminar_catalogo_item.php', { tipo_catalogo: btn.dataset.tipo, id: parseInt(btn.dataset.id, 10) });
        if (data.ok) {
            btn.closest('.catalog-item').remove();
        } else {
            alert(data.error || 'No se pudo quitar.');
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
