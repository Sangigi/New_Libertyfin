<?php
// dashboard.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\CajaRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\EmpresaRepository;
use App\Repositories\SistemaConfigRepository;
use App\Services\DashboardService;

// Antes: 6 ini_set + check de sesión manual + redirect a "Login" a mano.
Auth::requireLoginForPage('Login');

// Función segura para obtener valores de configuración (la usa el HTML de abajo)
function getConfigValue($config, $key, $default = '') {
    return isset($config[$key]) ? $config[$key] : $default;
}

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $dashboardService = new DashboardService(
        new SistemaConfigRepository($pdoEmpresa),
        new DashboardRepository($pdoEmpresa),
        new CajaRepository($pdoEmpresa),
        new EmpresaRepository(Database::pdo())
    );

    $resumen = $dashboardService->resumenPara(
        (int) $_SESSION['empresa_id'],
        (int) $_SESSION['usuario_id'],
        (int) $_SESSION['sucursal_id']
    );

    $empresa_info        = $resumen['empresa_info'];
    $logo_empresa        = $resumen['logo_empresa'];
    $logo_src_base64     = $resumen['logo_src_base64'];
    $estadisticas        = $resumen['estadisticas'];
    $empresa_plan        = $resumen['empresa_plan'];
    $timbres_totales     = $resumen['timbres_totales'];
    $timbres_disponibles = $resumen['timbres_disponibles'];
    $caja_actual         = $resumen['caja_actual'];

    // Guardar el plan en la sesión (igual que antes)
    $_SESSION['empresa_plan'] = $empresa_plan;

    // Variable para notificaciones (si existe el módulo). Integración
    // opcional con EmidaServicios — se deja igual hasta que se migre
    // esa sección (Sección 9 del roadmap).
    $notification_status = null;
    if (file_exists(__DIR__ . '/../EmidaServicios/config.php')) {
        require_once __DIR__ . '/../EmidaServicios/config.php';
        if (function_exists('getNotificationStatus')) {
            $notification_status = getNotificationStatus(Database::pdo());
        }
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
    <!-- <style>
       :root {
            --primary-color: <?php echo getConfigValue($empresa_info, 'color_primario', '#27ae60'); ?>;
            --secondary-color: <?php echo getConfigValue($empresa_info, 'color_secundario', '#2ecc71'); ?>;
        }
     </style> -->
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            touch-action: pan-y;
            overflow-x: hidden;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            will-change: transform;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .welcome-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .welcome-card .logo-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .ingresos-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Botón hamburguesa para móvil */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem;
            margin-right: 1rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }

        /* Mejoras para el sidebar táctil */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: 0;
                transform: translateX(-100%);
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            }

            .sidebar.show {
                transform: translateX(0);
                box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
            }

            main {
                margin-left: 0 !important;
                padding: 1rem !important;
                transition: transform 0.3s ease-out;
            }

            body.sidebar-open main {
                transform: translateX(280px);
            }
        }

        .badge-estado {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: #2c3e50;
        }

        .accion-btn {
            margin: 0 2px;
            padding: 5px 10px;
            font-size: 0.85rem;
        }

        .filtros-card {
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        /* Mejoras para estadísticas en móvil */
        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .metric-value {
                font-size: 1.5rem;
            }
        }

        /* Agrega este CSS al estilo existente */
        .logo-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
        }

        /* Para el logo en el navbar */
        .navbar-brand img {
            height: 30px;
            width: auto;
        }

        /* Estilos para imágenes responsivas */
        .img-logo-navbar {
            height: 30px;
            width: auto;
            max-height: 100%;
        }

        .img-logo-welcome {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
        }

        /* ========================================= */
        /* ESTILOS CORREGIDOS PARA EL VISOR DE ARCHIVOS */
        /* ========================================= */

        #modalArchivo .modal-body {
            min-height: 500px;
            padding: 0 !important;
            position: relative;
        }

        #modalArchivo .modal-dialog {
            max-width: 90%;
            height: 90vh;
        }

        #modalArchivo .modal-content {
            height: 90vh;
        }

        #modalArchivo .modal-header,
        #modalArchivo .modal-footer {
            flex-shrink: 0;
        }

        #modalArchivo .modal-body {
            flex: 1;
            overflow: hidden;
        }

        #archivoCargando {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.9);
            z-index: 10;
        }

        #visorImagen {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        #visorImagen img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        #visorPDF {
            width: 100%;
            height: 100%;
            background-color: #f8f9fa;
        }

        #visorPDF iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        #visorError {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            padding: 20px;
        }

        /* Para móviles */
        @media (max-width: 767.98px) {
            .img-logo-navbar {
                height: 25px;
            }

            .img-logo-welcome {
                width: 50px;
                height: 50px;
            }

            #modalArchivo .modal-dialog {
                max-width: 95%;
                height: 80vh;
                margin: 10px auto;
            }

            #modalArchivo .modal-content {
                height: 80vh;
            }

            .modal-xl {
                max-width: 95%;
            }
        }

        @media (max-width: 991.98px) {
            .modal-xl {
                max-width: 95%;
            }

            #modalArchivo .modal-dialog {
                max-width: 95%;
            }
        }

        /* ========================================= */
        /* ESTILOS PARA SCROLL TÁCTIL EN TABLA */
        /* ========================================= */

        .table-responsive {
            position: relative;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #27ae60 #f1f1f1;
            scroll-behavior: smooth;
        }

        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #27ae60;
            border-radius: 4px;
        }

        /* Prevenir selección de texto durante el swipe */
        .table-responsive * {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Permitir selección solo en inputs y textareas dentro de la tabla */
        .table-responsive input,
        .table-responsive textarea,
        .table-responsive select {
            -webkit-user-select: auto;
            user-select: auto;
        }

        /* Feedback visual durante el scroll */
        .table-responsive.touch-scrolling {
            cursor: grabbing;
            cursor: -webkit-grabbing;
        }

        /* Indicador visual en móvil */
        @media (max-width: 767.98px) {
            .table-responsive::after {
                content: '← Desliza para ver más →';
                position: absolute;
                bottom: 5px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(39, 174, 96, 0.9);
                color: white;
                padding: 3px 10px;
                border-radius: 15px;
                font-size: 0.75rem;
                white-space: nowrap;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s;
                z-index: 5;
            }

            .table-responsive:hover::after,
            .table-responsive.touch-active::after {
                opacity: 1;
            }
        }

        /* Botones de navegación para scroll */
        .scroll-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(39, 174, 96, 0.8);
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            opacity: 0;
            transition: opacity 0.3s, background 0.3s;
        }

        .scroll-btn:hover {
            background: rgba(39, 174, 96, 1);
        }

        .scroll-btn-left {
            left: 10px;
        }

        .scroll-btn-right {
            right: 10px;
        }

        .table-responsive:hover .scroll-btn {
            opacity: 1;
        }

        /* Solo mostrar botones en escritorio */
        @media (max-width: 767.98px) {
            .scroll-btn {
                display: none;
            }
        }

        /* Mejora la experiencia táctil */
        .table-hover tr {
            cursor: default;
        }
    </style>

    
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <!-- Mostrar logo por ruta de archivo (fallback) -->
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <i class="fas fa-cash-register me-2" style="display: none;"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                        <span class="badge bg-<?php
                                                echo match ($empresa_plan) {
                                                    'premium' => 'primary',
                                                    'emprendedor' => 'success',
                                                    'basico' => 'warning',
                                                    'prueba' => 'info',
                                                    default => 'secondary'
                                                };
                                                ?> ms-2" style="font-size: 0.5rem;">
                            <?php echo ucfirst($empresa_plan); ?>
                        </span>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="Inicio">
                                <i class="fas fa-tachometer-alt"></i>
                                Inicio
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Usuarios">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Caja">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Productos">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Clientes">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Ventas">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="CortesCaja">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="Proveedores">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>

                        <!-- MENÚ DE SUCURSALES CONDICIONAL -->
                        <?php if ($empresa_plan !== 'basico'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Sucursales">
                                    <i class="fas fa-store"></i>
                                    Sucursales
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin' && $_SESSION['sucursal_id'] == 1 && $timbres_disponibles > 0) : ?>
                            <li class="nav-item">
                                <a class="nav-link" href="Facturacion/inicio.php">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Facturación
                                    <?php if ($timbres_disponibles > 0): ?>
                                        <span class="badge bg-success ms-2" style="font-size: 0.65rem;">
                                            <?php echo $timbres_disponibles; ?> timbres
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;">
                                            Sin timbres
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="Reportes">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                        <?php if ($empresa_plan === 'premium'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../EmidaServicios/inicio.php">
                                    <img src="../images/emidalogo.png" alt="" style="width: 20px; height: 20px; margin-right: 10px; object-fit: contain;">
                                    Emida Servicios
                                    <?php if ($notification_status && isset($notification_status['notification_status']) && !$notification_status['notification_status']['success']): ?>
                                        <span class="badge bg-warning ms-2" style="font-size: 0.65rem;" title="Notificaciones no configuradas">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="Configuracion">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4" id="mainContent">
                <!-- Welcome Card -->
                <div class="card welcome-card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center mb-3">
                                    <?php if ($logo_src_base64): ?>
                                        <!-- Mostrar logo en base64 -->
                                        <img src="<?php echo $logo_src_base64; ?>"
                                            alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                                            style="width: 120px; height: 120px; object-fit: contain; border-radius: 10px; margin-right: 15px;"
                                            class="me-3">
                                    <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                                        <!-- Mostrar logo por ruta de archivo (fallback) -->
                                        <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                                            alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                                            style="width: 120px; height: 120px; object-fit: contain; border-radius: 10px; margin-right: 15px;"
                                            class="me-3"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="logo-placeholder" style="display: none;">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    <?php else: ?>
                                        <!-- Mostrar icono por defecto -->
                                        <div class="logo-placeholder me-3">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h4 class="card-title mb-2">
                                            <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
                                            <span class="badge bg-<?php
                                                                    echo match ($empresa_plan) {
                                                                        'premium' => 'primary',
                                                                        'emprendedor' => 'success',
                                                                        'basico' => 'warning',
                                                                        'prueba' => 'info',
                                                                        default => 'secondary'
                                                                    };
                                                                    ?> ms-2" style="font-size: 0.7rem;">
                                                Plan <?php echo ucfirst($empresa_plan); ?>
                                            </span>
                                        </h4>
                                        <p class="card-text mb-0 opacity-75">
                                            Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                                        </p>
                                        <small class="opacity-75">
                                            <i class="fas fa-user-tag me-1"></i>
                                            Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?>
                                            <?php if ($empresa_plan === 'basico'): ?>
                                                <span class="ms-2 text-warning">
                                                    <i class="fas fa-info-circle"></i> Plan Básico: Sucursales deshabilitadas
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <i class="fas fa-cash-register fa-4x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <a href="Productos" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Productos</div>
                                            <div class="metric-value text-primary"><?php echo $estadisticas['total_productos']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-boxes fa-2x text-primary opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Clientes" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Clientes</div>
                                            <div class="metric-value text-success"><?php echo $estadisticas['total_clientes']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-users fa-2x text-success opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Ventas" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Ventas Hoy</div>
                                            <div class="metric-value text-warning"><?php echo $estadisticas['ventas_hoy']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-shopping-cart fa-2x text-warning opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-2 mb-3">
                        <a href="Usuarios" class="text-decoration-none">
                            <div class="card stat-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label">Usuarios</div>
                                            <div class="metric-value text-info"><?php echo $estadisticas['total_usuarios']; ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-user-check fa-2x text-info opacity-25"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="Reportes" class="text-decoration-none">
                            <div class="card ingresos-card h-100" style="cursor: pointer; transition: transform 0.2s;">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="metric-label text-white-50">Ingresos Hoy</div>
                                            <div class="metric-value text-white">$<?php echo number_format($estadisticas['ingresos_hoy'], 2); ?></div>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="fas fa-money-bill-wave fa-2x text-white opacity-50"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Estado de Caja -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-cash-register me-2"></i>Estado de Caja
                                </h5>
                                <?php if ($caja_actual): ?>
                                    <span class="badge bg-success">Caja Abierta</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Caja Cerrada</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <?php if ($caja_actual): ?>
                                            <p class="mb-2">Caja abierta desde: <strong><?php echo date('H:i', strtotime($caja_actual['fecha_apertura'])); ?></strong></p>
                                            <p class="mb-2">Monto inicial: <strong>$<?php echo number_format($caja_actual['monto_apertura'], 2); ?></strong></p>
                                            <p class="mb-0">Estado: <span class="badge bg-success">Abierta</span></p>
                                        <?php else: ?>
                                            <p class="mb-3">No hay una caja abierta. Debe abrir caja para poder realizar ventas.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <?php if ($caja_actual): ?>
                                            <a href="caja_cierre.php" class="btn btn-warning me-2">
                                                <i class="fas fa-lock me-1"></i>Cerrar Caja
                                            </a>
                                        <?php else: ?>
                                            <a href="caja_apertura.php" class="btn btn-primary me-2">
                                                <i class="fas fa-lock-open me-1"></i>Abrir Caja
                                            </a>
                                        <?php endif; ?>
                                        <a href="CortesCaja" class="btn btn-outline-secondary">
                                            <i class="fas fa-history me-1"></i>Historial
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas -->
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bolt me-2"></i>Acciones Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 btn-group-actions">
                                    <div class="col-md-6">
                                        <a href="Caja" class="btn btn-primary w-100 text-start p-3">
                                            <i class="fas fa-cash-register fa-2x mb-2"></i>
                                            <h6>Nueva Venta</h6>
                                            <small class="text-white-50">Iniciar punto de venta</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Productos" class="btn btn-success w-100 text-start p-3">
                                            <i class="fas fa-boxes fa-2x mb-2"></i>
                                            <h6>Gestionar Productos</h6>
                                            <small class="text-white-50">Agregar o editar productos</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Clientes" class="btn btn-info w-100 text-start p-3">
                                            <i class="fas fa-users fa-2x mb-2"></i>
                                            <h6>Clientes</h6>
                                            <small class="text-white-50">Administrar clientes</small>
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="Reportes" class="btn btn-warning w-100 text-start p-3">
                                            <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                            <h6>Reportes</h6>
                                            <small class="text-white-50">Ver estadísticas</small>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Información de la Empresa
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <small class="text-muted">Empresa:</small>
                                    <p class="mb-1 fw-bold"><?php echo htmlspecialchars($empresa_info['nombre_empresa']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">RFC:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['rfc']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Teléfono:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['telefono']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Email:</small>
                                    <p class="mb-1"><?php echo htmlspecialchars($empresa_info['email']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Plan Actual:</small>
                                    <p class="mb-1">
                                        <span class="badge bg-<?php
                                                                echo match ($empresa_plan) {
                                                                    'premium' => 'primary',
                                                                    'emprendedor' => 'success',
                                                                    'basico' => 'warning',
                                                                    'prueba' => 'info',
                                                                    default => 'secondary'
                                                                };
                                                                ?>">
                                            <?php echo ucfirst($empresa_plan); ?>
                                        </span>
                                        <?php if ($empresa_plan === 'basico'): ?>
                                            <small class="text-warning d-block mt-1">
                                                <i class="fas fa-info-circle"></i> Las sucursales están deshabilitadas en este plan
                                            </small>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // =============================================
        // FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
        // =============================================

        // Variables para controlar el swipe
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isTouchActive = false;
        const SWIPE_THRESHOLD = 50; // Mínimo de píxeles para considerar un swipe
        const SWIPE_EDGE_ZONE = 30; // Zona del borde donde se activa el swipe
        const VERTICAL_THRESHOLD = 30; // Máxima desviación vertical permitida

        // Función para abrir el sidebar automáticamente
        function openSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.add('show');
                sidebarBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        // Función para cerrar el sidebar automáticamente
        function closeSidebarAuto() {
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            if (sidebar && sidebarBackdrop) {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }

        // Detectar inicio del touch
        document.addEventListener('touchstart', function(e) {
            // Solo en dispositivos móviles
            if (window.innerWidth >= 768) return;

            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchEndX = touchStartX;
            touchEndY = touchStartY;
            isTouchActive = true;
        });

        // Detectar movimiento del touch
        document.addEventListener('touchmove', function(e) {
            if (!isTouchActive) return;

            touchEndX = e.touches[0].clientX;
            touchEndY = e.touches[0].clientY;

            // Calcular diferencia
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;

            // Solo prevenir el scroll si es un movimiento horizontal significativo
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
                e.preventDefault();
            }
        }, {
            passive: false
        });

        // Detectar fin del touch
        document.addEventListener('touchend', function(e) {
            if (!isTouchActive) return;

            isTouchActive = false;

            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;

            // Verificar que sea un swipe horizontal válido
            if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
                return; // Demasiada desviación vertical
            }

            const sidebar = document.getElementById('sidebar');
            const isSidebarOpen = sidebar && sidebar.classList.contains('show');

            // SWIPE DE IZQUIERDA A DERECHA (para abrir)
            if (deltaX > SWIPE_THRESHOLD) {
                // Solo abrir si empezó cerca del borde izquierdo
                if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                    openSidebarAuto();
                }
            }
            // SWIPE DE DERECHA A IZQUIERDA (para cerrar)
            else if (deltaX < -SWIPE_THRESHOLD) {
                // Cerrar si el sidebar está abierto
                if (isSidebarOpen) {
                    closeSidebarAuto();
                }
            }

            // Resetear valores
            touchStartX = 0;
            touchStartY = 0;
            touchEndX = 0;
            touchEndY = 0;
        });

        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                if (sidebar.classList.contains('show')) {
                    closeSidebarAuto();
                } else {
                    openSidebarAuto();
                }
            }

            // Event listeners para el botón hamburguesa
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', closeSidebarAuto);
            }

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebarAuto();
                    }
                });
            });

            // Ajustar altura de cards en móvil
            function adjustCardHeights() {
                if (window.innerWidth < 576) {
                    const statCards = document.querySelectorAll('.stat-card');
                    let maxHeight = 0;

                    statCards.forEach(card => {
                        const height = card.offsetHeight;
                        if (height > maxHeight) {
                            maxHeight = height;
                        }
                    });

                    statCards.forEach(card => {
                        card.style.minHeight = maxHeight + 'px';
                    });
                } else {
                    const statCards = document.querySelectorAll('.stat-card');
                    statCards.forEach(card => {
                        card.style.minHeight = '';
                    });
                }
            }
            const statCards = document.querySelectorAll('.stat-card, .ingresos-card');

            statCards.forEach(card => {
                // Para dispositivos táctiles
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                    this.style.transition = 'transform 0.1s ease';
                });

                card.addEventListener('touchend', function() {
                    this.style.transform = '';
                });

                card.addEventListener('touchcancel', function() {
                    this.style.transform = '';
                });

                // Para mouse (efecto hover)
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                });
            });

            // Ejecutar al cargar y al redimensionar
            adjustCardHeights();
            window.addEventListener('resize', adjustCardHeights);

            // Ajustar sidebar en redimensionamiento
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeSidebarAuto();
                }
            });

            // Agregar indicador visual de zona de swipe (opcional)
            const swipeZoneIndicator = document.createElement('div');
            swipeZoneIndicator.id = 'swipeZoneIndicator';
            swipeZoneIndicator.style.cssText = `
                position: fixed;
                top: 56px;
                left: 0;
                width: ${SWIPE_EDGE_ZONE}px;
                height: calc(100vh - 56px);
                background: linear-gradient(90deg, rgba(39, 174, 96, 0.2), transparent);
                z-index: 9999;
                pointer-events: none;
                opacity: 0;
                transition: opacity 0.3s;
                display: none;
            `;
            document.body.appendChild(swipeZoneIndicator);

            // Mostrar/ocultar indicador según el tamaño de pantalla
            function updateSwipeZone() {
                if (window.innerWidth < 768) {
                    swipeZoneIndicator.style.display = 'block';
                } else {
                    swipeZoneIndicator.style.display = 'none';
                }
            }

            updateSwipeZone();
            window.addEventListener('resize', updateSwipeZone);

            // Mostrar indicador al tocar cerca del borde
            document.addEventListener('touchstart', function(e) {
                if (window.innerWidth >= 768) return;

                const touchX = e.touches[0].clientX;
                if (touchX <= SWIPE_EDGE_ZONE) {
                    swipeZoneIndicator.style.opacity = '0.5';
                }
            });

            document.addEventListener('touchend', function() {
                swipeZoneIndicator.style.opacity = '0';
            });

            // Mejorar la experiencia táctil
            let startX = 0;
            let currentX = 0;

            sidebar.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
            }, {
                passive: true
            });

            sidebar.addEventListener('touchmove', (e) => {
                currentX = e.touches[0].clientX;
                const diff = startX - currentX;

                if (diff > 50) { // Deslizar hacia la izquierda para cerrar
                    closeSidebarAuto();
                }
            }, {
                passive: true
            });
        });
    </script>
</body>

</html>