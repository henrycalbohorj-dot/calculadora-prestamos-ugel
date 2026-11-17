<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar contraseña · Calculadora de Disponibilidad de Crédito UGEL</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">

<div class="login-watermark" aria-hidden="true">
    <img src="ugel.jpg" alt="" onerror="this.replaceWith(this.nextElementSibling); this.nextElementSibling.style.display='block';">
    <svg viewBox="0 0 400 400" width="640" height="640" style="display:none">
        <circle cx="200" cy="200" r="196" fill="none" stroke="rgba(255,255,255,0.10)" stroke-width="2"/>
        <circle cx="200" cy="200" r="168" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1.5"/>
        <path d="M200 60 L330 110 V190 C330 270 275 325 200 348 C125 325 70 270 70 190 V110 Z"
              fill="none" stroke="rgba(255,255,255,0.14)" stroke-width="2.5"/>
        <path d="M200 120 L200 250 M140 185 L260 185" stroke="rgba(255,255,255,0.14)" stroke-width="4" stroke-linecap="round"/>
        <text x="200" y="380" text-anchor="middle" fill="rgba(255,255,255,0.16)" font-size="15" font-family="Sora, sans-serif" letter-spacing="6">U G E L</text>
    </svg>
</div>

<main class="login-wrap">
    <section class="login-card">
        <div class="login-card__brand">
            <div class="brand-mark brand-mark--lg" aria-hidden="true">
                <img src="ugel.jpg" alt="" class="brand-mark__img" onerror="this.replaceWith(this.nextElementSibling); this.nextElementSibling.style.display='block';">
                <svg viewBox="0 0 48 48" width="42" height="42" style="display:none">
                    <path d="M24 3 L44 12 V22 C44 34 36 42.5 24 46 C12 42.5 4 34 4 22 V12 Z"
                          fill="url(#gradShieldRecuperar)" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>
                    <path d="M24 12 L24 30 M15 21 L33 21" stroke="#0d2138" stroke-width="2.6" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="gradShieldRecuperar" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#2dd4bf"/>
                            <stop offset="100%" stop-color="#0ea5a4"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h1>Recuperar contraseña</h1>
            <p>Verifica tus datos registrados para definir una nueva contraseña, sin necesitar ayuda del administrador.</p>
        </div>

        <div id="alertaRecuperar" class="alert alert--danger is-hidden"></div>

        <!-- Paso 1: verificar identidad -->
        <form id="formVerificar" class="login-form" autocomplete="off">
            <label class="field">
                <span>Usuario</span>
                <input type="text" id="rUsuario" placeholder="Tu usuario de ingreso" autofocus required>
            </label>
            <label class="field">
                <span>Correo electrónico registrado</span>
                <input type="email" id="rCorreo" placeholder="tucorreo@ejemplo.com" required>
            </label>
            <label class="field">
                <span>Número de celular registrado</span>
                <input type="text" id="rCelular" placeholder="Ej. 987654321" required>
            </label>
            <button type="submit" class="btn btn--primary btn--block">Verificar identidad</button>
        </form>

        <!-- Paso 2: definir nueva contraseña (oculto hasta verificar) -->
        <form id="formNuevaClave" class="login-form is-hidden" autocomplete="off">
            <p class="text-success" id="saludoVerificado" style="font-weight:700; margin: 0 0 4px;"></p>
            <label class="field">
                <span>Nueva contraseña</span>
                <input type="password" id="rNuevaPassword" placeholder="Mínimo 6 caracteres" minlength="6" required>
            </label>
            <label class="field">
                <span>Confirmar nueva contraseña</span>
                <input type="password" id="rConfirmarPassword" placeholder="Repite la contraseña" minlength="6" required>
            </label>
            <button type="submit" class="btn btn--primary btn--block">Guardar nueva contraseña</button>
        </form>

        <p class="login-hint"><a href="login.php">← Volver a Ingresar</a></p>
    </section>
</main>

<script>
(function () {
    const alerta = document.getElementById('alertaRecuperar');
    const formVerificar = document.getElementById('formVerificar');
    const formNuevaClave = document.getElementById('formNuevaClave');
    let datosVerificados = null;

    function mostrarError(msg) {
        alerta.textContent = msg;
        alerta.classList.remove('is-hidden');
    }
    function ocultarError() {
        alerta.classList.add('is-hidden');
    }

    formVerificar.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        ocultarError();
        const usuario = document.getElementById('rUsuario').value.trim();
        const correo = document.getElementById('rCorreo').value.trim();
        const celular = document.getElementById('rCelular').value.trim();

        const btn = formVerificar.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Verificando...';
        try {
            const resp = await fetch('api/verificar_identidad_usuario.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ usuario, correo, celular }),
            });
            const data = await resp.json();
            if (data.ok) {
                datosVerificados = { usuario, correo, celular };
                document.getElementById('saludoVerificado').textContent = '✓ Identidad verificada, ' + data.nombre + '. Define tu nueva contraseña:';
                formVerificar.classList.add('is-hidden');
                formNuevaClave.classList.remove('is-hidden');
                document.getElementById('rNuevaPassword').focus();
            } else {
                mostrarError(data.error || 'No se pudo verificar tu identidad.');
            }
        } catch (err) {
            mostrarError('Error de conexión: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Verificar identidad';
        }
    });

    formNuevaClave.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        ocultarError();
        const nueva = document.getElementById('rNuevaPassword').value;
        const confirmar = document.getElementById('rConfirmarPassword').value;

        if (nueva.length < 6) {
            mostrarError('La contraseña debe tener al menos 6 caracteres.');
            return;
        }
        if (nueva !== confirmar) {
            mostrarError('Las dos contraseñas no coinciden.');
            return;
        }
        if (!datosVerificados) {
            mostrarError('Vuelve a verificar tu identidad.');
            return;
        }

        const btn = formNuevaClave.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Guardando...';
        try {
            const resp = await fetch('api/restablecer_password_propio.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...datosVerificados, password: nueva }),
            });
            const data = await resp.json();
            if (data.ok) {
                alert('✓ Contraseña actualizada. Ya puedes ingresar con tu nueva contraseña.');
                window.location.href = 'login.php';
            } else {
                mostrarError(data.error || 'No se pudo actualizar la contraseña.');
            }
        } catch (err) {
            mostrarError('Error de conexión: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Guardar nueva contraseña';
        }
    });
})();
</script>

</body>
</html>
