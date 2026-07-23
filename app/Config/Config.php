<?php

namespace App\Config;

/**
 * Configuración centralizada del sistema (reemplaza config.php).
 *
 * Diferencia importante con la versión anterior: las credenciales YA NO
 * tienen un valor hardcodeado como fallback (antes: DB_USERNAME ?:
 * 'juanc141_alexis'). Si falta la variable de entorno, el valor queda
 * vacío y la conexión falla de forma explícita — es preferible a
 * conectar en silencio con un usuario adivinado.
 */
class Config
{
    private static ?Config $instance = null;

    /** @var array<string, mixed> */
    private array $config;

    private function __construct()
    {
        Env::load();

        $this->config = [
            'db' => [
                'servername' => Env::get('DB_SERVERNAME'),
                'username'   => Env::get('DB_USERNAME'),
                'password'   => Env::get('DB_PASSWORD'),
                'db_main'    => Env::get('DB_MAIN'),
            ],
            'facturapi' => [
                'api_key' => Env::get('FACTURAPI_API_KEY'),
            ],
            'cpanel' => [
                'host'      => Env::get('CPANEL_HOST'),
                'user'      => Env::get('CPANEL_USER'),
                'api_token' => Env::get('CPANEL_API_TOKEN'),
            ],
            'smtp' => [
                'host'     => Env::get('SMTP_HOST', 'smtp.titan.email'),
                'username' => Env::get('SMTP_USERNAME'),
                'password' => Env::get('SMTP_PASSWORD'),
                'port'     => (int) Env::get('SMTP_PORT', 465),
            ],
            'app' => [
                'name'       => Env::get('APP_NAME', 'LibertyFin'),
                'env'        => Env::get('APP_ENV', 'production'),
                'upload_dir' => Env::get('UPLOAD_DIR', 'uploads/'),
                'timezone'   => Env::get('APP_TIMEZONE', 'America/Mexico_City'),
            ],
            'spei' => [
                'user'           => Env::get('SPEI_USER'),
                'password'       => Env::get('SPEI_PASSWORD'),
                'integration_id' => Env::get('SPEI_INTEGRATION_ID'),
                'business_id'    => Env::get('SPEI_BUSINESS_ID'),
                'url_generar'    => Env::get('SPEI_URL_GENERAR'),
                'url_sandbox'    => Env::get('SPEI_URL_SANDBOX'),
                'timeout'        => (int) Env::get('SPEI_TIMEOUT', 30),
                'dias_vigencia'  => (int) Env::get('SPEI_DIAS_VIGENCIA', 1),
            ],
            'cors' => [
                // Whitelist real de orígenes — nunca "*".
                'allowed_origins' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))
                ))),
            ],
        ];

        date_default_timezone_set($this->config['app']['timezone']);
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function getDBConfig(): array
    {
        return $this->config['db'];
    }

    public function getFacturapiConfig(): array
    {
        return $this->config['facturapi'];
    }

    public function getCpanelConfig(): array
    {
        return $this->config['cpanel'];
    }

    public function getSmtpConfig(): array
    {
        return $this->config['smtp'];
    }

    public function getAppConfig(): array
    {
        return $this->config['app'];
    }

    public function getSpeiConfig(): array
    {
        return $this->config['spei'];
    }

    public function getCorsConfig(): array
    {
        return $this->config['cors'];
    }
}

// Helpers globales, iguales a los que ya existían — para no romper
// llamadas a config()/speiConfig() en archivos que aún no se migran.
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::getInstance()->get($key, $default);
    }
}

if (!function_exists('speiConfig')) {
    function speiConfig(?string $key = null, mixed $default = null): mixed
    {
        $spei = Config::getInstance()->getSpeiConfig();
        return $key === null ? $spei : ($spei[$key] ?? $default);
    }
}
