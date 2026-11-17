<?php
declare(strict_types=1);

/**
 * Motor de la regla del 50 % — MISMA fórmula que la calculadora
 * (calculadora-prestamos-ugel/api/guardar_evaluacion.php):
 *
 *   neto_total  = max(sueldo bruto − descuentos de ley, 0)
 *   neto_50     = neto_total × 50 %
 *   total       = créditos vigentes (los que NO se liberan)
 *                 + cuotas ya APROBADAS aquí para el mismo DNI/periodo
 *                 + cuota solicitada por la entidad
 *   capacidad   = neto_50 − total
 *   DESAPROBADO si total > neto_50 + 0.001   (igual que excede_limite)
 *
 * "Se libera" = la deuda que compra la entidad (compra de deuda) o la cuota
 * anterior con la misma entidad (ampliación): ese descuento desaparece.
 * La vista previa en vivo replica esto en assets/app.js (función calcular).
 */
class Evaluador
{
    public static function calcular(array $in): array
    {
        $bruto = max(0, (float)($in['remuneracion'] ?? 0));
        $pct   = (float)($in['porcentaje'] ?? cfg('evaluacion.porcentaje_tope', 50));
        $cuota = max(0, (float)($in['cuota'] ?? 0));
        $aprob = max(0, (float)($in['aprobadas_sistema'] ?? 0));

        // Descuentos de ley detallados (AFP, ONP, Seguro, Quinta, Judicial, ...)
        $ley = [];
        $totalLey = 0.0;
        foreach (($in['ley'] ?? []) as $d) {
            $n = trim((string)($d['concepto'] ?? ''));
            $m = (float)($d['monto'] ?? 0);
            if ($m == 0) continue;   // conceptos en 0 no se guardan
            $ley[] = ['concepto' => $n !== '' ? $n : '(sin nombre)', 'monto' => round($m, 2)];
            $totalLey += $m;
        }

        // Créditos de terceros que ya figuran en la boleta
        $terceros = [];
        $vigentes = 0.0;
        $liberada = 0.0;
        foreach (($in['terceros'] ?? []) as $t) {
            $monto = (float)($t['monto'] ?? 0);
            $concepto = trim((string)($t['concepto'] ?? ''));
            if ($monto <= 0 && $concepto === '') continue;
            $libera = !empty($t['libera']);
            $terceros[] = ['concepto' => $concepto !== '' ? $concepto : '(sin nombre)', 'monto' => round($monto, 2), 'libera' => $libera];
            if ($libera) $liberada += $monto; else $vigentes += $monto;
        }

        $netoTotal = max($bruto - $totalLey, 0);
        $neto50    = $netoTotal * $pct / 100;
        $comprom   = $vigentes + $aprob;
        $total     = $comprom + $cuota;
        $excede    = $total > $neto50 + 0.001;

        return [
            'remuneracion'      => round($bruto, 2),
            'ley'               => $ley,
            'descuentos_ley'    => round($totalLey, 2),
            'terceros'          => $terceros,
            'total_terceros'    => round($vigentes + $liberada, 2),
            'cuota_liberada'    => round($liberada, 2),
            'aprobadas_sistema' => round($aprob, 2),
            'porcentaje'        => $pct,
            'base'              => round($netoTotal, 2),
            'tope'              => round($neto50, 2),
            'comprometido'      => round($comprom, 2),
            'total_solicitado'  => round($total, 2),
            'disponible'        => round($neto50 - $comprom, 2),
            'cuota'             => round($cuota, 2),
            'margen'            => round($neto50 - $total, 2),
            'uso_pct'           => $neto50 > 0 ? round($total / $neto50 * 100, 1) : 0,
            'resultado'         => ($bruto > 0 && $cuota > 0 && !$excede) ? 'APROBADO' : 'DESAPROBADO',
        ];
    }

    /**
     * Monto que le queda libre al trabajador para otros créditos después de la decisión.
     * Aprobado: el 50% menos todo lo comprometido incluida esta cuota. Desaprobado: sin esta cuota.
     */
    public static function libre(array $r, string $decision): float
    {
        $v = $decision === 'APROBADO' ? (float)$r['margen'] : (float)$r['disponible'];
        return round(max($v, 0), 2);
    }

