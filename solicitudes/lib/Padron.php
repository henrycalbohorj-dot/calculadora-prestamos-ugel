<?php
declare(strict_types=1);

/**
 * Padrón de trabajadores (DNI → apellidos, nombres y código modular).
 *  - Se alimenta con los Excel / CSV que exporta la UGEL (NEXUS, PENSIÓN, SOBREVIVIENTE, etc.).
 *    Las columnas se reconocen por su cabecera, no por su posición.
 *  - Un DNI repetido en varios archivos se guarda una sola vez. El código modular se conserva aunque
 *    el archivo nuevo no lo traiga (o traiga 0000000000).
 *  - publicar() copia el padrón a la pestaña PADRON del PANEL; el portal de entidades la consulta
 *    para autocompletar el formulario al escribir el DNI.
 */
class Padron
{
    public const HOJA = 'PADRON';
    public const CAB = ['DNI', 'APELLIDO PATERNO', 'APELLIDO MATERNO', 'NOMBRES', 'COD MODULAR', 'FUENTE'];

    public static function existe(): bool
    {
        static $s = null;
        return $s ??= (bool)db()->query("SELECT to_regclass('sol_padron') IS NOT NULL")->fetchColumn();
    }

    /** Texto en mayúsculas sin tildes ni guiones bajos, para comparar cabeceras. */
    public static function norm(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', '_' => ' ', '.' => ' ', 'º' => '', '°' => '']);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function dni($v): ?string
    {
        if (is_float($v)) $v = sprintf('%.0f', $v);
        $d = preg_replace('/\D/', '', (string)$v);
        if (strlen($d) === 7) $d = '0' . $d;
        return strlen($d) === 8 && $d !== '00000000' ? $d : null;
    }

    public static function codigo($v): ?string
    {
        if (is_float($v)) $v = sprintf('%.0f', $v);
        $d = preg_replace('/\D/', '', (string)$v);
        return (strlen($d) >= 6 && strlen($d) <= 12 && (int)$d !== 0) ? $d : null;
    }

    private static function nombre($v): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper((string)$v, 'UTF-8')));
    }

    /** Ubica la fila de cabecera y las columnas. Devuelve [filaCabecera, [campo => índice]] o null. */
    public static function columnas(array $filas): ?array
    {
        foreach (array_slice($filas, 0, 20, true) as $i => $f) {
            $c = [];
            foreach ($f as $k => $v) {
                $h = self::norm((string)$v);
                if ($h === '') continue;
                if (!isset($c['dni']) && !str_contains($h, 'TIPO') &&
                    (preg_match('/\bDNI\b/', $h) || str_contains($h, 'DOCUMENTO DE IDENTIDAD') || str_contains($h, 'DOCUMENTO IDENTIDAD')
                     || $h === 'NUMERO DE DOCUMENTO' || $h === 'NRO DOCUMENTO' || $h === 'DOCUMENTO')) $c['dni'] = $k;
                elseif (!isset($c['pat']) && str_contains($h, 'PATERNO')) $c['pat'] = $k;
                elseif (!isset($c['mat']) && str_contains($h, 'MATERNO')) $c['mat'] = $k;
                elseif (!isset($c['ape']) && ($h === 'APELLIDOS' || $h === 'APELLIDO')) $c['ape'] = $k;
                elseif (!isset($c['nom']) && ($h === 'NOMBRES' || $h === 'NOMBRE' || $h === 'PRENOMBRES')) $c['nom'] = $k;
                elseif (!isset($c['mod']) && preg_match('/^COD(IGO)? ?MOD(ULAR)?$/', $h)) $c['mod'] = $k;
            }
            if (isset($c['dni'], $c['nom']) && (isset($c['pat']) || isset($c['ape']))) return [$i, $c];
        }
        return null;
    }

    /**
     * Lee varios archivos y devuelve [personas por DNI, informe por hoja].
     * $archivos = [['ruta' => ..., 'nombre' => ...], ...]
     */
    public static function leerArchivos(array $archivos): array
    {
        $gente = []; $informe = [];
        foreach ($archivos as $a) {
            $fuente = mb_strtoupper(pathinfo($a['nombre'], PATHINFO_FILENAME), 'UTF-8');
            $fuente = mb_substr(trim(preg_replace('/[^A-Z0-9Ñ ]+/u', ' ', $fuente)), 0, 40);
            try { $hojas = LectorExcel::leer($a['ruta'], $a['nombre']); }
            catch (Throwable $e) { $informe[] = ['archivo' => $a['nombre'], 'hoja' => '—', 'error' => $e->getMessage()]; continue; }
            foreach ($hojas as $h) {
                $r = ['archivo' => $a['nombre'], 'hoja' => $h['nombre'], 'validas' => 0, 'sin_dni' => 0, 'con_codigo' => 0, 'error' => null];
                $col = self::columnas($h['filas']);
                if (!$col) {
                    if (count($h['filas']) > 1) { $r['error'] = 'No se encontraron las columnas DNI / APELLIDOS / NOMBRES.'; $informe[] = $r; }
                    continue;
                }
                [$fc, $c] = $col;
                foreach ($h['filas'] as $i => $f) {
                    if ($i <= $fc) continue;
                    $dni = self::dni($f[$c['dni']] ?? '');
                    if (!$dni) { if (trim(implode('', array_map('strval', $f))) !== '') $r['sin_dni']++; continue; }
                    $pat = isset($c['pat']) ? self::nombre($f[$c['pat']] ?? '') : self::nombre($f[$c['ape']] ?? '');
                    $mat = isset($c['mat']) ? self::nombre($f[$c['mat']] ?? '') : '';
                    $nom = self::nombre($f[$c['nom']] ?? '');
                    if ($nom === '' && $pat === '') { $r['sin_dni']++; continue; }
                    $mod = isset($c['mod']) ? self::codigo($f[$c['mod']] ?? '') : null;
                    $r['validas']++; if ($mod) $r['con_codigo']++;
                    $prev = $gente[$dni] ?? null;
                    if (!$prev || (!$prev['mod'] && $mod)) {
                        $gente[$dni] = ['pat' => $pat, 'mat' => $mat, 'nom' => $nom, 'mod' => $mod ?? ($prev['mod'] ?? null), 'fuente' => $fuente];
                    }
                }
                $informe[] = $r;
            }
        }
        return [$gente, $informe];
    }

    /** Guarda en la base. Con $reemplazar borra antes el padrón anterior. Devuelve [nuevos, actualizados]. */
    public static function guardar(array $gente, bool $reemplazar, string $archivos): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($reemplazar) $pdo->exec('DELETE FROM sol_padron');
            $antes = (int)$pdo->query('SELECT COUNT(*) FROM sol_padron')->fetchColumn();
            $st = $pdo->prepare("INSERT INTO sol_padron (dni, apellido_paterno, apellido_materno, nombres, cod_modular, fuente, actualizado)
                                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                                 ON CONFLICT (dni) DO UPDATE SET apellido_paterno = EXCLUDED.apellido_paterno,
                                        apellido_materno = EXCLUDED.apellido_materno, nombres = EXCLUDED.nombres,
                                        cod_modular = COALESCE(EXCLUDED.cod_modular, sol_padron.cod_modular),
                                        fuente = CASE WHEN EXCLUDED.cod_modular IS NOT NULL OR sol_padron.cod_modular IS NULL
                                                      THEN EXCLUDED.fuente ELSE sol_padron.fuente END,
                                        actualizado = NOW()");
            foreach ($gente as $dni => $p) $st->execute([(string)$dni, $p['pat'], $p['mat'], $p['nom'], $p['mod'], $p['fuente']]);
            $despues = (int)$pdo->query('SELECT COUNT(*) FROM sol_padron')->fetchColumn();
            $nuevos = $despues - $antes;
            $pdo->prepare('INSERT INTO sol_padron_cargas (usuario, archivos, personas, nuevos, reemplazo) VALUES (?, ?, ?, ?, ?)')
                ->execute([usuario_actual(), mb_substr($archivos, 0, 500), count($gente), $nuevos, $reemplazar ? 'true' : 'false']);
            $pdo->commit();
            return [$nuevos, count($gente) - $nuevos];
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function buscar(string $dni): ?array
    {
        if (!self::existe() || !($dni = self::dni($dni))) return null;
        $st = db()->prepare('SELECT * FROM sol_padron WHERE dni = ?');
        $st->execute([$dni]);
        return $st->fetch() ?: null;
    }

    public static function resumen(): array
    {
        if (!self::existe()) return ['total' => 0, 'con_codigo' => 0, 'fuentes' => [], 'ultima' => null];
        $pdo = db();
        return [
            'total'      => (int)$pdo->query('SELECT COUNT(*) FROM sol_padron')->fetchColumn(),
            'con_codigo' => (int)$pdo->query('SELECT COUNT(*) FROM sol_padron WHERE cod_modular IS NOT NULL')->fetchColumn(),
            'fuentes'    => $pdo->query('SELECT fuente, COUNT(*) n FROM sol_padron GROUP BY fuente ORDER BY n DESC')->fetchAll(),
            'ultima'     => $pdo->query('SELECT * FROM sol_padron_cargas ORDER BY id DESC LIMIT 1')->fetch() ?: null,
        ];
    }

    /** Copia el padrón a la pestaña PADRON del PANEL. Devuelve el número de personas publicadas. */
    public static function publicar(?GoogleSheets $gs = null): int
    {
        $id = Panel::id();
        if ($id === '') throw new RuntimeException('Falta el enlace del PANEL ENTIDADES (Entidades → Panel).');
        $gs ??= new GoogleSheets();
        $filas = db()->query('SELECT dni, apellido_paterno, apellido_materno, nombres, COALESCE(cod_modular, \'\'), COALESCE(fuente, \'\')
                                FROM sol_padron ORDER BY dni')->fetchAll(PDO::FETCH_NUM);
        $valores = array_merge([self::CAB], $filas);
        $gs->asegurarHoja($id, self::HOJA);
        $gs->asegurarFilas($id, self::HOJA, count($valores) + 50, count(self::CAB));
        $gs->limpiar($id, "'" . self::HOJA . "'!A1:F");
        $datos = [];
        foreach (array_chunk($valores, 5000) as $n => $bloque) {
            $datos[] = ['range' => "'" . self::HOJA . "'!A" . ($n * 5000 + 1), 'values' => $bloque];
        }
        $gs->escribirLote($id, $datos);
        ajuste('padron_publicado', date('c'));
        return count($filas);
    }
}
