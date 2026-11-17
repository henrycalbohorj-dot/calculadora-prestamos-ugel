<?php
require_once __DIR__ . '/includes/auth.php';
exigirAdmin();

$pdo = obtenerConexion();

$usuarios = $pdo->query(
    'SELECT id, nombre_completo, usuario, rol, activo, correo, celular, direccion, cargo, area, creado_en
     FROM usuarios
     ORDER BY activo DESC, nombre_completo'
)->fetchAll();

$tituloPagina = 'Usuarios';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Usuarios del sistema</h1>
        <p class="muted">Crea un usuario y contraseña para cada persona que va a ingresar a la calculadora desde su propia PC.</p>
    </div>
</div>

<div class="calc-grid calc-grid--split">
    <section class="card">
        <header class="card__header">
            <h2>Usuarios registrados</h2>
            <p class="muted">Los <strong>administradores</strong> también pueden entrar a Catálogos y Usuarios. Los <strong>operadores</strong> solo usan la Calculadora e Historial. El correo y celular son los datos con los que cada quien puede recuperar su propia contraseña.</p>
        </header>
        <div class="card__body">
            <div id="listaUsuarios" class="catalog-list catalog-list--usuarios">
                <?php foreach ($usuarios as $u): ?>
                <div class="user-item-wrap" data-id="<?= (int)$u['id'] ?>">
                    <div class="user-item <?= $u['activo'] ? '' : 'user-item--inactivo' ?>">
                        <div class="user-item__info">
                            <strong><?= htmlspecialchars($u['nombre_completo']) ?></strong>
                            <span class="muted">usuario: <?= htmlspecialchars($u['usuario']) ?></span>
                            <span class="muted">
                                <?= htmlspecialchars($u['cargo'] ?: '—') ?><?= $u['area'] ? ' · ' . htmlspecialchars($u['area']) : '' ?>
                            </span>
                            <span class="muted">
                                <?= htmlspecialchars($u['correo'] ?: 'sin correo') ?> · <?= htmlspecialchars($u['celular'] ?: 'sin celular') ?>
                            </span>
                        </div>
                        <div class="user-item__badges">
                            <span class="badge <?= $u['rol'] === 'admin' ? 'badge--tipo-admin' : 'badge--tipo-operador' ?>" data-rol-badge>
                                <?= $u['rol'] === 'admin' ? 'Administrador' : 'Operador' ?>
                            </span>
                            <span class="badge <?= $u['activo'] ? 'badge--success' : 'badge--danger' ?>" data-estado-badge>
                                <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </div>
                        <div class="user-item__acciones no-print">
                            <button type="button" class="btn btn--ghost btn--sm" data-accion="editar">Editar</button>
                            <button type="button" class="btn btn--ghost btn--sm" data-accion="rol">
                                Hacer <?= $u['rol'] === 'admin' ? 'operador' : 'admin' ?>
                            </button>
                            <button type="button" class="btn btn--ghost btn--sm" data-accion="password">Restablecer contraseña</button>
                            <button type="button" class="btn btn--ghost btn--sm <?= $u['activo'] ? 'btn--peligro' : '' ?>" data-accion="estado">
                                <?= $u['activo'] ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </div>
                    </div>

                    <form class="user-item__editar is-hidden" data-form-editar>
                        <div class="grid-2">
                            <label class="field">
                                <span>Nombres y apellidos</span>
                                <input type="text" data-campo="nombre_completo" value="<?= htmlspecialchars($u['nombre_completo']) ?>" required>
                            </label>
                            <label class="field">
                                <span>Correo electrónico</span>
                                <input type="email" data-campo="correo" value="<?= htmlspecialchars($u['correo'] ?? '') ?>" required>
                            </label>
                            <label class="field">
                                <span>Celular</span>
                                <input type="text" data-campo="celular" value="<?= htmlspecialchars($u['celular'] ?? '') ?>" required>
                            </label>
                            <label class="field">
                                <span>Cargo</span>
                                <input type="text" data-campo="cargo" value="<?= htmlspecialchars($u['cargo'] ?? '') ?>" placeholder="Ej. Especialista administrativo">
                            </label>
                            <label class="field">
                                <span>Área</span>
                                <input type="text" data-campo="area" value="<?= htmlspecialchars($u['area'] ?? '') ?>" placeholder="Ej. Recursos Humanos">
                            </label>
                            <label class="field">
                                <span>Dirección</span>
                                <input type="text" data-campo="direccion" value="<?= htmlspecialchars($u['direccion'] ?? '') ?>" placeholder="Opcional">
                            </label>
                        </div>
                        <div class="user-item__editar-acciones">
                            <button type="submit" class="btn btn--primary btn--sm">Guardar cambios</button>
                            <button type="button" class="btn btn--ghost btn--sm" data-accion="cancelar-editar">Cancelar</button>
                            <span class="muted" data-nota-editar></span>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <header class="card__header"><h2>Nuevo usuario</h2></header>
        <div class="card__body">
            <form id="formNuevoUsuario" class="form-vertical">
                <label class="field">
                    <span>Nombres y apellidos</span>
                    <input type="text" id="nuevoNombreCompleto" placeholder="Ej. María López Ríos" required>
                </label>
                <label class="field">
                    <span>Usuario (para ingresar)</span>
                    <input type="text" id="nuevoUsuarioLogin" placeholder="Ej. mlopez" required>
                </label>
                <label class="field">
                    <span>Contraseña</span>
                    <input type="text" id="nuevoUsuarioPassword" placeholder="Mínimo 6 caracteres" required minlength="6">
                </label>
                <label class="field">
                    <span>Correo electrónico</span>
                    <input type="email" id="nuevoUsuarioCorreo" placeholder="tucorreo@ejemplo.com" required>
                </label>
                <label class="field">
                    <span>Celular</span>
                    <input type="text" id="nuevoUsuarioCelular" placeholder="Ej. 987654321" required>
                </label>
                <p class="muted" style="margin: -6px 0 0; font-size: 12px;">Correo y celular son obligatorios: con esos datos el usuario podrá recuperar su propia contraseña más adelante.</p>
                <label class="field">
                    <span>Cargo</span>
                    <input type="text" id="nuevoUsuarioCargo" placeholder="Ej. Especialista administrativo">
                </label>
                <label class="field">
                    <span>Área</span>
                    <input type="text" id="nuevoUsuarioArea" placeholder="Ej. Recursos Humanos">
                </label>
                <label class="field">
                    <span>Dirección</span>
                    <input type="text" id="nuevoUsuarioDireccion" placeholder="Opcional">
                </label>
                <label class="field">
                    <span>Rol</span>
                    <select id="nuevoUsuarioRol">
                        <option value="operador" selected>Operador (Calculadora e Historial)</option>
                        <option value="admin">Administrador (todo, incluido Catálogos y Usuarios)</option>
                    </select>
                </label>
                <button type="submit" class="btn btn--primary">Crear usuario</button>
                <p class="muted" id="notaNuevoUsuario"></p>
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

    document.getElementById('formNuevoUsuario').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const nombre = document.getElementById('nuevoNombreCompleto').value.trim();
        const usuario = document.getElementById('nuevoUsuarioLogin').value.trim();
        const password = document.getElementById('nuevoUsuarioPassword').value;
        const correo = document.getElementById('nuevoUsuarioCorreo').value.trim();
        const celular = document.getElementById('nuevoUsuarioCelular').value.trim();
        const cargo = document.getElementById('nuevoUsuarioCargo').value.trim();
        const area = document.getElementById('nuevoUsuarioArea').value.trim();
        const direccion = document.getElementById('nuevoUsuarioDireccion').value.trim();
        const rol = document.getElementById('nuevoUsuarioRol').value;
        const nota = document.getElementById('notaNuevoUsuario');
        nota.textContent = '';

        const data = await llamar('api/guardar_usuario.php', {
            nombre_completo: nombre, usuario, password, rol,
            correo, celular, cargo, area, direccion,
        });
        if (data.ok) {
            nota.textContent = '✓ Usuario creado. Ya puede ingresar con "' + usuario + '".';
            nota.className = 'muted text-success';
            setTimeout(() => window.location.reload(), 900);
        } else {
            nota.textContent = '✕ ' + (data.error || 'No se pudo crear el usuario.');
            nota.className = 'muted text-danger';
        }
    });

    document.getElementById('listaUsuarios').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-accion]');
        if (!btn) return;
        const wrap = btn.closest('.user-item-wrap');
        const item = wrap.querySelector('.user-item');
        const editarForm = wrap.querySelector('[data-form-editar]');
        const id = parseInt(wrap.dataset.id, 10);
        const accion = btn.dataset.accion;

        if (accion === 'editar') {
            editarForm.classList.remove('is-hidden');
        } else if (accion === 'cancelar-editar') {
            editarForm.classList.add('is-hidden');
        } else if (accion === 'rol') {
            const esAdminAhora = item.querySelector('[data-rol-badge]').textContent.trim() === 'Administrador';
            const nuevoRol = esAdminAhora ? 'operador' : 'admin';
            if (!confirm('¿Cambiar este usuario a "' + (nuevoRol === 'admin' ? 'Administrador' : 'Operador') + '"?')) return;
            const data = await llamar('api/actualizar_usuario.php', { id, rol: nuevoRol });
            if (data.ok) { window.location.reload(); } else { alert(data.error || 'No se pudo cambiar el rol.'); }

        } else if (accion === 'estado') {
            const activoAhora = !item.classList.contains('user-item--inactivo');
            const accionTexto = activoAhora ? 'desactivar' : 'activar';
            if (!confirm('¿Seguro que quieres ' + accionTexto + ' este usuario?')) return;
            const data = await llamar('api/actualizar_usuario.php', { id, activo: !activoAhora });
            if (data.ok) { window.location.reload(); } else { alert(data.error || 'No se pudo cambiar el estado.'); }

        } else if (accion === 'password') {
            const nueva = prompt('Nueva contraseña para este usuario (mínimo 6 caracteres):');
            if (!nueva) return;
            if (nueva.length < 6) { alert('La contraseña debe tener al menos 6 caracteres.'); return; }
            const data = await llamar('api/actualizar_usuario.php', { id, password: nueva });
            if (data.ok) { alert('✓ Contraseña actualizada.'); } else { alert(data.error || 'No se pudo actualizar la contraseña.'); }
        }
    });

    document.getElementById('listaUsuarios').addEventListener('submit', async (ev) => {
        const form = ev.target.closest('[data-form-editar]');
        if (!form) return;
        ev.preventDefault();
        const wrap = form.closest('.user-item-wrap');
        const id = parseInt(wrap.dataset.id, 10);
        const nota = form.querySelector('[data-nota-editar]');
        nota.textContent = '';

        const payload = { id };
        form.querySelectorAll('[data-campo]').forEach((input) => {
            payload[input.dataset.campo] = input.value.trim();
        });

        const data = await llamar('api/actualizar_usuario.php', payload);
        if (data.ok) {
            window.location.reload();
        } else {
            nota.textContent = '✕ ' + (data.error || 'No se pudo guardar.');
            nota.className = 'muted text-danger';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
