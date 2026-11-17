<?php
/**
 * Encabezado común de todas las páginas protegidas.
 * Espera (opcionalmente) que la página defina $tituloPagina antes de incluirlo.
 */
$tituloPagina = $tituloPagina ?? 'Calculadora de Disponibilidad de Crédito';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($tituloPagina) ?> · UGEL</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <div class="app-watermark no-print" aria-hidden="true">
        <img src="ugel.jpg" alt="" onerror="this.parentElement.style.display='none';">
    </div>

    <header class="topbar no-print">
        <div class="topbar__brand">
            <div class="brand-mark" aria-hidden="true">
                <img src="ugel.jpg" alt="" class="brand-mark__img" onerror="this.replaceWith(this.nextElementSibling); this.nextElementSibling.style.display='block';">
                <svg viewBox="0 0 48 48" width="34" height="34" style="display:none">
                    <path d="M24 3 L44 12 V22 C44 34 36 42.5 24 46 C12 42.5 4 34 4 22 V12 Z"
                          fill="url(#gradShield)" stroke="rgba(255,255,255,0.35)" stroke-width="1"/>
                    <path d="M24 12 L24 30 M15 21 L33 21" stroke="#0d2138" stroke-width="2.6" stroke-linecap="round"/>
                    <defs>
                        <linearGradient id="gradShield" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#2dd4bf"/>
                            <stop offset="100%" stop-color="#0ea5a4"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <div class="brand-text">
                <strong>UGEL</strong>
                <span>Disponibilidad de Crédito</span>
            </div>
        </div>

        <nav class="topbar__nav">
            <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'is-active' : '' ?>">Calculadora</a>
            <a href="historial.php" class="<?= basename($_SERVER['PHP_SELF']) === 'historial.php' ? 'is-active' : '' ?>">Historial</a>
            <a href="solicitudes/">Solicitudes</a>
            <?php if (($_SESSION['usuario_rol'] ?? '') === 'admin'): ?>
            <a href="catalogos.php" class="<?= basename($_SERVER['PHP_SELF']) === 'catalogos.php' ? 'is-active' : '' ?>">Catálogos</a>
            <a href="usuarios.php" class="<?= basename($_SERVER['PHP_SELF']) === 'usuarios.php' ? 'is-active' : '' ?>">Usuarios</a>
            <?php endif; ?>
        </nav>

        <div class="topbar__user">
            <div class="user-chip">
                <span class="user-chip__avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['usuario_nombre'] ?? '?', 0, 1))) ?></span>
                <span class="user-chip__name"><?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '') ?></span>
            </div>
            <a href="logout.php" class="btn btn--ghost btn--sm">Salir</a>
        </div>
    </header>

    <main class="app-main">
