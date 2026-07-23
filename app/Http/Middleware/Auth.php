<?php

namespace App\Http\Middleware;

/**
 * Reemplaza los checks de sesión que hoy se repiten copiados en cada
 * script, por ejemplo en guardar_sucursal.php:
 *
 *     if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
 *         header('HTTP/1.1 401 Unauthorized');
 *         echo json_encode(['success' => false, 'message' => 'No autorizado']);
 *         exit();
 *     }
 *
 * También centraliza el endurecimiento de sesión que antes estaba
 * copiado al inicio de login.php Y dashboard.php (mismos 7-8 ini_set
 * repetidos en ambos archivos):
 *
 *     ini_set('session.cookie_secure', 1); ...
 *
 * Uso:
 *     Auth::start();                        // arranca sesión reforzada, sin exigir login
 *     Auth::requireLogin();                 // exige sesión — responde 401 JSON si no
 *     Auth::requireRole('admin');           // exige sesión + rol exacto — 403 JSON si no
 *     Auth::requireLoginForPage('Login');   // exige sesión — redirige si no (páginas HTML)
 *
 * Nota: Administracion/ maneja su propio vocabulario de roles
 * ('administrador', 'supervisor', ...) vía session_config.php, distinto
 * al de la app principal ('admin'). Esta clase no asume un vocabulario
 * fijo — recibe el/los rol(es) esperados como argumento, así sirve para
 * ambos paneles.
 */
class Auth
{
    /**
     * Arranca la sesión con la configuración reforzada (antes duplicada
     * en cada archivo). Debe llamarse ANTES que cualquier otra cosa toque
     * la sesión — los ini_set de cookie no tienen efecto si la sesión ya
     * está iniciada.
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.gc_maxlifetime', 28800);
        ini_set('session.cookie_lifetime', 28800);
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');

        session_start();
    }

    public static function requireLogin(): void
    {
        self::start();

        if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            self::deny(401, 'No autorizado');
        }
    }

    public static function requireRole(string ...$rolesPermitidos): void
    {
        self::requireLogin();

        if (!in_array($_SESSION['usuario_rol'] ?? '', $rolesPermitidos, true)) {
            self::deny(403, 'No tienes permisos para realizar esta acción');
        }
    }

    /**
     * Igual que requireLogin(), pero para páginas HTML completas
     * (dashboard.php, caja.php, etc.): redirige en vez de responder JSON.
     */
    public static function requireLoginForPage(string $redirectTo = 'Login'): void
    {
        self::start();

        if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            header("Location: {$redirectTo}");
            exit;
        }
    }

    private static function deny(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
