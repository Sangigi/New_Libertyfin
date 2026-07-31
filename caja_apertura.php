<?php
// caja_apertura.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\CajaRepository;
use App\Repositories\EmpresaRepository;
use App\Repositories\MovimientoCajaRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\SucursalRepository;
use App\Repositories\VentaRepository;
use App\Services\CajaService;
use App\Services\Exceptions\CajaYaAbiertaException;
use App\Support\LogoResolver;

Auth::requireLoginForPage('login.php');

// Verificar que sucursal_id esté definido
if (!isset($_SESSION['sucursal_id']) || !isset($_SESSION['usuario_id'])) {
    die("Error: Datos de sesión incompletos. Por favor, inicie sesión nuevamente.");
}

$error = null;

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $cajaRepo = new CajaRepository($pdoEmpresa);
    $cajaService = new CajaService(
        $cajaRepo,
        new VentaRepository($pdoEmpresa),
        new MovimientoCajaRepository($pdoEmpresa)
    );

    // Obtener el plan de la empresa desde la base de datos principal
    $planInfo = (new EmpresaRepository(Database::pdo()))
        ->findPlanInfo((int) ($_SESSION['empresa_id'] ?? 0));
    $empresa_plan = $planInfo['plan'] ?? 'prueba';
    $_SESSION['empresa_plan'] = $empresa_plan;

    // Si ya hay una caja abierta, no mostrar el formulario — ir directo a caja.php
    if ($cajaRepo->abiertaPara((int) $_SESSION['usuario_id'], (int) $_SESSION['sucursal_id']) !== null) {
        $_SESSION['success_message'] = "Caja ya está abierta";
        header("Location: caja.php");
        exit();
    }

    $empresa_info = (new SistemaConfigRepository($pdoEmpresa))->actual();

    $sucursal = (new SucursalRepository($pdoEmpresa))->findActiveById((int) $_SESSION['sucursal_id']);
    $nombre_sucursal = $sucursal['nombre'] ?? ('Sucursal ID: ' . $_SESSION['sucursal_id']);

    $logo             = LogoResolver::resolver($empresa_info['logo'] ?? null);
    $logo_empresa     = $logo['path'];
    $logo_src_base64  = $logo['base64'];
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Procesar apertura de caja
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $montoApertura = floatval($_POST['monto_apertura'] ?? 0);

    try {
        $cajaService->abrir(
            (int) $_SESSION['sucursal_id'],
            (int) $_SESSION['usuario_id'],
            $montoApertura,
            trim($_POST['observaciones'] ?? '')
        );

        $_SESSION['success_message'] = "Caja abierta correctamente con $" . number_format($montoApertura, 2);
        header("Location: caja.php");
        exit();
    } catch (CajaYaAbiertaException $e) {
        // Se abrió en otra pestaña/petición entre el chequeo de arriba y este POST.
        header("Location: caja.php");
        exit();
    } catch (RuntimeException $e) {
        // Error de validación (ej. monto negativo) — mismo mensaje, sin prefijo.
        $error = $e->getMessage();
    } catch (Exception $e) {
        // Fallo inesperado al guardar.
        $error = "Error al abrir la caja: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Apertura de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        * {
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* Navbar estilos mejorados para móvil */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 1rem;
        }

        .navbar-brand {
            font-size: 1rem;
        }

        .navbar-brand img {
            height: 35px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }

        .navbar-toggler {
            padding: 0.25rem 0.5rem;
            font-size: 1rem;
            border: none;
            outline: none;
        }

        .navbar-toggler:focus {
            box-shadow: none;
        }

        /* Estilos para el menú móvil */
        @media (max-width: 991.98px) {
            .navbar-brand {
                font-size: 0.9rem;
            }
            
            .navbar-brand img {
                max-height: 30px !important;
            }
            
            .user-info-mobile {
                border-top: 1px solid rgba(255,255,255,0.2);
                margin-top: 0.5rem;
                padding-top: 0.5rem;
            }
            
            .user-info-mobile .badge {
                font-size: 0.85rem;
                padding: 0.35rem 0.65rem;
                background-color: #f8f9fa !important;
                color: #212529 !important;
            }
            
            .user-info-mobile hr {
                border-color: rgba(255,255,255,0.2);
                margin: 0.5rem 0;
            }
            
            .user-info-mobile .btn {
                font-size: 0.9rem;
                padding: 0.5rem;
                margin-top: 0.25rem;
            }
            
            .user-info-mobile div {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .navbar-brand span {
                font-size: 0.85rem;
            }
            
            .navbar-brand img {
                max-height: 25px !important;
            }
            
            .navbar {
                padding: 0.5rem;
            }
        }

        /* Ajustes para tabletas */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .user-info-mobile .btn {
                width: auto !important;
                align-self: flex-start;
            }
        }

        .apertura-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .apertura-container {
                margin: 20px auto;
                padding: 15px;
            }
        }

        .money-input {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            .money-input {
                font-size: 1.2rem;
                padding: 12px;
            }
        }

        .money-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            border-radius: 15px 15px 0 0 !important;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        @media (max-width: 768px) {
            .btn-primary {
                padding: 10px;
                font-size: 0.95rem;
            }
            
            .btn-primary:hover {
                transform: none;
            }
        }

        .info-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-left: 4px solid var(--primary-color);
        }

        .input-group-text {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: bold;
        }

        /* Mejoras para inputs táctiles */
        input, textarea, button, a {
            touch-action: manipulation;
        }

        input[type="number"] {
            -moz-appearance: textfield;
        }

        input[type="number"]::-webkit-inner-spin-button, 
        input[type="number"]::-webkit-outer-spin-button {
            opacity: 0.5;
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar optimizado para móvil -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Logo y nombre de la empresa -->
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span class="d-none d-sm-inline">
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Botón hamburguesa para móvil -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMobileMenu" 
                    aria-controls="navbarMobileMenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Menú colapsable para móvil -->
            <div class="collapse navbar-collapse" id="navbarMobileMenu">
                <div class="navbar-nav ms-auto align-items-center">
                    <!-- Información del usuario (visible en desktop) -->
                    <div class="user-info-desktop d-none d-lg-flex align-items-center">
                        <span class="navbar-text me-3">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                        </span>
                        <span class="badge bg-light text-dark me-3">
                            <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($nombre_sucursal); ?>
                        </span>
                        <a href="dashboard.php" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Dashboard
                        </a>
                    </div>

                    <!-- Información del usuario (visible en móvil) -->
                    <div class="user-info-mobile d-lg-none w-100">
                        <div class="d-flex flex-column align-items-start gap-2 py-2">
                            <div class="w-100">
                                <i class="fas fa-user me-2"></i>
                                <strong><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong>
                            </div>
                            <div class="w-100">
                                <i class="fas fa-store me-2"></i>
                                <span class="badge bg-light text-dark">
                                    <?php echo htmlspecialchars($nombre_sucursal); ?>
                                </span>
                            </div>
                            <hr class="w-100 my-2">
                            <a href="dashboard.php" class="btn btn-light btn-sm w-100">
                                <i class="fas fa-arrow-left me-2"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="apertura-container">
            <div class="card shadow">
                <div class="card-header text-white">
                    <h4 class="mb-0"><i class="fas fa-lock-open me-2"></i>Apertura de Caja</h4>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message'];
                                                                    unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Mostrar error si no hay sucursal_id -->
                    <?php if (!isset($_SESSION['sucursal_id']) || !isset($_SESSION['usuario_id'])): ?>
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Error de Configuración</h5>
                            <p class="mb-0">No se pudo determinar la sucursal o usuario. Por favor, cierre sesión y vuelva a iniciar.</p>
                            <div class="mt-2">
                                <a href="logout.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-sign-out-alt me-1"></i>Cerrar Sesión
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST" id="formApertura">
                            <div class="mb-4">
                                <label for="monto_apertura" class="form-label fw-bold">Monto Inicial de Efectivo *</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">$</span>
                                    <input type="number"
                                        class="form-control money-input"
                                        id="monto_apertura"
                                        name="monto_apertura"
                                        step="0.01"
                                        min="0"
                                        required
                                        value="0.00"
                                        placeholder="0.00"
                                        inputmode="decimal">
                                </div>
                                <div class="form-text text-muted mt-2">
                                    <i class="fas fa-info-circle me-1"></i>Ingrese el monto con el que inicia la caja para dar cambio
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="observaciones" class="form-label fw-bold">Observaciones (Opcional)</label>
                                <textarea class="form-control"
                                    id="observaciones"
                                    name="observaciones"
                                    rows="3"
                                    placeholder="Ej: Turno matutino, fondo para cambio, etc..."
                                    style="resize: none;"></textarea>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg py-3">
                                    <i class="fas fa-lock-open me-2"></i>Abrir Caja e Iniciar Ventas
                                </button>
                                <a href="dashboard.php" class="btn btn-outline-secondary">Cancelar y Volver al Dashboard</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tarjeta de Información -->
            <div class="card mt-4 info-card">
                <div class="card-body">
                    <h6 class="fw-bold text-primary mb-3">
                        <i class="fas fa-info-circle me-2"></i>Información Importante
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>La caja debe abrirse</strong> al inicio de cada turno
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>El monto inicial</strong> será el efectivo para cambio
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <strong>Recuerde cerrar la caja</strong> al finalizar el turno
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled small">
                                <li class="mb-2">
                                    <i class="fas fa-store text-primary me-2"></i>
                                    <strong>Sucursal:</strong> <?php echo htmlspecialchars($nombre_sucursal); ?>
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-user text-primary me-2"></i>
                                    <strong>Usuario:</strong> <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjeta de Estado del Sistema -->
            <div class="card mt-3">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted">
                            <i class="fas fa-sync-alt me-1"></i>Estado del sistema:
                            <span class="badge bg-success">Conectado</span>
                        </small>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i><?php echo date('d/m/Y H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const montoInput = document.getElementById('monto_apertura');
            const form = document.getElementById('formApertura');

            // Enfocar y seleccionar el input de monto
            if (montoInput) {
                // En móvil, no forzar el foco automático para evitar que el teclado se abra sin querer
                if (window.innerWidth > 768) {
                    montoInput.focus();
                }
                montoInput.select();

                // Formatear automáticamente al perder el foco
                montoInput.addEventListener('blur', function() {
                    if (this.value !== '') {
                        this.value = parseFloat(this.value).toFixed(2);
                    }
                });
            }

            // Validación del formulario
            if (form) {
                form.addEventListener('submit', function(e) {
                    const monto = parseFloat(montoInput.value);

                    if (monto < 0) {
                        e.preventDefault();
                        alert('El monto de apertura no puede ser negativo');
                        montoInput.focus();
                        return false;
                    }

                    if (isNaN(monto)) {
                        e.preventDefault();
                        alert('Por favor ingrese un monto válido');
                        montoInput.focus();
                        return false;
                    }

                    // Mostrar confirmación
                    if (!confirm(`¿Está seguro de abrir la caja con $${monto.toFixed(2)}?`)) {
                        e.preventDefault();
                        return false;
                    }

                    // Mostrar loading
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Abriendo caja...';
                        submitBtn.disabled = true;
                    }
                });
            }

            // Prevenir envío con Enter en el textarea
            const observaciones = document.getElementById('observaciones');
            if (observaciones) {
                observaciones.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                    }
                });
            }

            // Cerrar menú móvil automáticamente al hacer clic en un enlace
            const mobileMenuLinks = document.querySelectorAll('#navbarMobileMenu a');
            const navbarToggler = document.querySelector('.navbar-toggler');
            const mobileMenu = document.getElementById('navbarMobileMenu');
            
            if (mobileMenuLinks.length > 0 && navbarToggler) {
                mobileMenuLinks.forEach(link => {
                    link.addEventListener('click', () => {
                        if (window.innerWidth < 992 && mobileMenu.classList.contains('show')) {
                            navbarToggler.click();
                        }
                    });
                });
            }
        });
    </script>
</body>

</html>