<?php
require_once __DIR__ . '/includes/auth.php';
exigirSesion();

$pdo = obtenerConexion();

// Los más usados primero: así los accesos rápidos siempre muestran lo
// que el personal de RRHH realmente usa con más frecuencia.
$descuentosCatalogo = $pdo->query(
    'SELECT id, nombre, valor_sugerido, grupo_exclusivo
     FROM descuentos_catalogo
     WHERE activo = TRUE
     ORDER BY veces_usado DESC, orden, nombre'
)->fetchAll();

$entidadesCatalogo = $pdo->query(
    'SELECT id, nombre, tipo
     FROM entidades_catalogo
     WHERE activo = TRUE
     ORDER BY veces_usado DESC, orden, nombre'
)->fetchAll();

$tituloPagina = 'Calculadora';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Evaluación de disponibilidad de crédito</h1>
        <p class="muted">Calcula cuánto puede solicitar un trabajador en bancos y cooperativas sin exceder el 50% de su sueldo neto.</p>
    </div>
    <div class="page-head__actions no-print">
        <button type="button" class="btn btn--ghost" id="btnNuevo">Nueva evaluación</button>
        <button type="button" class="btn btn--ghost" id="btnImprimir">Imprimir</button>
        <button type="button" class="btn btn--primary" id="btnGuardar">Guardar evaluación</button>
    </div>
</div>

<div id="alertaGuardado" class="alert alert--success is-hidden no-print" role="status"></div>

<!-- Membrete solo visible al imprimir -->
<div class="print-letterhead print-only">
    <img src="ugel.jpg" alt="UGEL" class="print-letterhead__logo">
    <div class="print-letterhead__text">
        <strong>Unidad de Gestión Educativa Local</strong>
        <span>Evaluación de Disponibilidad de Crédito — Uso interno de RRHH / T.I.</span>
    </div>
    <div class="print-letterhead__fecha" id="printFecha"></div>
</div>

