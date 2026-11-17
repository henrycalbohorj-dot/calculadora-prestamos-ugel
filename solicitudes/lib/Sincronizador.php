<?php
declare(strict_types=1);

/**
 * Lee los Google Sheets de las entidades, registra/actualiza solicitudes,
 * aplica los filtros automáticos y devuelve el estado a cada Sheet.
 */
class Sincronizador
{
    private const CABECERAS = [
        'ITEM'                    => 'item',
        'COD MODULAR'             => 'cod_modular',
        'COD CARGO'               => 'cod_cargo',
        'DNI'                     => 'dni',
        'NOMBRES'                 => 'nombres',
        'APELLIDOS'               => 'apellidos',
        'TIPO DE PRODUCTO'        => 'tipo_producto',
        'GRUPO DE DESCUENTO'      => 'grupo_descuento',
        'ENTIDAD'                 => 'entidad_texto',
        'ENTIDAD COMPRA DE DEUDA' => 'entidad_compra',
        'PERIODO'                 => 'periodo',
        'MONTO DE CUOTA MENSUAL'  => 'cuota',
        'ACT/CESANT'              => 'condicion',
        'ESTADO UGEL'             => 'r_estado',
        'OBSERVACION UGEL'        => 'r_obs',
        'FECHA EVALUACION'        => 'r_fecha',
        'ID'                      => 'uid',
        'REGISTRADO POR'          => 'reg_por',
        'FECHA REGISTRO'          => 'reg_fecha',
        'RECEPCION OTIC'          => 'otic',
        'TIPO DE TRABAJADOR'      => 'tipo_trab',
    ];
    private const DATOS = ['cod_modular','cod_cargo','dni','nombres','apellidos','tipo_producto',
                           'grupo_descuento','entidad_texto','entidad_compra','periodo','cuota','condicion'];
    /** Campos que, si la entidad los cambia, obligan a evaluar de nuevo (la entidad y el grupo salen de la cabecera del Sheet). */
    private const COMPARAR = ['cod_modular','cod_cargo','dni','nombres','apellidos','tipo_producto','entidad_compra','periodo','cuota','condicion'];

    private GoogleSheets $gs;
    public ?string $panelError = null;

    public function __construct(?GoogleSheets $gs = null)
    {
        $this->gs = $gs ?? new GoogleSheets();
    }

    /** Sincroniza todas las entidades activas. */
    public function todas(): array
    {
        $res = [];
        $ents = db()->query('SELECT * FROM sol_entidades WHERE activa ORDER BY nombre')->fetchAll();
        foreach ($ents as $ent) $res[] = $this->entidad($ent);
        // Monto disponible por trabajador → PANEL (lo usa el portal para la regla automática)
        $this->panelError = Panel::publicarSilencioso();
        return $res;
    }