    /**
     * Última evaluación de la UGEL para el DNI (cualquier entidad) con monto disponible conocido.
     * Es la base de la regla: si otra entidad pide una cuota mayor, sale DESAPROBADO automáticamente.
     */
    public static function ultimoDisponible(string $dni, int $excluirId = 0): ?array
    {
        if ($dni === '' || !self::hayV18()) return null;
        try {
            $dias = (int)cfg('evaluacion.regla_vigencia_dias', 0);
            $st = db()->prepare("SELECT v.disponible_libre AS libre, v.creado, v.resultado, v.modo, v.usuario, s.id AS solicitud_id,
                                        s.cuota, s.periodo, e.nombre AS entidad, e.codigo
                                   FROM sol_evaluaciones v
                                   JOIN sol_solicitudes s ON s.id = v.solicitud_id
                                   JOIN sol_entidades e ON e.id = s.entidad_id
                                  WHERE s.dni = ? AND s.id <> ? AND v.disponible_libre IS NOT NULL
                                    AND s.estado IN ('APROBADO', 'DESAPROBADO')   -- una solicitud ANULADA no se toma como referencia
                                    AND v.id = (SELECT MAX(v2.id) FROM sol_evaluaciones v2 WHERE v2.solicitud_id = s.id)"
                                . ($dias > 0 ? " AND v.creado >= NOW() - INTERVAL '$dias days'" : '') . "
                               ORDER BY v.id DESC LIMIT 1");
            $st->execute([$dni, $excluirId]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            return null;   // falta ejecutar el SQL v1.8
        }
    }

    /** true si ya se ejecutó el SQL v1.8 (sin consultas que fallen dentro de una transacción). */
    public static function hayV18(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool)db()->query("SELECT 1 FROM information_schema.columns
                WHERE table_name = 'sol_evaluaciones' AND column_name = 'disponible_libre'")->fetchColumn();
        }
        return $ok;
    }

    /** Devuelve el motivo si la cuota excede el disponible de la última evaluación; null si no aplica. */
    public static function reglaDisponible(string $dni, float $cuota, int $excluirId = 0): ?array
    {
        if (!cfg('evaluacion.regla_disponible', true)) return null;
        $u = self::ultimoDisponible($dni, $excluirId);
        if (!$u || $cuota <= (float)$u['libre'] + 0.001) return null;
        $u['motivo'] = 'Posible desaprobación: la cuota ' . soles($cuota) . ' excede el monto disponible del trabajador ('
                     . soles($u['libre']) . ') según la última evaluación de la UGEL del ' . fecha_hoja($u['creado']) . '.';
        return $u;
    }

    /** Descuentos de ley que no pueden ir juntos (sistema de pensiones: AFP u ONP / DL 20530). */
    public static function grupoExclusivo(string $concepto): string
    {
        return preg_match('/\b(AFP|ONP|SNP|19990|20530|PENSI)/i', $concepto) ? 'pension' : '';
    }

    /** Suma de cuotas ya aprobadas en el sistema para el mismo DNI y periodo (excluye la solicitud actual). */
    public static function aprobadasSistema(string $dni, string $periodo, int $excluirId): float
    {
        if (!cfg('evaluacion.considerar_aprobadas', true)) return 0.0;
        $st = db()->prepare("SELECT COALESCE(SUM(cuota),0) FROM sol_solicitudes
                             WHERE dni = ? AND periodo = ? AND estado = 'APROBADO' AND id <> ?");
        $st->execute([$dni, $periodo, $excluirId]);
        return (float)$st->fetchColumn();
    }

    /**
     * Registra la evaluación también en el HISTORIAL de la calculadora
     * (tablas evaluaciones / evaluacion_descuentos / evaluacion_creditos),
     * para que aparezca junto a las evaluaciones hechas a mano.
     * Devuelve el folio o null si la calculadora no tiene esas tablas.
     */
    public static function registrarEnCalculadora(array $sol, array $r, string $obs): ?int
    {
        if (!cfg('evaluacion.registrar_en_calculadora', true)) return null;
        $pdo = db();
        $existe = $pdo->query("SELECT to_regclass('public.evaluaciones') IS NOT NULL")->fetchColumn();
        if (!$existe) return null;

        $uid = $_SESSION[cfg('auth.session_key', 'usuario_id')] ?? null;
        $nombre = mb_substr(trim($sol['apellidos'] . ', ' . $sol['nombres'], ', '), 0, 150);
        $dependencia = mb_substr('Cód. modular ' . $sol['cod_modular'] . ' · ' . ($sol['condicion'] === 'C' ? 'Cesante' : 'Activo'), 0, 150);

        // Columnas según la versión de la calculadora (migraciones v2/v3)
        $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'evaluaciones'")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $datos = [
            'usuario_id'             => is_numeric($uid) ? (int)$uid : null,
            'trabajador_nombre'      => $nombre,
            'trabajador_dni'         => $sol['dni'] ?: null,
            'trabajador_dependencia' => $dependencia,
            'sueldo_bruto'           => $r['remuneracion'],
            'total_descuentos'       => $r['descuentos_ley'],
            'neto_total'             => $r['base'],
            'neto_50'                => $r['tope'],
            'total_solicitado'       => $r['total_solicitado'],
            'capacidad_disponible'   => round($r['tope'] - $r['total_solicitado'], 2),
            'excede_limite'          => ($r['resultado_calculo'] ?? $r['resultado']) === 'DESAPROBADO' ? 'true' : 'false',
            'observaciones'          => $obs,
        ];
        if (in_array('sueldo_bruto_base', $cols, true))      $datos['sueldo_bruto_base'] = $r['remuneracion'];   // v3
        if (in_array('sueldo_adicional_monto', $cols, true)) $datos['sueldo_adicional_monto'] = 0;               // v3
        if (in_array('trabajador_id', $cols, true) && $sol['dni']) {                                               // v2
            $datos['trabajador_id'] = self::trabajadorId($sol['dni'], $nombre, $dependencia, $r['remuneracion']);
        }

        $st = $pdo->prepare('INSERT INTO evaluaciones (' . implode(', ', array_keys($datos)) . ') VALUES ('
                            . implode(', ', array_fill(0, count($datos), '?')) . ') RETURNING id');
        $st->execute(array_values($datos));
        $folio = (int)$st->fetchColumn();

        $d = $pdo->prepare('INSERT INTO evaluacion_descuentos (evaluacion_id, nombre, monto) VALUES (?,?,?)');
        foreach ($r['ley'] as $x) $d->execute([$folio, mb_substr($x['concepto'], 0, 100), $x['monto']]);

        $c = $pdo->prepare('INSERT INTO evaluacion_creditos (evaluacion_id, entidad_nombre, entidad_tipo, monto_solicitado) VALUES (?,?,?,?)');
        foreach ($r['terceros'] as $t) {
            if (!$t['libera']) $c->execute([$folio, mb_substr($t['concepto'], 0, 100), 'otro', $t['monto']]);
        }
        if ($r['aprobadas_sistema'] > 0) $c->execute([$folio, 'Aprobadas en módulo solicitudes', 'otro', $r['aprobadas_sistema']]);
        $c->execute([$folio, mb_substr($sol['entidad'] . ' (solicitud)', 0, 100), self::tipoEntidad($sol['grupo_descuento']), $r['cuota']]);

        // v2: frecuencia de uso de los catálogos (ordena los botones rápidos de la calculadora)
        try {
            $u = $pdo->prepare('UPDATE descuentos_catalogo SET veces_usado = veces_usado + 1 WHERE nombre = ?');
            foreach ($r['ley'] as $x) $u->execute([$x['concepto']]);
            $u = $pdo->prepare('UPDATE entidades_catalogo SET veces_usado = veces_usado + 1 WHERE nombre = ?');
            foreach ($r['terceros'] as $t) if (!$t['libera']) $u->execute([$t['concepto']]);
        } catch (Throwable $e) { /* calculadora v1: sin contador */ }
        return $folio;
    }

    /** v2: busca o crea el trabajador por DNI (tabla trabajadores de la calculadora). */
    private static function trabajadorId(string $dni, string $nombre, string $dependencia, float $bruto): ?int
    {
        $pdo = db();
        if (!$pdo->query("SELECT to_regclass('public.trabajadores') IS NOT NULL")->fetchColumn()) return null;
        $st = $pdo->prepare('INSERT INTO trabajadores (nombres, dni, dependencia, sueldo_bruto_referencia)
                             VALUES (?,?,?,?)
                             ON CONFLICT (dni) DO UPDATE SET sueldo_bruto_referencia = EXCLUDED.sueldo_bruto_referencia, actualizado_en = NOW()
                             RETURNING id');
        $st->execute([$nombre, $dni, $dependencia, $bruto]);
        return (int)$st->fetchColumn();
    }

    private static function tipoEntidad(?string $grupo): string
    {
        $g = (string)$grupo;
        if (str_contains($g, 'COOPERATIVA')) return 'cooperativa';
        if (str_contains($g, 'SBS')) return 'banco';
        return 'otro';
    }

    /** Catálogos de la calculadora (si existen) para los botones rápidos. */
    public static function catalogo(string $tabla): array
    {
        $tabla = $tabla === 'descuentos' ? 'descuentos_catalogo' : 'entidades_catalogo';
        try {
            if (!db()->query("SELECT to_regclass('public.$tabla') IS NOT NULL")->fetchColumn()) return [];
            return db()->query("SELECT nombre FROM $tabla WHERE activo = TRUE ORDER BY orden, nombre")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }
}
