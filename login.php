<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($usuario === '' || $password === '') {
        $error = 'Ingresa tu usuario y contraseña.';
    } elseif (intentarLogin($usuario, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresar · Calculadora de Disponibilidad de Crédito UGEL</title>
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
                          fill="url(#gradShieldLogin)" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>
                    <path d="M24 12 L24 30 M15 21 L33 21" stroke="#0d2138" stroke-width="2.6" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="gradShieldLogin" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#2dd4bf"/>
                            <stop offset="100%" stop-color="#0ea5a4"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <h1>Disponibilidad de Crédito</h1>
            <p>Calculadora institucional para evaluación de préstamos del personal — UGEL</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert--danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="login-form" autocomplete="off">
            <label class="field">
                <span>Usuario</span>
                <input type="text" name="usuario" placeholder="admin" autofocus required>
            </label>
            <label class="field">
                <span>Contraseña</span>
                <input type="password" name="password" placeholder="••••••••" required>
            </label>
            <button type="submit" class="btn btn--primary btn--block">Ingresar</button>
        </form>

        <p class="login-hint"><a href="recuperar_password.php">¿Olvidaste tu contraseña?</a></p>
        <p class="login-hint">Usuario inicial: <code>admin</code> / <code>admin123</code> — cámbialo después de instalar.</p>
    </section>
</main>

</body>
</html>
