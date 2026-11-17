<?php
declare(strict_types=1);

/**
 * Lector mínimo de hojas de cálculo, sin librerías externas:
 *  - .xls  (Excel 97-2003, BIFF8 dentro de un archivo OLE; ej. el reporte NEXUS)
 *  - .xlsx (Excel 2007+)
 *  - .csv / .txt delimitados por coma, punto y coma o tabulador
 * Devuelve las hojas como arreglos de filas de texto: [ ['nombre' => ..., 'filas' => [[...], ...]], ... ].
 */
class LectorExcel
{
    public static function leer(string $ruta, string $nombreOriginal): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $cabecera = (string)file_get_contents($ruta, false, null, 0, 8);
        if (str_starts_with($cabecera, "\xD0\xCF\x11\xE0")) return self::xls($ruta);
        if (str_starts_with($cabecera, "PK\x03\x04"))     return self::xlsx($ruta);
        if (in_array($ext, ['csv', 'txt'], true))          return self::csv($ruta);
        throw new RuntimeException('Formato no reconocido: ' . $nombreOriginal . ' (use .xls, .xlsx o .csv).');
    }

    /* ============================== CSV ============================== */

    private static function csv(string $ruta): array
    {
        $txt = (string)file_get_contents($ruta);
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
        $primera = strtok($txt, "\n");
        $sep = ',';
        foreach ([";", "\t", ','] as $c) if (substr_count((string)$primera, $c) > substr_count((string)$primera, $sep)) $sep = $c;
        $filas = [];
        $h = fopen('php://memory', 'r+');
        fwrite($h, $txt); rewind($h);
        while (($r = fgetcsv($h, 0, $sep, '"', '\\')) !== false) $filas[] = array_map(fn($v) => trim((string)$v), $r);
        fclose($h);
        return [['nombre' => 'CSV', 'filas' => $filas]];
    }

    /* ============================== XLSX ============================== */

    private static function xlsx(string $ruta): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Active extension=zip en php.ini para leer .xlsx.');
        $z = new ZipArchive();
        if ($z->open($ruta) !== true) throw new RuntimeException('No se pudo abrir el .xlsx.');
        $ss = [];
        if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($x);
            foreach ($doc->si as $si) {
                if (isset($si->t)) { $ss[] = (string)$si->t; continue; }
                $t = '';
                foreach ($si->r as $r) $t .= (string)$r->t;
                $ss[] = $t;
            }
        }
        $wb = simplexml_load_string((string)$z->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string)$z->getFromName('xl/_rels/workbook.xml.rels'));
        $destino = [];
        foreach ($rels->Relationship as $r) $destino[(string)$r['Id']] = (string)$r['Target'];
        $hojas = [];
        foreach ($wb->sheets->sheet as $sh) {
            $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $obj = $destino[$rid] ?? '';
            $obj = str_starts_with($obj, '/') ? ltrim($obj, '/') : 'xl/' . $obj;
            $xml = $z->getFromName($obj);
            if ($xml === false) continue;
            $doc = simplexml_load_string($xml);
            $filas = [];
            foreach ($doc->sheetData->row as $row) {
                $f = [];
                foreach ($row->c as $c) {
                    $col = self::colIndex((string)$c['r']);
                    $t = (string)$c['t'];
                    if ($t === 's')            $v = $ss[(int)$c->v] ?? '';
                    elseif ($t === 'inlineStr') $v = (string)$c->is->t;
                    else                        $v = (string)$c->v;
                    $f[$col] = trim($v);
                }
                if ($f) { $max = max(array_keys($f)); $filas[] = array_replace(array_fill(0, $max + 1, ''), $f); }
                else $filas[] = [];
            }
            $hojas[] = ['nombre' => (string)$sh['name'], 'filas' => $filas];
        }
        $z->close();
        return $hojas;
    }

    private static function colIndex(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    /* ============================== XLS (BIFF8) ============================== */

    private static function xls(string $ruta): array
    {
        $d = (string)file_get_contents($ruta);
        $libro = self::ole($d, ['Workbook', 'Book']);
        if ($libro === null) throw new RuntimeException('El .xls no tiene la hoja de cálculo esperada.');

        // 1) recorrer registros
        $regs = [];
        $p = 0; $L = strlen($libro);
        while ($p + 4 <= $L) {
            $t = unpack('v', $libro, $p)[1]; $n = unpack('v', $libro, $p + 2)[1];
            $regs[] = [$t, substr($libro, $p + 4, $n)];
            $p += 4 + $n;
        }
        // 2) tabla de cadenas compartidas (SST + CONTINUE)
        $sst = [];
        foreach ($regs as $i => [$t, $x]) {
            if ($t !== 0x00FC) continue;
            $trozos = [$x];
            for ($j = $i + 1; isset($regs[$j]) && $regs[$j][0] === 0x003C; $j++) $trozos[] = $regs[$j][1];
            $sst = self::sst($trozos);
            break;
        }
        // 3) celdas de cada hoja (cada BOF después del primero abre una hoja)
        // BIFF8 (Excel 97+) guarda un byte de opciones antes del texto; BIFF5 (Excel 95, ej. NEXUS) no.
        $hojas = []; $nombres = []; $actual = -1; $filas = []; $ultimaFormula = null; $biff8 = true; $globales = true;
        foreach ($regs as [$t, $x]) {
            if ($t === 0x0085) {                                  // BOUNDSHEET: nombre de la hoja
                $cch = ord($x[6]);
                if ($biff8) { $hi = ord($x[7]) & 1; $nombres[] = self::texto(substr($x, 8, $cch * ($hi ? 2 : 1)), (bool)$hi); }
                else        { $nombres[] = self::texto(substr($x, 7, $cch), false); }
            } elseif ($t === 0x0809) {                            // BOF: el primero es el libro, los de tipo 0x10 son hojas
                $tipo = strlen($x) >= 4 ? unpack('v', $x, 2)[1] : 0x10;
                if ($globales) { $biff8 = unpack('v', $x)[1] === 0x0600; $globales = false; if ($tipo === 0x0005) continue; }
                if ($tipo !== 0x0010) continue;
                if ($actual >= 0) $hojas[] = ['nombre' => $nombres[$actual] ?? ('Hoja' . ($actual + 1)), 'filas' => $filas];
                $actual++; $filas = [];
            } elseif ($actual >= 0) {
                if ($t === 0x00FD) {                              // LABELSST
                    $u = unpack('vr/vc/vxf/Vi', $x); $filas[$u['r']][$u['c']] = $sst[$u['i']] ?? '';
                } elseif ($t === 0x0204) {                        // LABEL
                    $u = unpack('vr/vc/vxf/vn', $x);
                    if ($u['n'] === 0 || strlen($x) < 9) { $filas[$u['r']][$u['c']] = ''; }
                    elseif (!$biff8) { $filas[$u['r']][$u['c']] = self::texto(substr($x, 8, $u['n']), false); }
                    else { $hi = ord($x[8]) & 1; $filas[$u['r']][$u['c']] = self::texto(substr($x, 9, $u['n'] * ($hi ? 2 : 1)), (bool)$hi); }
                } elseif ($t === 0x0203) {                        // NUMBER
                    $u = unpack('vr/vc/vxf', $x); $filas[$u['r']][$u['c']] = self::num(unpack('e', $x, 6)[1]);
                } elseif ($t === 0x027E) {                        // RK
                    $u = unpack('vr/vc/vxf/Vrk', $x); $filas[$u['r']][$u['c']] = self::num(self::rk($u['rk']));
                } elseif ($t === 0x00BD) {                        // MULRK
                    $r = unpack('v', $x)[1]; $c = unpack('v', $x, 2)[1]; $k = (strlen($x) - 6) / 6;
                    for ($i = 0; $i < $k; $i++) $filas[$r][$c + $i] = self::num(self::rk(unpack('V', $x, 4 + $i * 6 + 2)[1]));
                } elseif ($t === 0x0006) {                        // FORMULA
                    $u = unpack('vr/vc/vxf', $x);
                    if (substr($x, 12, 2) === "\xFF\xFF" && ord($x[6]) === 0) $ultimaFormula = [$u['r'], $u['c']];
                    else { $filas[$u['r']][$u['c']] = self::num(unpack('e', $x, 6)[1]); $ultimaFormula = null; }
                } elseif ($t === 0x0207 && $ultimaFormula) {      // STRING (resultado de fórmula)
                    $n = unpack('v', $x)[1];
                    if ($biff8) { $hi = ord($x[2]) & 1; $filas[$ultimaFormula[0]][$ultimaFormula[1]] = self::texto(substr($x, 3, $n * ($hi ? 2 : 1)), (bool)$hi); }
                    else        { $filas[$ultimaFormula[0]][$ultimaFormula[1]] = self::texto(substr($x, 2, $n), false); }
                    $ultimaFormula = null;
                }
            }
        }
        if ($actual >= 0) $hojas[] = ['nombre' => $nombres[$actual] ?? ('Hoja' . ($actual + 1)), 'filas' => $filas];
        // filas densas y ordenadas
        foreach ($hojas as &$h) {
            $denso = [];
            if ($h['filas']) {
                $maxR = max(array_keys($h['filas']));
                for ($r = 0; $r <= $maxR; $r++) {
                    $f = $h['filas'][$r] ?? [];
                    $denso[] = $f ? array_replace(array_fill(0, max(array_keys($f)) + 1, ''), $f) : [];
                }
            }
            $h['filas'] = $denso;
        }
        return $hojas;
    }

    private static function texto(string $bytes, bool $utf16): string
    {
        return trim($utf16 ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'));
    }

    private static function num(float $v): string
    {
        return (floor($v) == $v && abs($v) < 1e15) ? sprintf('%.0f', $v) : (string)$v;
    }

    private static function rk(int $rk): float
    {
        if ($rk & 2) { $v = $rk >> 2; if ($v & 0x20000000) $v -= 0x40000000; $v = (float)$v; }
        else $v = unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        return ($rk & 1) ? $v / 100 : $v;
    }

    /** Cadenas del SST; una cadena puede seguir en el siguiente CONTINUE (con su propio byte de opciones). */
    private static function sst(array $trozos): array
    {
        $k = 0; $p = 8; $out = [];
        $total = unpack('V', $trozos[0], 4)[1];
        $leer = function (int $n) use (&$k, &$p, $trozos): string {   // bytes sin interpretar, cruzando trozos
            $s = '';
            while ($n > 0 && isset($trozos[$k])) {
                $disp = strlen($trozos[$k]) - $p;
                if ($disp <= 0) { $k++; $p = 0; continue; }
                $t = min($n, $disp); $s .= substr($trozos[$k], $p, $t); $p += $t; $n -= $t;
            }
            return $s;
        };
        for ($i = 0; $i < $total && isset($trozos[$k]); $i++) {
            if ($p >= strlen($trozos[$k])) { $k++; $p = 0; if (!isset($trozos[$k])) break; }
            $cch = unpack('v', $leer(2))[1];
            $fl = ord($leer(1));
            $runs = ($fl & 0x08) ? unpack('v', $leer(2))[1] : 0;
            $ext  = ($fl & 0x04) ? unpack('V', $leer(4))[1] : 0;
            $hi = (bool)($fl & 0x01); $txt = ''; $falta = $cch;
            while ($falta > 0 && isset($trozos[$k])) {
                $disp = strlen($trozos[$k]) - $p;
                if ($disp <= 0) { $k++; $p = 0; if (!isset($trozos[$k])) break; $hi = (bool)(ord($trozos[$k][0]) & 1); $p = 1; continue; }
                $n = min($falta, intdiv($disp, $hi ? 2 : 1));
                $txt .= $hi ? mb_convert_encoding(substr($trozos[$k], $p, $n * 2), 'UTF-8', 'UTF-16LE')
                            : mb_convert_encoding(substr($trozos[$k], $p, $n), 'UTF-8', 'Windows-1252');
                $p += $n * ($hi ? 2 : 1); $falta -= $n;
            }
            if ($runs) $leer(4 * $runs);
            if ($ext)  $leer($ext);
            $out[] = trim($txt);
        }
        return $out;
    }

    /** Extrae un flujo (stream) de un archivo compuesto OLE2. */
    private static function ole(string $d, array $nombres): ?string
    {
        if (strlen($d) < 512) return null;
        $tamSec = 1 << unpack('v', $d, 30)[1];
        $tamMini = 1 << unpack('v', $d, 32)[1];
        $nFat = unpack('V', $d, 44)[1];
        $dir1 = unpack('V', $d, 48)[1];
        $corte = unpack('V', $d, 56)[1];
        $mini1 = unpack('V', $d, 60)[1];
        $difat1 = unpack('V', $d, 68)[1];
        $sec = fn(int $n) => substr($d, 512 + $n * $tamSec, $tamSec);
        $difat = array_values(unpack('V109', $d, 76));
        for ($s = $difat1; $s !== 0xFFFFFFFE && $s !== 0xFFFFFFFF && count($difat) < $nFat + 109; ) {
            $b = $sec($s); $v = array_values(unpack('V' . ($tamSec / 4), $b));
            $s = array_pop($v); $difat = array_merge($difat, $v);
        }
        $fat = [];
        foreach (array_slice($difat, 0, $nFat) as $s) $fat = array_merge($fat, array_values(unpack('V' . ($tamSec / 4), $sec($s))));
        $cadena = function (int $s) use ($fat, $sec): string {
            $out = ''; $vistos = 0;
            while ($s < 0xFFFFFFFA && isset($fat[$s]) && $vistos++ < 1000000) { $out .= $sec($s); $s = $fat[$s]; }
            return $out;
        };
        $dir = $cadena($dir1);
        $raiz = null; $buscado = null;
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $len = unpack('v', $dir, $o + 64)[1];
            if ($len < 2) continue;
            $nom = mb_convert_encoding(substr($dir, $o, $len - 2), 'UTF-8', 'UTF-16LE');
            $ent = ['tipo' => ord($dir[$o + 66]), 'ini' => unpack('V', $dir, $o + 116)[1], 'tam' => unpack('V', $dir, $o + 120)[1]];
            if ($ent['tipo'] === 5) $raiz = $ent;
            if (in_array($nom, $nombres, true) && $buscado === null) $buscado = $ent;
        }
        if (!$buscado) return null;
        if ($buscado['tam'] >= $corte || !$raiz) return substr($cadena($buscado['ini']), 0, $buscado['tam']);
        // flujo pequeño: está en el mini-stream
        $miniStream = $cadena($raiz['ini']);
        $miniFat = $mini1 < 0xFFFFFFFA ? array_values(unpack('V*', $cadena($mini1))) : [];
        $out = ''; $s = $buscado['ini'];
        while ($s < 0xFFFFFFFA && isset($miniFat[$s])) { $out .= substr($miniStream, $s * $tamMini, $tamMini); $s = $miniFat[$s]; }
        return substr($out, 0, $buscado['tam']);
    }
}
