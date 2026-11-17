<?php
declare(strict_types=1);

/** Filtros compartidos por la bandeja y la exportación. */
class Consultas
{
    public static function filtros(array $g): array
    {
        $w = ['1=1'];
        $p = [];
        if (($q = trim((string)($g['q'] ?? ''))) !== '') {
            $w[] = "(s.dni ILIKE ? OR s.nombres ILIKE ? OR s.apellidos ILIKE ? OR (s.apellidos || ' ' || s.nombres) ILIKE ? OR s.cod_modular ILIKE ?)";
            array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (!empty($g['entidad']))  { $w[] = 's.entidad_id = ?';      $p[] = (int)$g['entidad']; }
        if (($cod = preg_replace('/\D/', '', (string)($g['cod'] ?? ''))) !== '') {
            $w[] = 's.entidad_id IN (SELECT id FROM sol_entidades WHERE codigo = ?)'; $p[] = $cod;
        }
        if (!empty($g['estado']))   { $w[] = 's.estado = ?';          $p[] = (string)$g['estado']; }
        else                        { $w[] = "s.estado <> 'RETIRADA'"; }
        if (!empty($g['grupo']))    { $w[] = 's.grupo_descuento = ?'; $p[] = (string)$g['grupo']; }
        if (!empty($g['tipo']))     { $w[] = 's.tipo_producto = ?';   $p[] = (string)$g['tipo']; }
        if (!empty($g['periodo']))  { $w[] = 's.periodo = ?';         $p[] = preg_replace('/\D/', '', (string)$g['periodo']); }
        if (($g['otic'] ?? '') === 'nuevos' && Sincronizador::hayOticVisto()) { $w[] = 's.otic_recepcion IS NOT NULL AND NOT s.otic_visto'; }
        elseif (($g['otic'] ?? '') === 'todos') { $w[] = 's.otic_recepcion IS NOT NULL'; }
        if (!empty($g['dup']))      { $w[] = "s.duplicado_de IS NOT NULL AND s.estado <> 'DUPLICADA'"; }
        if (!empty($g['desde']))    { $w[] = 's.recibido >= ?';       $p[] = $g['desde'] . ' 00:00:00'; }
        if (!empty($g['hasta']))    { $w[] = 's.recibido <= ?';       $p[] = $g['hasta'] . ' 23:59:59'; }
        return [implode(' AND ', $w), $p];
    }

    public static function entidades(): array
    {
        return db()->query("SELECT id, nombre, codigo FROM sol_entidades
                            ORDER BY NULLIF(regexp_replace(COALESCE(codigo,''), '\\D', '', 'g'), '')::int NULLS LAST, nombre")->fetchAll();
    }

    /**
     * Crea o actualiza una entidad buscándola primero por CÓDIGO (así un Sheet nuevo reemplaza al anterior)
     * y luego por enlace. Devuelve 'nueva', 'actualizada' o 'igual'.
     */
    public static function upsertEntidad(?string $cod, string $nombre, ?string $grupo, ?string $sid, ?string $titulo = null): string
    {
        $pdo = db();
        $id = null;
        if ($cod) {
            $st = $pdo->prepare('SELECT id FROM sol_entidades WHERE codigo = ? ORDER BY id LIMIT 1');
            $st->execute([$cod]);
            $id = $st->fetchColumn() ?: null;
        }
        if (!$id && $sid) {
            $st = $pdo->prepare('SELECT id FROM sol_entidades WHERE spreadsheet_id = ?');
            $st->execute([$sid]);
            $id = $st->fetchColumn() ?: null;
        }
        if ($id) {
            $st = $pdo->prepare('SELECT nombre, grupo, spreadsheet_id, codigo FROM sol_entidades WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch();
            if ($sid) {   // otro registro con ese mismo Sheet (registrado a mano sin código) → se libera el enlace
                $pdo->prepare("UPDATE sol_entidades SET spreadsheet_id = 'reemplazado-' || id || '-' || spreadsheet_id, activa = FALSE
                               WHERE spreadsheet_id = ? AND id <> ?")->execute([$sid, $id]);
            }
            $pdo->prepare('UPDATE sol_entidades SET nombre = ?, grupo = COALESCE(?, grupo), spreadsheet_id = COALESCE(?, spreadsheet_id),
                           codigo = COALESCE(?, codigo), titulo_hoja = COALESCE(?, titulo_hoja), ultimo_error = CASE WHEN ? THEN NULL ELSE ultimo_error END
                           WHERE id = ?')
                ->execute([$nombre, $grupo, $sid, $cod, $titulo, ($sid && $sid !== $antes['spreadsheet_id']) ? 'true' : 'false', $id]);
            return ($antes['nombre'] === $nombre && ($grupo === null || $antes['grupo'] === $grupo)
                    && ($sid === null || $antes['spreadsheet_id'] === $sid) && ($cod === null || $antes['codigo'] === $cod)) ? 'igual' : 'actualizada';
        }
        if (!$sid) throw new RuntimeException($nombre . ': aún no tiene Sheet creado.');
        $pdo->prepare("INSERT INTO sol_entidades (nombre, grupo, spreadsheet_id, hoja, titulo_hoja, codigo) VALUES (?,?,?,'SOLICITUDES',?,?)")
            ->execute([$nombre, $grupo, $sid, $titulo, $cod]);
        return 'nueva';
    }

    public static function periodos(): array
    {
        return db()->query("SELECT DISTINCT periodo FROM sol_solicitudes WHERE periodo ~ '^[0-9]{6}$' ORDER BY periodo DESC LIMIT 24")
                   ->fetchAll(PDO::FETCH_COLUMN);
    }
}
