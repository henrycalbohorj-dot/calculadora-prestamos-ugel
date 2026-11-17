<?php
/**
 * CONFIGURACIÓN — Módulo Solicitudes de Crédito
 * Edita SOLO este archivo (el de C:\xampp\htdocs\calculadora-prestamos-ugel\solicitudes\,
 * no una copia del Escritorio).
 */
return [

    // --- Base de datos ------------------------------------------------------
    // Por defecto usa la conexión de la calculadora (../config/database.php).
    // Solo llena esto si el módulo se instala fuera de la calculadora.
    'db' => null,
    /* 'db' => ['host' => '127.0.0.1', 'port' => '5432', 'dbname' => 'calculadora_prestamos',
                'user' => 'postgres', 'password' => '...'], */

    // --- Login: se reutiliza la sesión de la calculadora --------------------
    // 'session_key'   = clave de $_SESSION que existe cuando hay sesión iniciada
    // 'nombre_key'    = clave de $_SESSION con el nombre del usuario (para auditoría)
    // 'login_url'     = a dónde mandar si no hay sesión
    // 'requerir'      = false SOLO para pruebas
    'auth' => [
        'requerir'    => true,
        'session_key' => 'usuario_id',
        'nombre_key'  => 'usuario_nombre',
        'login_url'   => '../login.php',
        'rol_key'     => 'usuario_rol',   // solo el rol admin registra/edita entidades
        'rol_admin'   => 'admin',
    ],

    // --- Google Sheets (cuenta de servicio) ---------------------------------
    'google' => [
        'credenciales' => __DIR__ . '/credenciales/service-account.json',
        // Bundle de certificados para HTTPS desde XAMPP (si curl da error SSL)
        'ca_bundle'    => 'C:/xampp/apache/bin/curl-ca-bundle.crt',
        // No tocar (se cambian solo para pruebas)
        'token_url'    => 'https://oauth2.googleapis.com/token',
        'api_base'     => 'https://sheets.googleapis.com',
        // Escribir el resultado (APROBADO/DESAPROBADO/OBSERVADA) en el Sheet de la entidad
        'escribir_resultado' => true,
    ],

    // --- Regla de evaluación ------------------------------------------------
    'evaluacion' => [
        'porcentaje_tope'            => 50,    // % del ingreso neto disponible para descuentos
        'considerar_aprobadas'       => true,  // sumar cuotas ya APROBADAS aquí (mismo DNI y periodo) que aún no figuran en boleta
        'cuota_atipica'              => 3000,  // alerta si la cuota supera este monto
        'registrar_en_calculadora'   => true,  // copiar cada evaluación al Historial de la calculadora
    ],

    // Catálogos (iguales a la hoja de listas del formato DRE)
    'tipos_producto' => ['NUEVO', 'NUEVA COMPRA DE PRODUCTO', 'AMPLIACION', 'AMPLIACION COMPRA'],
    'tipos_compra'   => ['NUEVA COMPRA DE PRODUCTO', 'AMPLIACION COMPRA'],
    'grupos'         => ['COOPERATIVA JUDICIALES', 'FONDO DE BIENESTAR', 'COOPERATIVAS', 'ENTIDADES REGULADAS POR LA SBS', 'SINDICATO'],

    'zona_horaria'   => 'America/Lima',
    'url_calculadora'=> '../index.php',
];
