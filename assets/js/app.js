/**
 * Calculadora de Disponibilidad de Crédito - UGEL
 * Lógica de filas dinámicas, cálculo en vivo, buscador de trabajadores,
 * exclusión AFP/ONP y guardado vía API.
 */
(function () {
    'use strict';

    // Sólo corre en páginas que tienen el formulario de la calculadora.
    const form = document.getElementById('formEvaluacion');
    if (!form) return;

    const money = (n) => 'S/ ' + (Number(n) || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const filasDescuentos = document.getElementById('filasDescuentos');
    const filasEntidades  = document.getElementById('filasEntidades');
    const tplDescuento    = document.getElementById('tplFilaDescuento');
    const tplEntidad      = document.getElementById('tplFilaEntidad');
    const chipsDescuentos = document.getElementById('chipsDescuentos');

    // Mapa nombre -> grupo excluyente (ej. "AFP" y "ONP (DL 19990)" -> "pension")
    const gruposPorNombre = {};
    (window.CATALOGO_DESCUENTOS || []).forEach((d) => {
        if (d.grupo_exclusivo) {
            gruposPorNombre[d.nombre.trim().toLowerCase()] = d.grupo_exclusivo;
        }
    });

    function agregarFila(contenedor, tpl, nombre, monto) {
        const nodo = tpl.content.firstElementChild.cloneNode(true);
        const inputNombre = nodo.querySelector('.row-item__nombre');
        const inputMonto  = nodo.querySelector('.row-item__monto');
        if (nombre) inputNombre.value = nombre;
        if (monto)  inputMonto.value = monto;

        nodo.querySelector('.row-item__del').addEventListener('click', () => {
            nodo.remove();
            recalcular();
        });
        inputMonto.addEventListener('input', recalcular);
        if (contenedor === filasDescuentos) {
            inputNombre.addEventListener('input', sincronizarGruposExclusivos);
        }
        contenedor.appendChild(nodo);
        recalcular();
        return nodo;
    }

    // Si ya existe una fila con ese nombre (ej. prefiltrada al cargar un
    // trabajador guardado), la reutiliza en vez de crear una duplicada.
    function enfocarOFilaExistente(contenedor, nombre) {
        const buscado = nombre.trim().toLowerCase();
        let encontrada = null;
        contenedor.querySelectorAll('.row-item').forEach((fila) => {
            const actual = fila.querySelector('.row-item__nombre').value.trim().toLowerCase();
            if (!encontrada && actual === buscado) encontrada = fila;
        });
        if (encontrada) {
            const inputMonto = encontrada.querySelector('.row-item__monto');
            inputMonto.focus();
            inputMonto.select();
            encontrada.classList.add('row-item--resaltada');
            setTimeout(() => encontrada.classList.remove('row-item--resaltada'), 900);
        }
        return encontrada;
    }

    function sumarFilas(contenedor) {
        let total = 0;
        const detalle = [];
        contenedor.querySelectorAll('.row-item').forEach((fila) => {
            const nombre = fila.querySelector('.row-item__nombre').value.trim();
            const monto  = parseFloat(fila.querySelector('.row-item__monto').value) || 0;
            if (nombre !== '' || monto !== 0) {
                detalle.push({ nombre: nombre || '(sin nombre)', monto });
                total += monto;
            }
        });
        return { total, detalle };
    }

    // --- Exclusión de grupos (ej. AFP vs ONP): si ya hay una fila con un
    // nombre de un grupo, se desactivan los demás chips de ese grupo. ---
    function sincronizarGruposExclusivos() {
        if (!chipsDescuentos) return;

        const nombresActivos = new Set();
        filasDescuentos.querySelectorAll('.row-item__nombre').forEach((input) => {
            const n = input.value.trim().toLowerCase();
            if (n && gruposPorNombre[n]) nombresActivos.add(n);
        });

        const gruposActivos = new Set(Array.from(nombresActivos).map((n) => gruposPorNombre[n]));

        chipsDescuentos.querySelectorAll('.chip').forEach((chip) => {
            const grupo = chip.dataset.grupo;
            const nombreChip = chip.dataset.nombre.trim().toLowerCase();
            if (!grupo) return;
            const debeDesactivar = gruposActivos.has(grupo) && !nombresActivos.has(nombreChip);
            chip.disabled = debeDesactivar;
            chip.classList.toggle('chip--disabled', debeDesactivar);
        });
    }

    // --- Remuneración adicional (opcional): se suma al sueldo bruto base ---
    function sueldoBrutoBase() {
        return parseFloat(document.getElementById('sueldoBruto').value) || 0;
    }
    function sueldoAdicionalMonto() {
        return parseFloat(document.getElementById('sueldoAdicionalMonto').value) || 0;
    }
    function sueldoBrutoTotal() {
        return sueldoBrutoBase() + sueldoAdicionalMonto();
    }

    function recalcular() {
        const sueldoBruto = sueldoBrutoTotal();
        const { total: totalDescuentos } = sumarFilas(filasDescuentos);
        const { total: totalSolicitado } = sumarFilas(filasEntidades);

        const totalNota = document.getElementById('totalConAdicional');
        if (totalNota) {
            const adicional = sueldoAdicionalMonto();
            totalNota.textContent = adicional > 0
                ? 'Total sueldo bruto (base + adicional): ' + money(sueldoBruto)
                : '';
        }

        const netoTotal = Math.max(sueldoBruto - totalDescuentos, 0);
        const neto50    = netoTotal * 0.5;
        const capacidadDisponible = neto50 - totalSolicitado;
        const excede = totalSolicitado > neto50 + 0.001;

        document.getElementById('rSueldoBruto').textContent       = money(sueldoBruto);
        document.getElementById('rTotalDescuentos').textContent   = '− ' + money(totalDescuentos);
        document.getElementById('rNetoTotal').textContent         = money(netoTotal);
        document.getElementById('rNeto50').textContent            = money(neto50);
        document.getElementById('rTotalSolicitado').textContent   = '− ' + money(totalSolicitado);
        document.getElementById('rCapacidadDisponible').textContent = money(capacidadDisponible);

        const caja = document.getElementById('cajaCapacidadDisponible');
        const nota = document.getElementById('notaLimite');
        caja.classList.toggle('summary-result--danger', excede || capacidadDisponible < 0);
        caja.classList.toggle('summary-result--ok', !excede && capacidadDisponible >= 0);

        nota.textContent = excede
            ? '⚠ El monto solicitado supera el 50% del sueldo neto permitido.'
            : '✓ Dentro del límite permitido (50% del sueldo neto).';
        nota.classList.toggle('text-danger', excede);
        nota.classList.toggle('text-success', !excede);

        sincronizarGruposExclusivos();
    }

    // --- Botones para agregar filas manualmente ---
    document.getElementById('btnAgregarDescuento').addEventListener('click', () => {
        agregarFila(filasDescuentos, tplDescuento, '', '');
    });
    document.getElementById('btnAgregarEntidad').addEventListener('click', () => {
        agregarFila(filasEntidades, tplEntidad, '', '');
    });

    // --- Chips rápidos del catálogo ---
    chipsDescuentos.addEventListener('click', (ev) => {
        const chip = ev.target.closest('.chip');
        if (!chip || chip.disabled) return;
        if (enfocarOFilaExistente(filasDescuentos, chip.dataset.nombre)) return;
        agregarFila(filasDescuentos, tplDescuento, chip.dataset.nombre, chip.dataset.valor);
    });
    document.getElementById('chipsEntidades').addEventListener('click', (ev) => {
        const chip = ev.target.closest('.chip');
        if (!chip) return;
        if (enfocarOFilaExistente(filasEntidades, chip.dataset.nombre)) return;
        agregarFila(filasEntidades, tplEntidad, chip.dataset.nombre, '');
    });

    document.getElementById('sueldoBruto').addEventListener('input', recalcular);
    document.getElementById('sueldoAdicionalMonto').addEventListener('input', recalcular);

    // --- Mostrar/ocultar el bloque de remuneración adicional (opcional) ---
    const bloqueAdicionalToggle = document.getElementById('bloqueAdicionalToggle');
    const bloqueAdicionalCampos = document.getElementById('bloqueAdicionalCampos');
    document.getElementById('btnAgregarAdicional').addEventListener('click', () => {
        bloqueAdicionalToggle.classList.add('is-hidden');
        bloqueAdicionalCampos.classList.remove('is-hidden');
        document.getElementById('sueldoAdicionalConcepto').focus();
    });
    document.getElementById('btnQuitarAdicional').addEventListener('click', () => {
        document.getElementById('sueldoAdicionalConcepto').value = '';
        document.getElementById('sueldoAdicionalMonto').value = '';
        bloqueAdicionalCampos.classList.add('is-hidden');
        bloqueAdicionalToggle.classList.remove('is-hidden');
        recalcular();
    });

    // --- Tipo de personal (Administrativo / Docente) ---
    function tipoPersonalActual() {
        const marcado = document.querySelector('input[name="tipoPersonal"]:checked');
        return marcado ? marcado.value : 'administrativo';
    }
    function fijarTipoPersonal(valor) {
        const input = document.querySelector('input[name="tipoPersonal"][value="' + valor + '"]');
        if (input) input.checked = true;
    }

    // --- Buscador de trabajadores guardados (lupa) ---
    const inputBuscar   = document.getElementById('buscarTrabajador');
    const btnBuscar     = document.getElementById('btnBuscarTrabajador');
    const cajaResultados = document.getElementById('resultadosBusqueda');
    let temporizadorBusqueda = null;

    function ocultarResultados() {
        cajaResultados.innerHTML = '';
        cajaResultados.classList.add('is-hidden');
    }

    async function buscarTrabajadores() {
        const q = inputBuscar.value.trim();
        if (q.length < 2) { ocultarResultados(); return; }

        try {
            const resp = await fetch('api/buscar_trabajador.php?q=' + encodeURIComponent(q));
            const data = await resp.json();
            if (!data.ok || !data.resultados.length) {
                cajaResultados.innerHTML = '<div class="worker-search__empty">Sin coincidencias — se guardará como trabajador nuevo.</div>';
                cajaResultados.classList.remove('is-hidden');
                return;
            }
            cajaResultados.innerHTML = data.resultados.map((t) => `
                <button type="button" class="worker-search__item" data-id="${t.id}">
                    <strong>${escaparHtml(t.nombres)}</strong>
                    <span>${t.dni ? 'DNI ' + escaparHtml(t.dni) : 'Sin DNI'} · ${t.tipo_personal === 'docente' ? 'Docente' : 'Administrativo'}${t.dependencia ? ' · ' + escaparHtml(t.dependencia) : ''}</span>
                </button>
            `).join('');
            cajaResultados.classList.remove('is-hidden');
        } catch (err) {
            ocultarResultados();
        }
    }

    function escaparHtml(s) {
        const div = document.createElement('div');
        div.textContent = s || '';
        return div.innerHTML;
    }

    async function cargarTrabajador(id) {
        try {
            const resp = await fetch('api/obtener_trabajador.php?id=' + encodeURIComponent(id));
            const data = await resp.json();
            if (!data.ok) { alert(data.error || 'No se pudo cargar el trabajador.'); return; }

            const t = data.trabajador;
            document.getElementById('trabajadorNombre').value = t.nombres || '';
            document.getElementById('trabajadorDni').value = t.dni || '';
            document.getElementById('trabajadorArea').value = t.dependencia || '';
            fijarTipoPersonal(t.tipo_personal || 'administrativo');

            if (data.ultimo_sueldo_bruto) {
                document.getElementById('sueldoBruto').value = data.ultimo_sueldo_bruto;
            }
            // La remuneración adicional se recuerda de la última evaluación
            // de este trabajador. Si no tenía (o se quitó y se guardó así
            // la última vez), simplemente no hay nada que precargar.
            if (data.sueldo_adicional_monto) {
                document.getElementById('sueldoAdicionalConcepto').value = data.sueldo_adicional_concepto || '';
                document.getElementById('sueldoAdicionalMonto').value = data.sueldo_adicional_monto;
                bloqueAdicionalCampos.classList.remove('is-hidden');
                bloqueAdicionalToggle.classList.add('is-hidden');
            } else {
                document.getElementById('sueldoAdicionalConcepto').value = '';
                document.getElementById('sueldoAdicionalMonto').value = '';
                bloqueAdicionalCampos.classList.add('is-hidden');
                bloqueAdicionalToggle.classList.remove('is-hidden');
            }

            filasDescuentos.innerHTML = '';
            (data.descuentos || []).forEach((d) => {
                agregarFila(filasDescuentos, tplDescuento, d.nombre, d.monto);
            });

            filasEntidades.innerHTML = '';
            (data.entidades || []).forEach((e) => {
                agregarFila(filasEntidades, tplEntidad, e.nombre, e.monto);
            });

            inputBuscar.value = '';
            ocultarResultados();
            recalcular();

            const alerta = document.getElementById('alertaGuardado');
            alerta.textContent = '✓ Datos de "' + t.nombres + '" cargados desde su última evaluación. Revisa los montos antes de guardar.';
            alerta.className = 'alert alert--success no-print';
            alerta.classList.remove('is-hidden');
        } catch (err) {
            alert('Error al cargar el trabajador: ' + err.message);
        }
    }

    if (btnBuscar) btnBuscar.addEventListener('click', buscarTrabajadores);
    if (inputBuscar) {
        inputBuscar.addEventListener('input', () => {
            clearTimeout(temporizadorBusqueda);
            temporizadorBusqueda = setTimeout(buscarTrabajadores, 350);
        });
        inputBuscar.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); buscarTrabajadores(); }
        });
    }
    if (cajaResultados) {
        cajaResultados.addEventListener('click', (ev) => {
            const item = ev.target.closest('.worker-search__item');
            if (!item) return;
            cargarTrabajador(item.dataset.id);
        });
    }
    document.addEventListener('click', (ev) => {
        if (!ev.target.closest('.worker-search')) ocultarResultados();
    });

    // --- Nueva evaluación (limpiar todo) ---
    document.getElementById('btnNuevo').addEventListener('click', () => {
        if (!confirm('¿Iniciar una nueva evaluación? Se perderán los datos no guardados.')) return;
        form.reset();
        filasDescuentos.innerHTML = '';
        filasEntidades.innerHTML = '';
        fijarTipoPersonal('administrativo');
        bloqueAdicionalCampos.classList.add('is-hidden');
        bloqueAdicionalToggle.classList.remove('is-hidden');
        ocultarResultados();
        document.getElementById('alertaGuardado').classList.add('is-hidden');
        recalcular();
    });

    // --- Imprimir ---
    document.getElementById('btnImprimir').addEventListener('click', () => {
        prepararObservacionesParaImprimir();
        window.print();
    });

    const printFecha = document.getElementById('printFecha');
    if (printFecha) {
        printFecha.textContent = 'Fecha: ' + new Date().toLocaleDateString('es-PE', { year: 'numeric', month: 'long', day: 'numeric' });
    }

    // La caja de "Observaciones" tiene 3 filas fijas en pantalla (con
    // scroll); al imprimir se reemplaza por un párrafo que crece con el
    // texto real, para no cortar contenido ni desperdiciar espacio.
    function prepararObservacionesParaImprimir() {
        const obs = document.getElementById('observaciones');
        const espejo = document.getElementById('observacionesImpresion');
        if (!obs || !espejo) return;
        espejo.textContent = obs.value.trim();
    }
    window.addEventListener('beforeprint', prepararObservacionesParaImprimir);

    // --- Guardar evaluación vía API ---
    document.getElementById('btnGuardar').addEventListener('click', async () => {
        const nombre = document.getElementById('trabajadorNombre').value.trim();
        const sueldoBase = sueldoBrutoBase();

        if (!nombre) {
            alert('Ingresa el nombre del trabajador antes de guardar.');
            document.getElementById('trabajadorNombre').focus();
            return;
        }
        if (sueldoBase <= 0) {
            alert('Ingresa el sueldo bruto antes de guardar.');
            document.getElementById('sueldoBruto').focus();
            return;
        }

        const descuentos = sumarFilas(filasDescuentos).detalle;
        const entidades  = sumarFilas(filasEntidades).detalle.map(d => ({ nombre: d.nombre, monto: d.monto }));

        const payload = {
            trabajador_nombre: nombre,
            trabajador_dni: document.getElementById('trabajadorDni').value.trim(),
            trabajador_dependencia: document.getElementById('trabajadorArea').value.trim(),
            tipo_personal: tipoPersonalActual(),
            sueldo_bruto_base: sueldoBase,
            sueldo_adicional_concepto: document.getElementById('sueldoAdicionalConcepto').value.trim(),
            sueldo_adicional_monto: sueldoAdicionalMonto(),
            observaciones: document.getElementById('observaciones').value.trim(),
            descuentos: descuentos,
            entidades: entidades,
        };

        const btn = document.getElementById('btnGuardar');
        const textoOriginal = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Guardando...';

        try {
            const resp = await fetch('api/guardar_evaluacion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await resp.json();

            const alerta = document.getElementById('alertaGuardado');
            if (resp.ok && data.ok) {
                const idGuardado = parseInt(data.id, 10) || 0;
                alerta.innerHTML = '<span>✓ Evaluación guardada correctamente (folio #' + idGuardado + ').</span>'
                    + (idGuardado > 0
                        ? '<a class="alert__accion" href="detalle.php?id=' + idGuardado + '&autoprint=1" target="_blank" rel="noopener">🖨 Imprimir esta evaluación</a>'
                        : '');
                alerta.className = 'alert alert--success alert--conAccion no-print';
            } else {
                alerta.textContent = '✕ ' + (data.error || 'No se pudo guardar la evaluación.');
                alerta.className = 'alert alert--danger no-print';
            }
            alerta.classList.remove('is-hidden');
            alerta.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (err) {
            alert('Error de conexión al guardar: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = textoOriginal;
        }
    });

    recalcular();
})();
