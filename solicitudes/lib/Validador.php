<?php
declare(strict_types=1);

/**
 * Normaliza y valida una fila enviada por la entidad.
 * Devuelve [datosNormalizados, observaciones[]]; nivel "error" => OBSERVADA, "alerta" => solo aviso.
 */
class Validador
{
    public static function norm($s): string
    {
        $s = trim((string)($s ?? ''));
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','ü'=>'u','Ü'=>'U']);
        $s = preg_replace('/\s+/', ' ', $s);
        return mb_strtoupper($s, 'UTF-8');
    }

    private static function digitos($v): string
    {
        if (is_float($v) || is_int($v)) $v = number_format((float)$v, 0, '', '');
        return preg_replace('/\s+/', '', trim((string)($v ?? '')));
    }

    public static function normalizar(array $f): array
    {
        $cuota = $f['cuota'] ?? '';
        if (is_string($cuota)) {
            $cuota = str_replace(['S/', 's/', ' ', ','], ['', '', '', '.'], $cuota);
        }
        $nombres = trim(preg_replace('/\s+/', ' ', (string)($f['nombres'] ?? '')));
        $apellidos = trim(preg_replace('/\s+/', ' ', (string)($f['apellidos'] ?? '')));
        $cut = fn(string $v, int $n) => mb_substr($v, 0, $n, 'UTF-8');
        return [
            'item'            => is_numeric($f['item'] ?? null) ? (int)$f['item'] : null,
            'cod_modular'     => $cut(self::digitos($f['cod_modular'] ?? ''), 30),
            'cod_cargo'       => $cut(self::digitos($f['cod_cargo'] ?? ''), 30),
            'dni'             => $cut(self::digitos($f['dni'] ?? ''), 30),
            'nombres'         => $cut(mb_strtoupper($nombres, 'UTF-8'), 150),
            'apellidos'       => $cut(mb_strtoupper($apellidos, 'UTF-8'), 150),
            'tipo_producto'   => $cut(self::norm($f['tipo_producto'] ?? ''), 60),
            'grupo_descuento' => $cut(self::norm($f['grupo_descuento'] ?? ''), 60),
            'entidad_texto'   => $cut(mb_strtoupper(trim((string)($f['entidad_texto'] ?? '')), 'UTF-8'), 150),
            'entidad_compra'  => $cut(mb_strtoupper(trim((string)($f['entidad_compra'] ?? '')), 'UTF-8'), 150),
            'periodo'         => $cut(self::digitos($f['periodo'] ?? ''), 20),
            'cuota'           => (is_numeric($cuota) && abs((float)$cuota) < 1e9) ? round((float)$cuota, 2) : null,
            'condicion'       => $cut(self::norm($f['condicion'] ?? ''), 20),
        ];
    }

    public static function validar(array &$d, string $nombreEntidad): array
    {
        $obs = [];
        $err = function (string $m) use (&$obs) { $obs[] = ['nivel' => 'error', 'msg' => $m]; };
        $ale = function (string $m) use (&$obs) { $obs[] = ['nivel' => 'alerta', 'msg' => $m]; };

        // DNI (Excel/Sheets suele comerse el 0 inicial)
        if ($d['dni'] === '') {
            $err('Falta el DNI.');
        } elseif (preg_match('/^\d{7}$/', $d['dni'])) {
            $d['dni'] = '0' . $d['dni'];
            $ale('DNI con 7 dígitos: se asumió un 0 inicial (' . $d['dni'] . ').');
        } elseif (!preg_match('/^\d{8}$/', $d['dni'])) {
            $err('DNI inválido: debe tener 8 dígitos.');
        }

        if ($d['cod_modular'] === '')                         $err('Falta el código modular.');
        elseif (!preg_match('/^\d{6,10}$/', $d['cod_modular'])) $err('Código modular inválido (solo números).');

        if ($d['cod_cargo'] === '')                       $err('Falta el código de cargo.');
        elseif (!preg_match('/^\d{1,10}$/', $d['cod_cargo'])) $err('Código de cargo inválido (solo números).');

        if ($d['nombres'] === '')   $err('Faltan los nombres.');
        if ($d['apellidos'] === '') $err('Faltan los apellidos.');

        $tipos = array_map([self::class, 'norm'], cfg('tipos_producto'));
        if ($d['tipo_producto'] === '')                  $err('Falta el tipo de producto.');
        elseif (!in_array($d['tipo_producto'], $tipos, true)) $err('Tipo de producto no reconocido: ' . $d['tipo_producto']);

        $grupos = array_map([self::class, 'norm'], cfg('grupos'));
        if ($d['grupo_descuento'] === '')                   $err('La entidad no tiene grupo de descuento asignado (UGEL: revisar Entidades).');
        elseif (!in_array($d['grupo_descuento'], $grupos, true)) $err('Grupo de descuento no reconocido: ' . $d['grupo_descuento']);

        if ($d['entidad_texto'] !== '' && self::norm($d['entidad_texto']) !== self::norm($nombreEntidad)) {
            $ale('La entidad escrita ("' . $d['entidad_texto'] . '") no coincide con la registrada (' . $nombreEntidad . ').');
        }

        // AMPLIACIÓN: solo de la misma entidad (la columna ENTIDAD COMPRA DE DEUDA se llena con ella)
        if ($d['tipo_producto'] === 'AMPLIACION') {
            if ($d['entidad_compra'] === '') {
                $d['entidad_compra'] = mb_strtoupper($nombreEntidad, 'UTF-8');
            } elseif (self::norm($d['entidad_compra']) !== self::norm($nombreEntidad)) {
                $err('La ampliación solo puede ser de la misma entidad (' . $nombreEntidad . '), no de ' . $d['entidad_compra'] . '.');
            }
        }
        $compras = array_map([self::class, 'norm'], cfg('tipos_compra'));
        if (in_array($d['tipo_producto'], $compras, true) && $d['entidad_compra'] === '') {
            $err('Es compra de deuda: falta la entidad cuya deuda se compra.');
        }
        if (!in_array($d['tipo_producto'], $compras, true) && $d['tipo_producto'] !== 'AMPLIACION' && $d['entidad_compra'] !== '' && $d['tipo_producto'] !== '') {
            $ale('Indicó entidad de compra de deuda, pero el producto no es de compra.');
        }

        if ($d['periodo'] === '') {
            $err('Falta el periodo (AAAAMM).');
        } elseif (!preg_match('/^(20\d{2})(0[1-9]|1[0-2])$/', $d['periodo'])) {
            $err('Periodo inválido: use AAAAMM, por ejemplo 202611.');
        } elseif ((int)$d['periodo'] < (int)date('Ym')) {
            $ale('El periodo ' . periodo_txt($d['periodo']) . ' ya pasó.');
        }

        if ($d['cuota'] === null)      $err('Falta el monto de la cuota mensual o no es numérico.');
        elseif ($d['cuota'] <= 0)      $err('La cuota debe ser mayor a cero.');
        elseif ($d['cuota'] > (float)cfg('evaluacion.cuota_atipica', 3000)) $ale('Cuota inusualmente alta: ' . soles($d['cuota']));

        if ($d['condicion'] === '')                        $err('Falta la condición (A = activo, C = cesante).');
        elseif (!in_array($d['condicion'], ['A', 'C'], true)) $err('Condición inválida: use A (activo) o C (cesante).');

        return $obs;
    }

    public static function tieneErrores(array $obs): bool
    {
        foreach ($obs as $o) if ($o['nivel'] === 'error') return true;
        return false;
    }
}
