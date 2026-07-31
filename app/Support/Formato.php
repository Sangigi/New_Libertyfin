<?php

namespace App\Support;

/**
 * Antes definidas dentro de caja_resumen.php (formatDateSafe,
 * formatCurrency, getDiferenciaClass). Se necesitan en más de una
 * pantalla de Caja (resumen, y probablemente historial cuando se
 * migre), así que viven aquí una sola vez.
 */
class Formato
{
    public static function fecha(?string $valor, string $formato = 'd/m/Y H:i', string $default = 'No disponible'): string
    {
        if (empty($valor) || $valor === '0000-00-00 00:00:00') {
            return $default;
        }

        $timestamp = strtotime($valor);
        if ($timestamp === false) {
            return $default;
        }

        return date($formato, $timestamp);
    }

    public static function moneda(mixed $monto, string $default = '0.00'): string
    {
        if (!isset($monto)) {
            return $default;
        }

        return number_format((float) $monto, 2);
    }

    public static function claseDiferencia(mixed $diferencia): string
    {
        if (!isset($diferencia)) {
            return 'cero';
        }

        if ($diferencia > 0) {
            return 'positiva';
        }

        if ($diferencia < 0) {
            return 'negativa';
        }

        return 'cero';
    }

    /** "#RRGGBB" o "#RGB" -> "R, G, B" (para variables CSS). Antes duplicada en caja_historial.php e inventario.php. */
    public static function hexARgb(string $hex): string
    {
        $hex = str_replace('#', '', $hex);

        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat($hex[0], 2));
            $g = hexdec(str_repeat($hex[1], 2));
            $b = hexdec(str_repeat($hex[2], 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        return "{$r}, {$g}, {$b}";
    }
}