<form id="formEvaluacion" class="calc-grid" autocomplete="off">

    <div class="calc-main">

        <!-- ============== DATOS DEL TRABAJADOR ============== -->
        <section class="card">
            <header class="card__header">
                <h2>1. Datos del trabajador</h2>
            </header>
            <div class="card__body">

                <div class="worker-search no-print">
                    <label class="field">
                        <span>Buscar trabajador ya registrado (por nombre o DNI)</span>
                        <div class="worker-search__box">
                            <input type="text" id="buscarTrabajador" placeholder="Escribe un nombre o DNI...">
                            <button type="button" id="btnBuscarTrabajador" class="btn-search" title="Buscar">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            </button>
                        </div>
                        <div id="resultadosBusqueda" class="worker-search__results is-hidden"></div>
                    </label>
                    <p class="muted worker-search__hint">Si no aparece, simplemente completa los datos abajo — se guardará como nuevo.</p>
                </div>

                <div class="grid-3">
                    <label class="field">
                        <span>Nombres y apellidos</span>
                        <input type="text" id="trabajadorNombre" placeholder="Ej. Juan Pérez Gómez" required>
                    </label>
                    <label class="field">
                        <span>DNI</span>
                        <input type="text" id="trabajadorDni" placeholder="Ej. 12345678" maxlength="15">
                    </label>
                    <label class="field">
                        <span>Área / Dependencia</span>
                        <input type="text" id="trabajadorArea" placeholder="Ej. Dirección / UGEL">
                    </label>
                </div>

                <div class="field field--tipo-personal">
                    <span>Tipo de personal</span>
                    <div class="radio-group" id="grupoTipoPersonal">
                        <label class="radio-pill">
                            <input type="radio" name="tipoPersonal" value="administrativo" checked>
                            Personal Administrativo
                        </label>
                        <label class="radio-pill">
                            <input type="radio" name="tipoPersonal" value="docente">
                            Docente
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============== SUELDO BRUTO Y DESCUENTOS ============== -->
        <section class="card">
            <header class="card__header">
                <h2>2. Sueldo bruto y descuentos de ley</h2>
            </header>
            <div class="card__body">
                <label class="field field--money field--lg">
                    <span>Sueldo bruto mensual</span>
                    <div class="input-money">
                        <span>S/</span>
                        <input type="number" id="sueldoBruto" min="0" step="0.01" placeholder="0.00" required>
                    </div>
                </label>

                <div class="extra-remuneracion no-print" id="bloqueAdicionalToggle">
                    <button type="button" class="btn btn--dashed" id="btnAgregarAdicional">+ Agregar remuneración por otro concepto</button>
                </div>
                <div class="extra-remuneracion-campos is-hidden" id="bloqueAdicionalCampos">
                    <div class="grid-2">
                        <label class="field">
                            <span>Concepto adicional</span>
                            <input type="text" id="sueldoAdicionalConcepto" placeholder="Ej. Bonificación por función">
                        </label>
                        <label class="field field--money">
                            <span>Monto adicional</span>
                            <div class="input-money">
                                <span>S/</span>
                                <input type="number" id="sueldoAdicionalMonto" min="0" step="0.01" placeholder="0.00">
                            </div>
                        </label>
                    </div>
                    <button type="button" class="btn-quitar-adicional no-print" id="btnQuitarAdicional">✕ Quitar remuneración adicional</button>
                    <p class="muted extra-remuneracion__total" id="totalConAdicional"></p>
                </div>

                <div class="rows-head">
                    <span>Concepto</span>
                    <span>Monto</span>
                    <span></span>
                </div>
                <div id="filasDescuentos" class="rows"></div>

                <div class="row-actions no-print">
                    <div class="chips" id="chipsDescuentos">
                        <?php foreach ($descuentosCatalogo as $d): ?>
                        <button type="button" class="chip" data-nombre="<?= htmlspecialchars($d['nombre']) ?>" data-valor="<?= htmlspecialchars($d['valor_sugerido']) ?>" data-grupo="<?= htmlspecialchars((string)$d['grupo_exclusivo']) ?>">+ <?= htmlspecialchars($d['nombre']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn--dashed" id="btnAgregarDescuento">+ Agregar otro descuento</button>
                    <p class="muted chip-hint">Nota: AFP y ONP son excluyentes — al elegir uno se desactiva el otro, porque solo se descuenta por un sistema pensionario.</p>
                </div>
            </div>
        </section>

        <!-- ============== ENTIDADES FINANCIERAS ============== -->
        <section class="card">
            <header class="card__header">
                <h2>3. Entidades financieras solicitadas</h2>
                <p class="muted">Bancos y cooperativas donde el trabajador está solicitando (o ya tiene) un préstamo.</p>
            </header>
            <div class="card__body">
                <div class="rows-head">
                    <span>Entidad</span>
                    <span>Monto solicitado</span>
                    <span></span>
                </div>
                <div id="filasEntidades" class="rows"></div>

                <div class="row-actions no-print">
                    <div class="chips" id="chipsEntidades">
                        <?php foreach ($entidadesCatalogo as $e): ?>
                        <button type="button" class="chip" data-nombre="<?= htmlspecialchars($e['nombre']) ?>" data-tipo="<?= htmlspecialchars($e['tipo']) ?>">+ <?= htmlspecialchars($e['nombre']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn--dashed" id="btnAgregarEntidad">+ Agregar otra entidad</button>
                </div>
            </div>
        </section>

        <section class="card">
            <header class="card__header">
                <h2>4. Observaciones</h2>
            </header>
            <div class="card__body">
                <textarea id="observaciones" rows="3" placeholder="Notas adicionales sobre esta evaluación (opcional)"></textarea>
                <p class="print-only observaciones-print" id="observacionesImpresion"></p>
            </div>
        </section>

    </div>

    <!-- ============== PANEL RESUMEN ============== -->
    <aside class="calc-summary">
        <div class="summary-card">
            <h3>Resumen del cálculo</h3>

            <div class="summary-line">
                <span>Sueldo bruto</span>
                <strong id="rSueldoBruto">S/ 0.00</strong>
            </div>
            <div class="summary-line">
                <span>Total descuentos de ley</span>
                <strong id="rTotalDescuentos" class="text-danger">− S/ 0.00</strong>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-line summary-line--big">
                <span>Sueldo neto total (100%)</span>
                <strong id="rNetoTotal">S/ 0.00</strong>
            </div>

            <div class="summary-highlight">
                <span>Capacidad máxima disponible (50% del neto)</span>
                <strong id="rNeto50">S/ 0.00</strong>
            </div>

            <div class="summary-divider"></div>

            <div class="summary-line">
                <span>Total solicitado en entidades</span>
                <strong id="rTotalSolicitado" class="text-danger">− S/ 0.00</strong>
            </div>

            <div class="summary-result" id="cajaCapacidadDisponible">
                <span>Capacidad disponible para crédito</span>
                <strong id="rCapacidadDisponible">S/ 0.00</strong>
            </div>

            <p class="summary-note" id="notaLimite"></p>
        </div>

        <div class="summary-card summary-card--muted no-print">
            <h4>Regla aplicada</h4>
            <p>El monto total solicitado en bancos y cooperativas no puede superar el <strong>50% del sueldo neto total</strong> del trabajador.</p>
        </div>
    </aside>

</form>

<!-- Firmas: solo visibles al imprimir -->
<div class="print-firmas print-only">
    <div class="print-firmas__linea"><span></span><small>Elaborado por (RRHH / T.I.)</small></div>
    <div class="print-firmas__linea"><span></span><small>V°B° Jefe de Personal</small></div>
    <div class="print-firmas__linea"><span></span><small>Trabajador (recibí conforme)</small></div>
</div>

<!-- Plantillas para filas dinámicas (JS las clona) -->
<template id="tplFilaDescuento">
    <div class="row-item" data-row="descuento">
        <input type="text" class="row-item__nombre" placeholder="Nombre del descuento" required>
        <div class="input-money input-money--sm">
            <span>S/</span>
            <input type="number" class="row-item__monto" min="0" step="0.01" placeholder="0.00">
        </div>
        <button type="button" class="row-item__del no-print" title="Quitar">✕</button>
    </div>
</template>

<template id="tplFilaEntidad">
    <div class="row-item" data-row="entidad">
        <input type="text" class="row-item__nombre" placeholder="Nombre del banco / cooperativa" required>
        <div class="input-money input-money--sm">
            <span>S/</span>
            <input type="number" class="row-item__monto" min="0" step="0.01" placeholder="0.00">
        </div>
        <button type="button" class="row-item__del no-print" title="Quitar">✕</button>
    </div>
</template>

<script>
    // Catálogo de descuentos con su grupo excluyente (ej. AFP/ONP), para
    // que app.js pueda desactivar automáticamente el otro chip.
    window.CATALOGO_DESCUENTOS = <?= json_encode($descuentosCatalogo, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
