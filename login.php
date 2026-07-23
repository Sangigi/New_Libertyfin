<?php
// login.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Http\Middleware\Auth;
use App\Repositories\EmpresaRepository;
use App\Services\AuthService;
use App\Services\Exceptions\InvalidCredentialsException;

// Antes: 8 ini_set repetidos + session_start(). Ahora centralizado.
Auth::start();

// VERIFICAR SI EL USUARIO YA ESTÁ LOGUEADO
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Verificar integridad de la sesión (opcional pero recomendado)
    if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        // IP cambió - posible secuestro de sesión
        session_destroy();
        Logger::warning('auth', 'Posible secuestro de sesión - IP cambió', [
            'antes' => $_SESSION['ip_address'] ?? null,
            'ahora' => $_SERVER['REMOTE_ADDR'],
        ]);
    } elseif (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        // User Agent cambió - posible secuestro de sesión
        session_destroy();
        Logger::warning('auth', 'Posible secuestro de sesión - User Agent cambió');
    } elseif (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
        // Sesión expirada (8 horas)
        session_destroy();
        Logger::info('auth', 'Sesión expirada por tiempo');
    } else {
        // Sesión válida - redirigir al dashboard
        header("Location: Inicio");
        exit();
    }
}

// Regenerar ID de sesión para prevenir fijación
if (!isset($_SESSION['initialized'])) {
    session_regenerate_id(true);
    $_SESSION['initialized'] = true;
}

$mensaje = "";
$tipo_mensaje = "";

// Limitar intentos de login (protección contra fuerza bruta)
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if ($_SESSION['login_attempts'] >= 5) {
    $time_diff = time() - $_SESSION['last_attempt_time'];
    if ($time_diff < 900) { // 15 minutos de bloqueo
        $mensaje = "Demasiados intentos fallidos. Por favor, espere " . ceil((900 - $time_diff) / 60) . " minutos.";
        $tipo_mensaje = "danger";
        $_POST = [];
    } else {
        $_SESSION['login_attempts'] = 0;
    }
}

// Procesar formulario de login
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($mensaje)) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $mensaje = "Error de seguridad. Por favor, recargue la página.";
        $tipo_mensaje = "danger";
    } else {
        $usuario = trim(htmlspecialchars($_POST['usuario'], ENT_QUOTES, 'UTF-8'));
        $password = $_POST['password']; // No sanitizar contraseña

        if (empty($usuario) || empty($password)) {
            $mensaje = "Usuario/Email y contraseña son obligatorios.";
            $tipo_mensaje = "danger";
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
        } else {
            try {
                // Antes: ~90 líneas recorriendo cada BD de empresa inline.
                // Ahora, un solo Service (App\Services\AuthService).
                $authService = new AuthService(new EmpresaRepository(Database::pdo()));
                $resultado   = $authService->attempt($usuario, $password);

                $empresaId = (new EmpresaRepository(Database::pdo()))
                    ->findIdByDbName($resultado['empresa_db']);

                // Limpiar intentos de login
                $_SESSION['login_attempts'] = 0;

                // Regenerar ID de sesión por seguridad
                session_regenerate_id(true);

                // Login exitoso - Establecer variables de sesión
                $_SESSION['logged_in']          = true;
                $_SESSION['login_time']         = time();
                $_SESSION['ip_address']         = $_SERVER['REMOTE_ADDR'];
                $_SESSION['user_agent']         = $_SERVER['HTTP_USER_AGENT'];
                $_SESSION['empresa_id']         = intval($empresaId ?? 0);
                $_SESSION['empresa_db']         = $resultado['empresa_db'];
                $_SESSION['empresa_nombre']     = htmlspecialchars($resultado['empresa_nombre']);
                $_SESSION['usuario_id']         = intval($resultado['usuario']['id']);
                $_SESSION['usuario_nombre']     = htmlspecialchars($resultado['usuario']['nombre']);
                $_SESSION['usuario_rol']        = htmlspecialchars($resultado['usuario']['rol']);
                $_SESSION['sucursal_id']        = intval($resultado['sucursal']['id']);
                $_SESSION['sucursal_nombre']    = htmlspecialchars($resultado['sucursal']['nombre']);
                $_SESSION['sucursal_es_matriz'] = intval($resultado['sucursal']['es_matriz']);
                $_SESSION['usuario_email']      = htmlspecialchars($resultado['usuario']['email'] ?? '');

                Logger::info('auth', 'Login exitoso', [
                    'usuario' => $resultado['usuario']['username'],
                    'empresa' => $resultado['empresa_nombre'],
                    'ip'      => $_SERVER['REMOTE_ADDR'],
                ]);

                header("Location: Inicio");
                exit();
            } catch (InvalidCredentialsException $e) {
                // Usuario/contraseña incorrectos — sí cuenta como intento fallido.
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                $mensaje = $e->getMessage();
                $tipo_mensaje = "danger";
                Logger::warning('auth', 'Intento de login fallido', [
                    'usuario_intentado' => $usuario,
                    'ip'                => $_SERVER['REMOTE_ADDR'],
                ]);
            } catch (Exception $e) {
                // Error de infraestructura (ej. no se pudo conectar a la BD
                // principal) — no penaliza al usuario ni expone el detalle
                // interno en el mensaje mostrado.
                $mensaje = "Error de conexión temporal. Intente más tarde.";
                $tipo_mensaje = "danger";
                Logger::error('auth', 'Error de login (infraestructura)', [
                    'error' => $e->getMessage(),
                    'ip'    => $_SERVER['REMOTE_ADDR'],
                ]);
            }
        }
    }
}