    public function entidad(array $ent): array
    {
        $pdo = db();
        $pdo->prepare('INSERT INTO sol_sync_log (entidad_id) VALUES (?)')->execute([$ent['id']]);
        $logId = (int)$pdo->lastInsertId();
        $r = ['entidad' => $ent['nombre'], 'leidas' => 0, 'nuevas' => 0, 'actualizadas' => 0,
              'retiradas' => 0, 'escritas' => 0, 'duplicados' => 0, 'automaticas' => 0, 'error' => null];

        try {
            $hoja  = $ent['hoja'] ?: 'SOLICITUDES';
            $filas = $this->gs->leer($ent['spreadsheet_id'], "'{$hoja}'!A1:AZ2000");
            [$filaCab, $mapa] = $this->ubicarCabecera($filas);
            foreach (['dni', 'cuota', 'uid'] as $req) {
                if (!isset($mapa[$req])) {
                    throw new RuntimeException("La hoja '{$hoja}' no tiene la columna " . strtoupper($req) . '. ¿Se instaló la plantilla?');
                }
            }

            $pdo->beginTransaction();
            $existentes = [];
            $st = $pdo->prepare('SELECT * FROM sol_solicitudes WHERE entidad_id = ?');
            $st->execute([$ent['id']]);
            foreach ($st->fetchAll() as $s) $existentes[$s['uid']] = $s;

            $vistos = [];
            $escrituras = [];
            $porEscribir = [];
            for ($i = $filaCab + 1; $i < count($filas); $i++) {
                $fila = $filas[$i];
                $raw = [];
                foreach ($mapa as $campo => $col) $raw[$campo] = $fila[$col] ?? '';

                $vacia = true;
                foreach (self::DATOS as $c) if (trim((string)($raw[$c] ?? '')) !== '') { $vacia = false; break; }
                if ($vacia) continue;

                $r['leidas']++;
                $nFila = $i + 1;   // fila real en el Sheet (1-based)

                $uid = trim((string)($raw['uid'] ?? ''));
                if ($uid === '' || isset($vistos[$uid])) {
                    $uid = 'S-' . bin2hex(random_bytes(6));
                    $escrituras[] = ['range' => "'{$hoja}'!" . self::col($mapa['uid']) . $nFila, 'values' => [[$uid]]];
                }
                $vistos[$uid] = true;

                $d   = Validador::normalizar($raw);
                // v1.8: el Sheet ya no tiene columnas ENTIDAD ni GRUPO: se toman de la entidad registrada
                if ($d['entidad_texto'] === '')   $d['entidad_texto'] = mb_strtoupper((string)$ent['nombre'], 'UTF-8');
                if ($d['grupo_descuento'] === '') $d['grupo_descuento'] = Validador::norm($ent['grupo'] ?? '');
                $obs = Validador::validar($d, $ent['nombre']);
                $estadoVal = Validador::tieneErrores($obs) ? 'OBSERVADA' : 'PENDIENTE';
                $hash = md5(json_encode(array_intersect_key($d, array_flip(self::COMPARAR)), JSON_UNESCAPED_UNICODE));

                $prev = $existentes[$uid] ?? null;
                if (!$prev) {
                    $id = $this->insertar($ent['id'], $uid, $nFila, $d, $hash, $estadoVal, $obs);
                    historial($id, 'RECIBIDA', 'Fila ' . $nFila . ' del Sheet', 'sistema');
                    $r['nuevas']++;
                    $sol = ['id' => $id, 'estado' => $estadoVal, 'observaciones' => json_encode($obs, JSON_UNESCAPED_UNICODE),
                            'comentario' => null, 'evaluado_en' => null];
                    if (in_array($estadoVal, ['PENDIENTE', 'OBSERVADA'], true) && $this->aplicarRegla($id, $d)) { $sol['estado'] = 'DESAPROBADO'; $r['automaticas']++; }
                } else {
                    $sol = $prev;
                    $evaluada = in_array($prev['estado'], ['APROBADO', 'DESAPROBADO'], true);
                    if ($prev['estado'] !== 'ANULADA' && self::cambio($prev, $d)) {      // una anulada no revive aunque editen la fila
                        $nuevoEstado = $estadoVal;
                        $this->actualizarDatos((int)$prev['id'], $nFila, $d, $hash, $nuevoEstado, $obs, true);
                        historial((int)$prev['id'], $evaluada ? 'MODIFICADA TRAS EVALUACIÓN' : 'CORREGIDA',
                            'La entidad cambió los datos; vuelve a ' . $nuevoEstado, 'sistema');
                        $r['actualizadas']++;
                        $sol = array_merge($prev, ['estado' => $nuevoEstado, 'comentario' => null, 'evaluado_en' => null,
                                                   'observaciones' => json_encode($obs, JSON_UNESCAPED_UNICODE)]);
                        if (in_array($nuevoEstado, ['PENDIENTE', 'OBSERVADA'], true) && $this->aplicarRegla((int)$prev['id'], $d)) { $sol['estado'] = 'DESAPROBADO'; $r['automaticas']++; }
                    } else {
                        $nuevoEstado = $prev['estado'];
                        if ($prev['estado'] === 'RETIRADA') {
                            $nuevoEstado = $estadoVal;
                            historial((int)$prev['id'], 'REACTIVADA', 'La fila volvió a aparecer en el Sheet', 'sistema');
                        }
                        $pdo->prepare('UPDATE sol_solicitudes SET fila = ?, item = ?, en_hoja = TRUE, estado = ? WHERE id = ?')
                            ->execute([$nFila, $d['item'], $nuevoEstado, $prev['id']]);
                        $sol['estado'] = $nuevoEstado;
                        // Aún sin evaluar: si mientras tanto la UGEL evaluó a este DNI en otra solicitud, se revalida
                        if (in_array($nuevoEstado, ['PENDIENTE', 'OBSERVADA'], true) && $this->aplicarRegla((int)$prev['id'], $d)) { $sol['estado'] = 'DESAPROBADO'; $r['automaticas']++; }
                    }
                }

                // Firma del portal (quién registró / corrigió la fila); no forma parte del hash
                $regPor = mb_substr(trim((string)($raw['reg_por'] ?? '')), 0, 150);
                $regFec = mb_substr(trim((string)($raw['reg_fecha'] ?? '')), 0, 150);
                if (self::hayFirma() && ($regPor !== '' || $regFec !== '')
                    && ($regPor !== (string)($sol['registrado_por'] ?? '') || $regFec !== (string)($sol['registrado_fecha'] ?? ''))) {
                    $pdo->prepare('UPDATE sol_solicitudes SET registrado_por = NULLIF(?, \'\'), registrado_fecha = NULLIF(?, \'\') WHERE id = ?')
                        ->execute([$regPor, $regFec, $sol['id']]);
                }

                // Recepción del OTIC confirmada por la entidad en el portal (solo informativo)
                if (isset($mapa['otic']) && self::hayOtic()) {
                    $otic = mb_substr(trim((string)($raw['otic'] ?? '')), 0, 200);
                    if ($otic !== (string)($sol['otic_recepcion'] ?? '')) {
                        $pdo->prepare('UPDATE sol_solicitudes SET otic_recepcion = NULLIF(?, \'\')' . (self::hayOticVisto() ? ', otic_visto = ?' : '') . ' WHERE id = ?')
                            ->execute(self::hayOticVisto() ? [$otic, $otic === '' ? 'true' : 'false', $sol['id']] : [$otic, $sol['id']]);
                        historial((int)$sol['id'], $otic !== '' ? 'OTIC RECIBIDO' : 'OTIC SIN CONFIRMAR',
                                  $otic !== '' ? 'La entidad confirmó la recepción del OTIC: ' . $otic : 'La entidad quitó la confirmación del OTIC', 'sistema');
                    }
                }

                // v1.15: tipo de trabajador marcado por la entidad (define la evaluación en la web; no forma parte del hash)
                if (isset($mapa['tipo_trab']) && self::hayTipoTrab()) {
                    $tt = self::tipoTrab((string)($raw['tipo_trab'] ?? ''), $d['condicion']);
                    if ($tt !== ($sol['tipo_trabajador'] ?? null)) {
                        $pdo->prepare('UPDATE sol_solicitudes SET tipo_trabajador = ? WHERE id = ?')->execute([$tt, $sol['id']]);
                    }
                }

                // La respuesta se calcula al final (después de detectar duplicados)
                $porEscribir[] = [(int)$sol['id'], $nFila, [
                    trim((string)($raw['r_estado'] ?? '')),
                    trim((string)($raw['r_obs'] ?? '')),
                    trim((string)($raw['r_fecha'] ?? '')),
                ]];
            }

            // Filas que ya no están en el Sheet
            foreach ($existentes as $uid => $s) {
                if (isset($vistos[$uid]) || !$s['en_hoja']) continue;
                $nuevo = in_array($s['estado'], ['PENDIENTE', 'OBSERVADA', 'DUPLICADA'], true) ? 'RETIRADA' : $s['estado'];
                $pdo->prepare('UPDATE sol_solicitudes SET en_hoja = FALSE, estado = ?, actualizado = NOW() WHERE id = ?')
                    ->execute([$nuevo, $s['id']]);
                historial((int)$s['id'], 'RETIRADA DEL SHEET', 'La entidad borró la fila', 'sistema');
                $r['retiradas']++;
            }

            // Mismo cliente (DNI) ingresado más de una vez por esta entidad → alerta de posible duplicado
            $r['duplicados'] = $this->detectarDuplicados((int)$ent['id']);

            // Respuesta visible para la entidad (ESTADO / OBSERVACIÓN / FECHA)
            if (cfg('google.escribir_resultado', true) && isset($mapa['r_estado']) && $porEscribir) {
                $st = $pdo->prepare('SELECT s.id, s.fila, s.estado, s.observaciones, s.comentario, s.evaluado_en, s.duplicado_de, s.auto_regla, (SELECT v.disponible_libre FROM sol_evaluaciones v WHERE v.solicitud_id = s.id ORDER BY v.id DESC LIMIT 1) AS libre_eval
                                       FROM sol_solicitudes s WHERE s.entidad_id = ?');
                $st->execute([$ent['id']]);
                $map = [];
                foreach ($st->fetchAll() as $x) $map[(int)$x['id']] = $x;
                foreach ($porEscribir as [$sid, $nFila, $actual]) {
                    if (!isset($map[$sid])) continue;
                    $orig = $map[$sid]['duplicado_de'] ? ($map[(int)$map[$sid]['duplicado_de']]['fila'] ?? null) : null;
                    $deseado = self::respuesta($map[$sid], $orig ? (int)$orig : null);
                    if ($deseado !== $actual) {
                        $escrituras[] = [
                            'range'  => "'{$hoja}'!" . self::col($mapa['r_estado']) . $nFila . ':' . self::col($mapa['r_estado'] + 2) . $nFila,
                            'values' => [$deseado],
                        ];
                    }
                }
            }
            $pdo->commit();

            $r['escritas'] = $escrituras ? $this->gs->escribirLote($ent['spreadsheet_id'], $escrituras) : 0;

            $pdo->prepare('UPDATE sol_entidades SET ultima_sync = NOW(), ultimo_error = NULL WHERE id = ?')->execute([$ent['id']]);
            // Aviso de instalación: el Sheet ya envía el tipo de trabajador pero la base aún no puede guardarlo
            if (isset($mapa['tipo_trab']) && !self::hayTipoTrab()) {
                $r['error'] = 'Falta ejecutar sql/001_solicitudes.sql (v1.15): el Sheet trae TIPO DE TRABAJADOR, pero la base no tiene la columna tipo_trabajador, así que la web no puede marcarlo sola.';
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $r['error'] = $ex->getMessage();
            $pdo->prepare('UPDATE sol_entidades SET ultimo_error = ? WHERE id = ?')->execute([$ex->getMessage(), $ent['id']]);
        }

        $pdo->prepare('UPDATE sol_sync_log SET fin = NOW(), leidas = ?, nuevas = ?, actualizadas = ?, retiradas = ?, escritas = ?, error = ? WHERE id = ?')
            ->execute([$r['leidas'], $r['nuevas'], $r['actualizadas'], $r['retiradas'], $r['escritas'], $r['error'], $logId]);
        return $r;
    }

    /**
     * Escribe al instante el resultado de UNA solicitud en su Sheet.
     * Busca la fila por ID (por si la entidad ordenó o insertó filas).
     */
    public function escribirUna(int $solicitudId): bool
    {
        if (!cfg('google.escribir_resultado', true)) return false;
        $st = db()->prepare('SELECT s.*, e.spreadsheet_id, e.hoja, (SELECT v.disponible_libre FROM sol_evaluaciones v WHERE v.solicitud_id = s.id ORDER BY v.id DESC LIMIT 1) AS libre_eval FROM sol_solicitudes s JOIN sol_entidades e ON e.id = s.entidad_id WHERE s.id = ?');
        $st->execute([$solicitudId]);
        $s = $st->fetch();
        if (!$s || !$s['en_hoja']) return false;

        $hoja  = $s['hoja'] ?: 'SOLICITUDES';
        $filas = $this->gs->leer($s['spreadsheet_id'], "'{$hoja}'!A1:AZ2000");
        [$filaCab, $mapa] = $this->ubicarCabecera($filas);
        if (!isset($mapa['uid'], $mapa['r_estado'])) return false;

        for ($i = $filaCab + 1; $i < count($filas); $i++) {
            if (trim((string)($filas[$i][$mapa['uid']] ?? '')) === $s['uid']) {
                $n = $i + 1;
                $orig = null;
                if (!empty($s['duplicado_de'])) {
                    $q = db()->prepare('SELECT fila FROM sol_solicitudes WHERE id = ?');
                    $q->execute([$s['duplicado_de']]);
                    $orig = $q->fetchColumn() ?: null;
                }
                $this->gs->escribirLote($s['spreadsheet_id'], [[
                    'range'  => "'{$hoja}'!" . self::col($mapa['r_estado']) . $n . ':' . self::col($mapa['r_estado'] + 2) . $n,
                    'values' => [self::respuesta($s, $orig ? (int)$orig : null)],
                ]]);
                db()->prepare('UPDATE sol_solicitudes SET fila = ? WHERE id = ?')->execute([$n, $solicitudId]);
                return true;
            }
        }
        return false;
    }

    /** Texto que ve la entidad en las columnas ESTADO / OBSERVACIÓN / FECHA. No revela montos del trabajador. */
    public static function respuesta(array $s, ?int $filaOriginal = null): array
    {
        $fecha = !empty($s['evaluado_en']) ? fecha_hoja($s['evaluado_en']) : '';
        $coment = trim((string)($s['comentario'] ?? ''));
        switch ($s['estado']) {
            case 'APROBADO':
                return ['APROBADO', $coment, $fecha];
            case 'DESAPROBADO':
                // Regla automática del monto disponible: la entidad ve "POSIBLE DESAPROBACIÓN" y puede corregir la cuota
                if (!empty($s['auto_regla']) && $s['auto_regla'] !== 'f') {
                    return ['POSIBLE DESAPROBACIÓN', preg_replace('/^Desaprobado automáticamente:/u', 'Posible desaprobación:', $coment), $fecha];
                }
                $txt = $coment !== '' ? $coment : 'La cuota excede la capacidad de descuento disponible (50%).';
                // v1.17: el desaprobado no amarra presupuesto: se informa el disponible para que la entidad registre una nueva solicitud
                if (isset($s['libre_eval']) && $s['libre_eval'] !== null && $s['libre_eval'] !== '') {
                    $lib = (float)$s['libre_eval'];
                    $txt = rtrim($txt, '. ') . '. ' . ($lib > 0 ? 'Monto disponible del trabajador: ' . soles($lib) . '. Puede registrar una nueva solicitud por ese monto o menos.'
                                                              : 'El trabajador no tiene monto disponible para descuento.');
                }
                return ['DESAPROBADO', $txt, $fecha];
            case 'OBSERVADA':
                $errs = [];
                foreach (json_decode((string)($s['observaciones'] ?? '[]'), true) ?: [] as $o) {
                    if (($o['nivel'] ?? '') === 'error') $errs[] = $o['msg'];
                }
                if ($coment !== '') $errs[] = $coment;
                return ['OBSERVADA', implode(' | ', $errs) ?: 'Revise los datos de la fila.', $fecha];
            case 'ANULADA':
                return ['ANULADA', 'Anulada por la UGEL: ' . rtrim($coment, '. ') . '. Puede registrar una nueva solicitud para este cliente.', $fecha];
            case 'DUPLICADA':
                return ['DUPLICADA', trim('Cliente ya registrado' . ($filaOriginal ? ' en la fila ' . $filaOriginal : '') . '. ' . $coment), $fecha];
            default:
                return ['EN EVALUACIÓN', $filaOriginal ? 'ALERTA: este cliente (DNI) ya fue registrado en la fila ' . $filaOriginal . '.' : '', ''];
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Marca duplicado_de en las solicitudes de una entidad cuyo DNI ya fue ingresado antes
     * (la primera en llegar es la original). Devuelve cuántas quedaron marcadas como posible duplicado.
     */
    private function detectarDuplicados(int $entidadId): int
    {
        $pdo = db();
        $st = $pdo->prepare("WITH base AS (
                SELECT id, FIRST_VALUE(id) OVER (PARTITION BY dni ORDER BY recibido, fila, id) AS primero
                FROM sol_solicitudes
                WHERE entidad_id = ? AND en_hoja AND dni ~ '^[0-9]{8}$' AND estado NOT IN ('RETIRADA', 'DESAPROBADO', 'ANULADA'))
            UPDATE sol_solicitudes s SET duplicado_de = NULLIF(b.primero, s.id)
            FROM base b
            WHERE s.id = b.id AND s.duplicado_de IS DISTINCT FROM NULLIF(b.primero, s.id)
            RETURNING s.id, s.duplicado_de");
        $st->execute([$entidadId]);
        foreach ($st->fetchAll() as $x) {
            if ($x['duplicado_de']) {
                historial((int)$x['id'], 'POSIBLE DUPLICADO', 'El mismo DNI ya fue ingresado por esta entidad (solicitud #' . $x['duplicado_de'] . ')', 'sistema');
            }
        }
        // Limpia marcas de solicitudes que ya no están en el Sheet
        $pdo->prepare("UPDATE sol_solicitudes SET duplicado_de = NULL WHERE entidad_id = ? AND duplicado_de IS NOT NULL AND (NOT en_hoja OR estado IN ('RETIRADA', 'DESAPROBADO', 'ANULADA')
                         OR duplicado_de IN (SELECT id FROM sol_solicitudes WHERE estado IN ('DESAPROBADO', 'ANULADA')))")
            ->execute([$entidadId]);
        $c = $pdo->prepare("SELECT COUNT(*) FROM sol_solicitudes WHERE entidad_id = ? AND duplicado_de IS NOT NULL AND estado <> 'DUPLICADA'");
        $c->execute([$entidadId]);
        return (int)$c->fetchColumn();
    }

    /** ¿La entidad cambió algún dato de la solicitud? (compara campo por campo con lo guardado) */
    private static function cambio(array $prev, array $d): bool
    {
        foreach (self::COMPARAR as $c) {
            // En una AMPLIACIÓN la entidad se completa sola con la propia: no cuenta como cambio de la entidad
            if ($c === 'entidad_compra' && ($d['tipo_producto'] ?? '') === 'AMPLIACION') continue;
            if ($c === 'cuota') {
                if ($prev['cuota'] === null || $d['cuota'] === null) { if (($prev['cuota'] === null) !== ($d['cuota'] === null)) return true; continue; }
                if (abs((float)$prev['cuota'] - (float)$d['cuota']) > 0.004) return true;
            } elseif (trim((string)($prev[$c] ?? '')) !== trim((string)($d[$c] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Regla del monto disponible: si la última evaluación de la UGEL para este DNI (cualquier entidad)
     * dejó un disponible menor que la cuota pedida, la solicitud sale DESAPROBADA sin pasar a evaluación.
     */
    public function aplicarRegla(int $id, array $d): bool
    {
        if (!preg_match('/^\d{8}$/', (string)($d['dni'] ?? '')) || !((float)($d['cuota'] ?? 0) > 0)) return false;
        $u = Evaluador::reglaDisponible((string)$d['dni'], (float)$d['cuota'], $id);
        if (!$u) return false;
        db()->prepare("UPDATE sol_solicitudes SET estado = 'DESAPROBADO', comentario = ?, evaluado_por = ?, evaluado_en = NOW(),
                              disponible = ?, margen = ?, auto_regla = TRUE, actualizado = NOW() WHERE id = ?")
            ->execute([$u['motivo'], 'Sistema · regla de monto disponible', $u['libre'], round((float)$u['libre'] - (float)$d['cuota'], 2), $id]);
        historial($id, 'DESAPROBADO AUTOMÁTICO', 'Cuota ' . soles($d['cuota']) . ' > disponible ' . soles($u['libre']) . ' de la evaluación del '
                  . fecha_txt($u['creado']) . ' (' . $u['entidad'] . ', solicitud #' . $u['solicitud_id'] . ')', 'sistema');
        return true;
    }

    /**
     * Después de una decisión de la UGEL: revisa las demás solicitudes de ese DNI que aún no se evalúan
     * (en cualquier entidad y de cualquier tipo de producto). Las que piden más del nuevo monto disponible
     * quedan DESAPROBADAS y se les escribe la respuesta en su Sheet. Devuelve cuántas se desaprobaron.
     */
    public function revalidarPendientes(string $dni, int $excluirId = 0): int
    {
        $st = db()->prepare("SELECT id, dni, cuota FROM sol_solicitudes
                              WHERE dni = ? AND id <> ? AND en_hoja AND estado IN ('PENDIENTE', 'OBSERVADA') ORDER BY id");
        $st->execute([$dni, $excluirId]);
        $n = 0;
        foreach ($st->fetchAll() as $x) {
            if ($this->aplicarRegla((int)$x['id'], ['dni' => $x['dni'], 'cuota' => (float)$x['cuota']])) {
                $n++;
                try { $this->escribirUna((int)$x['id']); } catch (Throwable $e) { /* se escribe en la próxima sincronización */ }
            }
        }
        return $n;
    }

    /** true si ya se ejecutó el SQL v1.10 (aviso de OTIC para Informática). */
    public static function hayOticVisto(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool)db()->query("SELECT 1 FROM information_schema.columns
                WHERE table_name = 'sol_solicitudes' AND column_name = 'otic_visto'")->fetchColumn();
        }
        return $ok;
    }

    /** true si ya se ejecutó el SQL v1.9 (columna otic_recepcion). */
    /**
     * v1.17 · El OTIC solo se envía a DOCENTE/CESANTE, aprobado o desaprobado por Informática (no a administrativos
     * ni a las desaprobaciones automáticas). $modoEval = modo de la última evaluación, si se conoce.
     */
    public static function oticAplica(array $s, ?string $modoEval = null): bool
    {
        if (!in_array($s['estado'] ?? '', ['APROBADO', 'DESAPROBADO'], true)) return false;
        if (!empty($s['auto_regla']) && $s['auto_regla'] !== 'f') return false;
        $modo = $modoEval ?: ($s['tipo_trabajador'] ?? null) ?: (($s['condicion'] ?? '') === 'C' ? 'DOCENTE' : null);
        return $modo === 'DOCENTE' || ($modo === null && ($s['estado'] ?? '') === 'APROBADO');   // antiguas sin dato: como antes
    }

    /** 'DOCENTE' (docente y/o cesante), 'ADMINISTRATIVO' o null si la entidad no lo indicó. */
    public static function tipoTrab(string $v, string $condicion = ''): ?string
    {
        $v = Validador::norm($v);
        if ($condicion === 'C') return 'DOCENTE';                       // todo cesante: "docente y/o cesante"
        if (str_contains($v, 'ADMIN')) return 'ADMINISTRATIVO';
        if (str_contains($v, 'DOCENTE') || str_contains($v, 'CESANTE')) return 'DOCENTE';
        return null;
    }

    public static function hayTipoTrab(): bool
    {
        static $ok = null;
        return $ok ??= (bool)db()->query("SELECT 1 FROM information_schema.columns
                WHERE table_name = 'sol_solicitudes' AND column_name = 'tipo_trabajador'")->fetchColumn();
    }

    private static function hayOtic(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool)db()->query("SELECT 1 FROM information_schema.columns
                WHERE table_name = 'sol_solicitudes' AND column_name = 'otic_recepcion'")->fetchColumn();
        }
        return $ok;
    }

    /** true si ya se ejecutó el SQL v1.7 (columnas registrado_por / registrado_fecha). */
    private static function hayFirma(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool)db()->query("SELECT 1 FROM information_schema.columns
                WHERE table_name = 'sol_solicitudes' AND column_name = 'registrado_fecha'")->fetchColumn();
        }
        return $ok;
    }

    private function ubicarCabecera(array $filas): array
    {
        $lim = min(15, count($filas));
        for ($i = 0; $i < $lim; $i++) {
            $mapa = [];
            foreach ($filas[$i] as $c => $v) {
                $k = str_replace('_', ' ', Validador::norm($v));
                if (isset(self::CABECERAS[$k]) && !isset($mapa[self::CABECERAS[$k]])) $mapa[self::CABECERAS[$k]] = $c;
            }
            if (isset($mapa['dni']) && count($mapa) >= 8) return [$i, $mapa];
        }
        throw new RuntimeException('No se encontró la fila de cabeceras (DNI, NOMBRES, ...) en el Sheet.');
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private function insertar(int $entId, string $uid, int $fila, array $d, string $hash, string $estado, array $obs): int
    {
        $st = db()->prepare('INSERT INTO sol_solicitudes
            (entidad_id, uid, fila, item, cod_modular, cod_cargo, dni, nombres, apellidos, tipo_producto, grupo_descuento,
             entidad_texto, entidad_compra, periodo, cuota, condicion, hash, estado, observaciones)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$entId, $uid, $fila, $d['item'], $d['cod_modular'], $d['cod_cargo'], $d['dni'], $d['nombres'],
            $d['apellidos'], $d['tipo_producto'], $d['grupo_descuento'], $d['entidad_texto'], $d['entidad_compra'],
            $d['periodo'], $d['cuota'], $d['condicion'], $hash, $estado, json_encode($obs, JSON_UNESCAPED_UNICODE)]);
        return (int)$st->fetchColumn();
    }

    private function actualizarDatos(int $id, int $fila, array $d, string $hash, string $estado, array $obs, bool $limpiarEval): void
    {
        $sql = 'UPDATE sol_solicitudes SET fila=?, item=?, cod_modular=?, cod_cargo=?, dni=?, nombres=?, apellidos=?,
                tipo_producto=?, grupo_descuento=?, entidad_texto=?, entidad_compra=?, periodo=?, cuota=?, condicion=?,
                hash=?, estado=?, observaciones=?, en_hoja=TRUE, actualizado=NOW()'
             . ($limpiarEval ? ', disponible=NULL, margen=NULL, evaluado_por=NULL, evaluado_en=NULL, comentario=NULL' . (Evaluador::hayV18() ? ', auto_regla=FALSE' : '') : '')
             . ' WHERE id=?';
        db()->prepare($sql)->execute([$fila, $d['item'], $d['cod_modular'], $d['cod_cargo'], $d['dni'], $d['nombres'],
            $d['apellidos'], $d['tipo_producto'], $d['grupo_descuento'], $d['entidad_texto'], $d['entidad_compra'],
            $d['periodo'], $d['cuota'], $d['condicion'], $hash, $estado, json_encode($obs, JSON_UNESCAPED_UNICODE), $id]);
    }
}
