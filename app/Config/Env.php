<?php

namespace App\Config;

/**
 * Carga variables de entorno desde .env al entorno de PHP.
 *
 * Reemplaza a env_loader.php — misma lógica, formalizada bajo el
 * namespace App\Config y con autoload PSR-4 (ya no requiere
 * require_once manual en cada archivo que la use).
 */
class Env
{
    private static bool $loaded = false;

    public static function load(?string $path = null): bool
    {
        if (self::$loaded) {
            return true;
        }

        $path ??= dirname(__DIR__, 2) . '/.env';

        if (!file_exists($path)) {
            error_log("⚠️ Archivo .env no encontrado en: {$path}");
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim(trim($parts[1]), "\"'");

            // No sobreescribir si ya viene definida por el entorno del
            // servidor (permite overrides a nivel de hosting/CI).
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
        return true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
