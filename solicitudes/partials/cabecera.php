<?php
/** @var string $titulo  @var string $activo  @var string|null $subtitulo  @var string|null $acciones */
$ico = [
    'panel'     => '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>',
    'bandeja'   => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'entidades' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
    'calc'      => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
    'otic'      => '<path d="M4 4h16v16H4z" opacity=".0"/><path d="M22 6 12 13 2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m9 16 2 2 4-4"/>',
    'padron'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    'sync'      => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8M21 3v5h-5M3 21v-5h5"/>',
];
$svg = fn($k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $ico[$k] . '</svg>';
$pendientes = (int)db()->query("SELECT COUNT(*) FROM sol_solicitudes WHERE estado = 'PENDIENTE'")->fetchColumn();
$oticNuevos = Sincronizador::hayOticVisto()
    ? (int)db()->query("SELECT COUNT(*) FROM sol_solicitudes WHERE otic_recepcion IS NOT NULL AND NOT otic_visto")->fetchColumn() : 0;
$ultSync = db()->query('SELECT MAX(ultima_sync) FROM sol_entidades WHERE activa')->fetchColumn();
$f = flash();
$pagVivo = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$solVivo = $pagVivo === 'solicitud' ? (int)($_GET['id'] ?? 0) : 0;
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Solicitudes de crédito · UGEL Chincha</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=18">
<meta name="csrf" content="<?= csrf_token() ?>">
</head>
<body data-pag="<?= e($pagVivo) ?>" data-sello="<?= e(Vivo::sello()) ?>"<?php if ($solVivo): ?> data-sol="<?= $solVivo ?>" data-sello-sol="<?= e(Vivo::selloSolicitud($solVivo)) ?>"<?php endif; ?>>
<div class="alertas" id="alertas" aria-live="polite"></div>
<div class="app">
  <aside class="side">
    <div class="brand">
      <div class="brand-mark">50<span>%</span></div>
      <div>
        <div class="brand-t">Solicitudes de crédito</div>
        <div class="brand-s">UGEL Chincha · Informática</div>
      </div>
    </div>
    <nav class="nav">
      <a href="index.php" class="<?= $activo === 'panel' ? 'on' : '' ?>"><?= $svg('panel') ?>Panel</a>
      <a href="bandeja.php" class="<?= $activo === 'bandeja' ? 'on' : '' ?>"><?= $svg('bandeja') ?>Bandeja
        <?php if ($pendientes): ?><b class="badge"><?= $pendientes ?></b><?php endif; ?></a>
      <a href="bandeja.php?otic=nuevos" class="<?= $activo === 'otic' ? 'on' : '' ?>"><?= $svg('otic') ?>OTIC confirmados
        <?php if ($oticNuevos): ?><b class="badge badge-otic" title="Confirmaciones de recepción del OTIC sin revisar"><?= $oticNuevos ?></b><?php endif; ?></a>
      <a href="entidades.php" class="<?= $activo === 'entidades' ? 'on' : '' ?>"><?= $svg('entidades') ?>Entidades</a>
      <a href="padron.php" class="<?= $activo === 'padron' ? 'on' : '' ?>"><?= $svg('padron') ?>Padrón</a>
      <div class="nav-sep"></div>
      <a href="<?= e(cfg('url_calculadora', '../index.php')) ?>"><?= $svg('calc') ?>Calculadora</a>
    </nav>
    <div class="side-foot">
      <button class="btn-sync" id="btnSync" type="button"><?= $svg('sync') ?><span>Sincronizar Sheets</span></button>
      <div class="sync-info" id="syncInfo">Última: <?= e(hace($ultSync)) ?></div>
      <div class="vivo" id="vivo"><span class="vivo-dot"></span><span id="vivoTxt">En vivo</span></div>
      <button type="button" class="btn-voz" id="btnVoz">🔊 <span>Alertas de voz activadas</span></button>
      <div class="user"><?= e(usuario_actual()) ?></div>
    </div>
  </aside>
  <main class="main">
    <?php if (empty($sinTop)): ?>
    <header class="top">
      <div>
        <h1><?= e($titulo) ?></h1>
        <?php if (!empty($subtitulo)): ?><p class="sub"><?= $subtitulo ?></p><?php endif; ?>
      </div>
      <div class="top-act"><?= $acciones ?? '' ?></div>
    </header>
    <?php endif; ?>
    <?php if ($f): ?><div class="flash flash-<?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
    <div id="toast" class="toast" hidden></div>
