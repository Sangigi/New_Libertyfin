<?php

namespace App\Core;

/**
 * Logging centralizado en storage/logs/.
 *
 * Reemplaza:
 *   - Los ~7 archivos "error_log" que PHP crea solo por carpeta cuando
 *     log_errors/error_log no están configurados (Administracion/,
 *     EmidaServicios/, Facturacion/, Service/, raíz...).
 *   - Las llamadas sueltas a error_log(...) repartidas por todo el código.
 *
 * Un archivo por día, con "canal" (subsistema) embebido en cada línea
 * para poder filtrar con grep: grep "spei.ERROR" storage/logs/2026-07-22.log
 *
 * Uso:
 *     Logger::error('spei', 'Error guardando log SPEI', ['exception' => $e->getMessage()]);
 *     Logger::info('auth', 'Login exitoso', ['usuario' => $usuario, 'empresa' => $empresa]);
 */
class Logger
{
    private static function logDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            $channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $file = self::logDir() . '/' . date('Y-m-d') . '.log';
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('info', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('warning', $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('error', $channel, $message, $context);
    }
}
