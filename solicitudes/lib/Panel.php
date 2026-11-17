<?php
declare(strict_types=1);

/**
 * Enlace con el PANEL ENTIDADES (Google Sheet de la UGEL donde vive el portal):
 *  - Lee la lista de entidades (COD, NOMBRE, GRUPO, ENLACE) para actualizar la web.
 *  - Publica en la pestaña DISPONIBLE el monto disponible de cada trabajador según su última
 *    evaluación. El portal lo usa para desaprobar al instante una cuota que no cabe.
 * El PANEL debe estar compartido como Editor con la cuenta de servicio.
 */
class Panel
{
    public const HOJA_DISP = 'DISPONIBLE';
    public const CAB_DISP = ['DNI', 'MONTO DISPONIBLE', 'RESULTADO', 'FECHA EVALUACION', 'ENTIDAD', 'COD ENTIDAD', 'PERIODO', 'CUOTA', 'TRABAJADOR', 'MODO'];

    public static function id(): string
    {
        return extraer_spreadsheet_id((string)(ajuste('panel_id') ?? cfg('google.panel', '')));
    }

    /** Filas de la pestaña ENTIDADES del panel: [codigo, nombre, grupo, spreadsheet_id]. */
    public static function entidades(?GoogleSheets $gs = null): array
    {
        $id = self::id();
        if ($id === '') throw new RuntimeException('Falta el enlace del PANEL ENTIDADES (Entidades → Panel).');
        $gs ??= new GoogleSheets();
        $out = [];
        foreach ($gs->leer($id, "'ENTIDADES'!A2:D400") as $f) {
            $cod = preg_replace('/\D/', '', (string)($f[0] ?? ''));
            $nom = trim((string)($f[1] ?? ''));
            $sid = extraer_spreadsheet_id((string)($f[3] ?? ''));
            if ($nom === '') continue;
            $out[] = ['codigo' => $cod ?: null, 'nombre' => mb_strtoupper($nom, 'UTF-8'), 'grupo' => trim((string)($f[2] ?? '')) ?: null,
                      'spreadsheet_id' => preg_match('/^[a-zA-Z0-9_-]{20,}$/', $sid) ? $sid : null];
        }
        return $out;
    }

    /** Publica el disponible vigente por DNI. Devuelve cuántos trabajadores se publicaron (null si no hay panel). */
    public static function publicarDisponibles(?GoogleSheets $gs = null): ?int
    {
        $id = self::id();
        if ($id === '' || !Evaluador::hayV18() || !cfg('evaluacion.regla_disponible', true)) return null;
        $gs ??= new GoogleSheets();
        $dias = (int)cfg('evaluacion.regla_vigencia_dias', 0);
        $filas = db()->query("SELECT DISTINCT ON (s.dni) s.dni, v.disponible_libre, v.resultado, v.creado, e.nombre AS entidad, e.codigo,
                                     s.periodo, s.cuota, s.apellidos || ', ' || s.nombres AS trabajador, v.modo
                                FROM sol_evaluaciones v
                                JOIN sol_solicitudes s ON s.id = v.solicitud_id
                                JOIN sol_entidades e ON e.id = s.entidad_id
                               WHERE v.disponible_libre IS NOT NULL AND s.estado IN ('APROBADO', 'DESAPROBADO') AND s.dni ~ '^[0-9]{8}$'
                                 AND v.id = (SELECT MAX(v2.id) FROM sol_evaluaciones v2 WHERE v2.solicitud_id = s.id)"
                           . ($dias > 0 ? " AND v.creado >= NOW() - INTERVAL '$dias days'" : '') . "
                            ORDER BY s.dni, v.id DESC")->fetchAll();
        $valores = [self::CAB_DISP];
        foreach ($filas as $f) {
            $valores[] = [$f['dni'], (float)$f['disponible_libre'], $f['resultado'], fecha_hoja($f['creado']), $f['entidad'],
                          (string)($f['codigo'] ?? ''), $f['periodo'], (float)$f['cuota'], $f['trabajador'], $f['modo']];
        }
        $gs->asegurarHoja($id, self::HOJA_DISP);
        $gs->limpiar($id, "'" . self::HOJA_DISP . "'!A1:J5000");
        $gs->escribirLote($id, [['range' => "'" . self::HOJA_DISP . "'!A1", 'values' => $valores]]);
        ajuste('panel_publicado', date('c'));
        ajuste('panel_error', null);
        return count($valores) - 1;
    }

    /** Igual que publicarDisponibles, pero nunca interrumpe (devuelve el error como texto). */
    public static function publicarSilencioso(): ?string
    {
        try { self::publicarDisponibles(); return null; }
        catch (Throwable $e) { ajuste('panel_error', $e->getMessage()); return $e->getMessage(); }
    }
}
