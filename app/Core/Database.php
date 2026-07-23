<?php

namespace App\Core;

use App\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Capa unificada de conexión a base de datos (solo PDO).
 *
 * Mismo patrón que ya tenías en config/Database.php — Singleton +
 * Connection Pool: una sola conexión PDO por base de datos (principal o
 * de una empresa), reutilizada aunque se pida varias veces en el mismo
 * request. Se mueve aquí (App\Core) para vivir junto al resto de la
 * infraestructura de la aplicación.
 *
 * Uso:
 *     $pdo = Database::pdo();                 // BD principal
 *     $pdo = Database::pdo($dbname_empresa);   // BD de una empresa
 */
class Database
{
    /** @var array<string, PDO> */
    private static array $pool = [];

    private static function credentials(): array
    {
        $cfg = Config::getInstance()->getDBConfig();

        return [
            'host'    => $cfg['servername'],
            'user'    => $cfg['username'],
            'pass'    => $cfg['password'],
            'main_db' => $cfg['db_main'],
        ];
    }

    public static function pdo(?string $dbname = null): PDO
    {
        $creds  = self::credentials();
        $dbname = $dbname ?: $creds['main_db'];

        // Validación centralizada del nombre de BD (antes vivía suelta,
        // por ejemplo dentro de login.php). Evita que un nombre
        // inesperado rompa el DSN o abra la puerta a un valor no
        // controlado si algún día llega indirectamente desde input.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
            throw new RuntimeException("Nombre de base de datos inválido: {$dbname}");
        }

        if (isset(self::$pool[$dbname])) {
            return self::$pool[$dbname];
        }

        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$dbname};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            Logger::error('database', "Error conectando a '{$dbname}'", ['error' => $e->getMessage()]);
            throw new RuntimeException("Error de conexión a la base de datos '{$dbname}'.");
        }

        return self::$pool[$dbname] = $pdo;
    }

    /** Alias semántico de pdo() para conexión a la BD de una empresa. */
    public static function forEmpresa(string $dbname): PDO
    {
        return self::pdo($dbname);
    }

    /**
     * Cierra todas las conexiones del pool. Normalmente no hace falta:
     * PHP cierra las conexiones al terminar el request. Útil en
     * scripts CLI/cron de larga duración (ej. Cron/cron_vencimiento.php).
     */
    public static function closeAll(): void
    {
        self::$pool = [];
    }
}

// -----------------------------------------------------------------------
// Compatibilidad hacia atrás: getDBConnection() y getEmpresaDBConnection()
// siguen existiendo como funciones globales delgadas sobre Database::pdo(),
// para que los archivos que aún no se migran a App\Core\Database sigan
// funcionando sin cambios.
// -----------------------------------------------------------------------
if (!function_exists('getDBConnection')) {
    function getDBConnection(): PDO
    {
        return Database::pdo();
    }
}

if (!function_exists('getEmpresaDBConnection')) {
    function getEmpresaDBConnection(string $dbname): PDO
    {
        return Database::pdo($dbname);
    }
}
