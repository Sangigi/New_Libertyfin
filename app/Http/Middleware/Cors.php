<?php

namespace App\Http\Middleware;

use App\Config\Config;

/**
 * CORS cerrado por whitelist de orígenes (CORS_ALLOWED_ORIGINS en .env),
 * en vez de "Access-Control-Allow-Origin: *".
 *
 * Ese wildcard aparece hoy en 9 endpoints — Service/generar_clabe.php,
 * pago_clabe.php, cancelar_pago.php, consultar_clabe.php, y los 5 proxy_*
 * de EmidaServicios — varios de ellos moviendo dinero real (SPEI/CLABE)
 * o saldo de servicios prepago, sin ninguna verificación de origen.
 *
 * Importante: CORS solo protege contra llamadas hechas desde el
 * navegador de otra persona. No sustituye a Auth::requireLogin() — un
 * script o servidor externo puede llamar al endpoint directamente sin
 * pasar por un navegador, por lo que estos endpoints necesitan Cors Y
 * autenticación juntos, no uno u otro.
 *
 * Uso: primera línea de cualquier endpoint público.
 *     Cors::handle();
 */
class Cors
{
    public static function handle(): void
    {
        $allowedOrigins = Config::getInstance()->getCorsConfig()['allowed_origins'];
        $origin          = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Responder al preflight y no seguir ejecutando el resto del script.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
