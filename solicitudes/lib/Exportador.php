<?php
declare(strict_types=1);

/**
 * Genera el "FORMATO PARA CONSTANCIA DE DISPONIBILIDAD DEL 50% - DRE ICA"
 * a partir de la plantilla original (plantillas/formato_dre.xlsx), conservando su diseño.
 */
class Exportador
{
    public static function formatoDre(array $filas): string
    {
        $tpl = dirname(__DIR__) . '/plantillas/formato_dre.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'dre') . '.xlsx';
        copy($tpl, $tmp);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('No se pudo abrir la plantilla DRE.');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');

        // Fecha y hora fijas (la plantilla usa HOY()/AHORA(), que cambian al abrir)
        $serial = (time() + (int)date('Z')) / 86400 + 25569;
        $xml = preg_replace('#<c r="E4"([^>]*)><f[^>]*>[^<]*</f><v>[^<]*</v></c>#', '<c r="E4"$1><v>' . floor($serial) . '</v></c>', $xml);
        $xml = preg_replace('#<c r="E5"([^>]*)><f[^>]*>[^<]*</f><v>[^<]*</v></c>#', '<c r="E5"$1><v>' . $serial . '</v></c>', $xml);

        // Reemplaza las filas de datos (9 en adelante)
        $n = max(30, count($filas));
        $rows = '';
        for ($i = 0; $i < $n; $i++) {
            $r = 9 + $i;
            $f = $filas[$i] ?? null;
            $c = fn(string $col, int $s, $v, bool $num = false) =>
                ($v === null || $v === '')
                    ? "<c r=\"{$col}{$r}\" s=\"{$s}\"/>"
                    : ($num ? "<c r=\"{$col}{$r}\" s=\"{$s}\"><v>{$v}</v></c>"
                            : "<c r=\"{$col}{$r}\" s=\"{$s}\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>');
            $rows .= "<row r=\"{$r}\" spans=\"3:15\">"
                . $c('C', 1, $i + 1, true)
                . $c('D', 7, $f['cod_modular'] ?? null)
                . $c('E', 7, $f['cod_cargo'] ?? null)
                . $c('F', 7, $f['dni'] ?? null)
                . $c('G', 7, $f['nombres'] ?? null)
                . $c('H', 7, $f['apellidos'] ?? null)
                . $c('I', 7, $f['tipo_producto'] ?? null)
                . $c('J', 7, $f['grupo_descuento'] ?? null)
                . $c('K', 7, $f['entidad'] ?? null)
                . $c('L', 7, $f['entidad_compra'] ?? null)
                . $c('M', 1, $f['periodo'] ?? null, isset($f['periodo']) && ctype_digit((string)$f['periodo']))
                . $c('N', 8, isset($f['cuota']) ? number_format((float)$f['cuota'], 2, '.', '') : null, true)
                . $c('O', 12, $f['condicion'] ?? null)
                . '</row>';
        }
        $xml = preg_replace('#<row r="9".*</sheetData>#s', $rows . '</sheetData>', $xml);
        $xml = preg_replace('#<dimension ref="[^"]*"/>#', '<dimension ref="C1:O' . (8 + $n) . '"/>', $xml);
        // Ajusta las listas desplegables al nuevo rango
        $xml = str_replace(['I10:I38', 'J9:J38', '<xm:sqref>I9</xm:sqref>'],
                           ['I10:I' . (8 + $n), 'J9:J' . (8 + $n), '<xm:sqref>I9</xm:sqref>'], $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);

        // Sin cadena de cálculo (ya no hay fórmulas)
        $zip->deleteName('xl/calcChain.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->addFromString('xl/_rels/workbook.xml.rels', preg_replace('#<Relationship [^>]*calcChain[^>]*/>#', '', $rels));
        $ct = $zip->getFromName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', preg_replace('#<Override [^>]*calcChain[^>]*/>#', '', $ct));
        $zip->close();
        return $tmp;
    }
}
