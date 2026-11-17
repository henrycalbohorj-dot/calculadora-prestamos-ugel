<?php
declare(strict_types=1);

/**
 * Web "en vivo" para Informática:
 *  - sello(): huella de las solicitudes (estado, OTIC, cambios). Si cambia, la pantalla se refresca sola.
 *  - sincronizar(): una sola sincronización a la vez (web automática, botón manual y tarea programada
 *    comparten el candado cache/sync.lock), respetando el cupo gratuito de Google.
 */
class Vivo
{
    public const INTERVALO = 120;   // segundos entre sincronizaciones automáticas
    public const MINIMO = 100;      // no repetir si alguien sincronizó hace menos de esto

    private static function expr(): string
    {
        $otic = Sincronizador::hayOticVisto() ? "COALESCE(s.otic_recepcion,'') || ':' || s.otic_visto::text" : "''";
        return "md5(COALESCE(string_agg(s.id || ':' || s.estado || ':' || $otic || ':' || s.actualizado::text, ',' ORDER BY s.id), ''))";
    }

    public static function sello(): string
    {
        $ev = (string)db()->query('SELECT COUNT(*) || \':\' || COALESCE(MAX(id), 0) FROM sol_evaluaciones')->fetchColumn();
        return substr(md5((string)db()->query('SELECT ' . self::expr() . ' FROM sol_solicitudes s')->fetchColumn() . $ev), 0, 16);
    }

    public static function selloSolicitud(int $id): string
    {
        $st = db()->prepare('SELECT ' . self::expr() . " || (SELECT COUNT(*) FROM sol_evaluaciones WHERE solicitud_id = ?)::text
                               FROM sol_solicitudes s WHERE s.id = ?");
        $st->execute([$id, $id]);
        return substr(md5((string)$st->fetchColumn()), 0, 16);
    }

    public static function maxId(): int
    {
        return (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM sol_solicitudes')->fetchColumn();
    }

    /** Segundos desde la última sincronización (de cualquier tipo). */
    public static function hace(): ?int
    {
        $t = ajuste('sync_inicio');
        return $t ? max(0, time() - (int)$t) : null;
    }

    /**
     * Ejecuta la sincronización con candado.
     *  $esperar = true  (botón manual / tarea programada): espera a que termine otra en curso.
     *  $esperar = false (automática): si hay otra en curso o hubo una hace < MINIMO s, no hace nada y devuelve null.
     */
    public static function sincronizar(bool $esperar, ?Sincronizador $sync = null): ?array
    {
        $dir = dirname(__DIR__) . '/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $lock = fopen($dir . '/sync.lock', 'c');
        if (!$lock) throw new RuntimeException('No se pudo crear cache/sync.lock (permisos de la carpeta cache).');
        try {
            if (!flock($lock, $esperar ? LOCK_EX : LOCK_EX | LOCK_NB)) return null;
            if (!$esperar && ($h = self::hace()) !== null && $h < self::MINIMO) return null;
            ajuste('sync_inicio', (string)time());
            $sync ??= new Sincronizador();
            $res = $sync->todas();
            return ['res' => $res, 'panelError' => $sync->panelError];
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Totales para la respuesta JSON (mismo formato que el botón). */
    public static function totales(array $r): array
    {
        $t = ['leidas' => 0, 'nuevas' => 0, 'actualizadas' => 0, 'retiradas' => 0, 'escritas' => 0, 'duplicados' => 0, 'automaticas' => 0];
        $errores = [];
        foreach ($r['res'] as $x) {
            foreach ($t as $k => $_) $t[$k] += $x[$k];
            if ($x['error']) $errores[] = $x['entidad'] . ': ' . $x['error'];
        }
        if ($r['panelError']) $errores[] = 'PANEL (monto disponible): ' . $r['panelError'];
        return ['ok' => true, 'entidades' => count($r['res']), 'totales' => $t, 'errores' => $errores];
    }
}