// Generar token CSRF para el formulario
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema Caja</title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            align-items: center;
        }

        .login-container {
            max-width: 400px;
            width: 100%;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 2rem;
            text-align: center;
        }

        .logo-horizontal {
            max-width: 280px;
            max-height: 90px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 576px) {
            .logo-horizontal {
                max-width: 220px;
                max-height: 70px;
            }
        }

        @media (min-width: 1200px) {
            .logo-horizontal {
                max-width: 320px;
                max-height: 100px;
            }
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(46, 204, 113, 0.25);
        }

        .password-group {
            position: relative;
        }

        .password-group .form-control {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            background: transparent;
            border: none;
            z-index: 10;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: var(--primary-color);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.4);
        }

        .alert {
            border-radius: 10px;
            border: none;
        }

        .login-help {
            font-size: 0.875rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6 col-xl-4">
                <div class="login-container">
                    <div class="card">
                        <div class="card-header">
                            <div class="text-center mb-4">
                                <img src="img/logo-libertyfin-white.png" alt="Logo Empresa" class="logo-horizontal">
                            </div>
                            <p class="mb-0 mt-2 opacity-75">Iniciar Sesión</p>
                        </div>
                        <div class="card-body p-4">
                            <?php if (!empty($mensaje)): ?>
                                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-exclamation-circle me-2"></i>
                                        <div><?php echo htmlspecialchars($mensaje); ?></div>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                
                                <div class="mb-3">
                                    <label for="usuario" class="form-label">
                                        <i class="fas fa-user me-2"></i>Usuario o Email
                                    </label>
                                    <input type="text" class="form-control" id="usuario" name="usuario"
                                        value="<?php echo isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : ''; ?>"
                                        required placeholder="Ingrese su usuario o email"
                                        autocomplete="username"
                                        maxlength="100"
                                        pattern="[a-zA-Z0-9@._-]{3,100}"
                                        title="El usuario debe tener entre 3 y 100 caracteres y solo puede contener letras, números, @, ., _ y -">
                                </div>

                                <div class="mb-4">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Contraseña
                                    </label>
                                    <div class="password-group">
                                        <input type="password" class="form-control" id="password" name="password"
                                            required placeholder="Ingrese su contraseña"
                                            autocomplete="current-password"
                                            minlength="6"
                                            maxlength="255">
                                        <button type="button" class="toggle-password" id="togglePasswordBtn" tabindex="-1">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted mt-1 d-block">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Haz clic en el ojo para ver/ocultar la contraseña
                                    </small>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 btn-lg" id="submitBtn">
                                    <i class="fas fa-sign-in-alt me-2"></i>Ingresar al Sistema
                                </button>
                            </form>

                            <div class="text-center mt-4">
                                <p class="text-muted login-help mb-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Puedes usar tu nombre de usuario o email para ingresar
                                </p>
                                <a href="SolicitudEmpresa" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-plus me-1"></i>Registrar Nueva Empresa
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-body text-center">
                            <h6 class="mb-3">Sistema Multi-Sucursal</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-building text-primary me-1"></i>Multi-Sucursal
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-cash-register text-info me-1"></i>Ventas Rápidas
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-credit-card text-success me-1"></i>Múltiples Pagos
                                    </small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">
                                        <i class="fas fa-chart-bar text-warning me-1"></i>Reportes
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Focus en el campo usuario
            document.getElementById('usuario').focus();

            // Funcionalidad del ojo
            const togglePasswordBtn = document.getElementById('togglePasswordBtn');
            const passwordInput = document.getElementById('password');
            const eyeIcon = togglePasswordBtn.querySelector('i');

            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                if (type === 'password') {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                } else {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                }
            });

            // Prevenir envío duplicado
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function(e) {
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                
                // Validación adicional del lado del cliente
                const usuario = document.getElementById('usuario').value.trim();
                const password = document.getElementById('password').value;
                
                if (usuario.length < 3) {
                    e.preventDefault();
                    alert('El usuario debe tener al menos 3 caracteres');
                    return;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    alert('La contraseña debe tener al menos 6 caracteres');
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
            });
        });
    </script>
</body>

</html>