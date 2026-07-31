<?php
// caja_resumen.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\CajaRepository;
use App\Repositories\MovimientoCajaRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\VentaRepository;
use App\Services\CajaService;
use App\Support\Formato;
use App\Support\LogoResolver;

Auth::requireLoginForPage('login.php');

if (!isset($_GET['id'])) {
    header("Location: caja_historial.php");
    exit();
}

// Compatibilidad con el HTML de abajo, que llama estas funciones por su
// nombre original — la lógica real vive en App\Support\Formato.
function formatDateSafe($valor, $formato = 'd/m/Y H:i', $default = 'No disponible')
{
    return Formato::fecha($valor, $formato, $default);
}
function formatCurrency($monto, $default = '0.00')
{
    return Formato::moneda($monto, $default);
}
function getDiferenciaClass($diferencia)
{
    return Formato::claseDiferencia($diferencia);
}

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $empresa_info = (new SistemaConfigRepository($pdoEmpresa))->actual();

    $cajaService = new CajaService(
        new CajaRepository($pdoEmpresa),
        new VentaRepository($pdoEmpresa),
        new MovimientoCajaRepository($pdoEmpresa)
    );

    $caja_id = intval($_GET['id']);
    $detalle = $cajaService->detalleConMovimientos($caja_id);

    if ($detalle === null) {
        header("Location: caja_historial.php");
        exit();
    }

    $caja        = $detalle['caja'];
    $movimientos = $detalle['movimientos'];

    $logo             = LogoResolver::resolver($empresa_info['logo'] ?? null);
    $logo_empresa     = $logo['path'];
    $logo_src_base64  = $logo['base64'];
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen de Caja - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
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
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .navbar-brand img {
            height: 40px;
            width: auto;
            max-width: 120px;
            object-fit: contain;
            border-radius: 4px;
        }


        .resumen-container {
            max-width: 800px;
            margin: 30px auto;
        }

        .header-resumen {
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }

        .totales-card {
            border-left: 4px solid #28a745;
        }

        .diferencia {
            font-size: 1.3rem;
            font-weight: bold;
        }

        .diferencia.positiva {
            color: #28a745;
        }

        .diferencia.negativa {
            color: #dc3545;
        }

        .diferencia.cero {
            color: #6c757d;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        @media print {
            .no-print {
                display: none;
            }

            .container {
                max-width: 100% !important;
            }

            .navbar {
                display: none;
            }

            body {
                background-color: white;
            }

            .card {
                box-shadow: none;
                border: 1px solid #dee2e6;
            }
        }
    </style>
    <!-- Tema unificado LibertyFin (estilo landing) -->
    <!-- <link rel="stylesheet" href="css/crm-theme.css"> -->
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <?php if ($logo_src_base64): ?>
                    <!-- Mostrar logo en base64 -->
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>"
                        class="me-2">
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
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
                    </span>
                <?php else: ?>
                    <!-- Mostrar icono por defecto -->
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
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

    <div class="container">
        <div class="resumen-container">
            <!-- Encabezado -->
            <div class="header-resumen text-center">
                <h2 class="mb-1"><?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></h2>
                <h4 class="text-muted">Corte de Caja</h4>
                <p class="mb-0 text-muted"><?php echo date('d/m/Y H:i:s'); ?></p>
            </div>

            <!-- Información del corte -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Información del Turno</h6>
                            <p class="mb-1"><strong>Usuario:</strong> <?php echo htmlspecialchars($caja['usuario_nombre']); ?></p>
                            <p class="mb-1"><strong>Sucursal:</strong> <?php echo htmlspecialchars($caja['sucursal_nombre']); ?></p>
                            <p class="mb-1"><strong>Apertura:</strong> <?php echo formatDateSafe($caja['fecha_apertura'], 'd/m/Y H:i', 'No registrada'); ?></p>
                            <p class="mb-0"><strong>Cierre:</strong> <?php echo formatDateSafe($caja['fecha_cierre'], 'd/m/Y H:i', 'No cerrada'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card totales-card">
                        <div class="card-body">
                            <h6 class="card-title">Resumen Financiero</h6>
                            <p class="mb-1"><strong>Monto Apertura:</strong> $<?php echo formatCurrency($caja['monto_apertura']); ?></p>
                            <p class="mb-1"><strong>Monto Cierre:</strong> $<?php echo formatCurrency($caja['monto_cierre']); ?></p>
                            <p class="mb-1"><strong>Monto Esperado:</strong> $<?php echo formatCurrency($caja['monto_esperado']); ?></p>
                            <p class="mb-0 diferencia <?php echo getDiferenciaClass($caja['diferencia']); ?>">
                                <strong>Diferencia:</strong> $<?php echo formatCurrency($caja['diferencia']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Desglose de ventas -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Desglose de Ventas</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h6 class="text-muted">Efectivo</h6>
                            <h4 class="text-success">$<?php echo formatCurrency($caja['ventas_efectivo']); ?></h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">Tarjeta</h6>
                            <h4 class="text-info">$<?php echo formatCurrency($caja['ventas_tarjeta']); ?></h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">Transferencia</h6>
                            <h4 class="text-warning">$<?php echo formatCurrency($caja['ventas_transferencia']); ?></h4>
                        </div>
                        <div class="col-md-3">
                            <h6 class="text-muted">Total Ventas</h6>
                            <h4 class="text-primary">$<?php echo formatCurrency($caja['total_ventas']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Otros movimientos -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Otros Movimientos</h5>
                    <span>
                        <small class="text-muted">
                            Ingresos: $<?php echo formatCurrency($caja['otros_ingresos']); ?> |
                            Egresos: $<?php echo formatCurrency($caja['otros_egresos']); ?>
                        </small>
                    </span>
                </div>
                <div class="card-body p-0">
                    <?php if (count($movimientos) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Concepto</th>
                                        <th>Tipo</th>
                                        <th>Método</th>
                                        <th>Monto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($movimientos as $movimiento): ?>
                                        <tr>
                                            <td><?php echo formatDateSafe($movimiento['fecha'], 'H:i', '--:--'); ?></td>
                                            <td><?php echo htmlspecialchars($movimiento['concepto']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $movimiento['tipo'] == 'ingreso' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($movimiento['tipo']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo ucfirst($movimiento['metodo_pago']); ?></td>
                                            <td class="fw-bold <?php echo $movimiento['tipo'] == 'ingreso' ? 'text-success' : 'text-danger'; ?>">
                                                $<?php echo formatCurrency($movimiento['monto']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <p class="text-muted mb-0">No hay movimientos registrados</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Observaciones -->
            <?php if (!empty($caja['observaciones'])): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Observaciones</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($caja['observaciones'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Botones de acción -->
            <div class="d-grid gap-2 d-md-flex justify-content-md-end no-print">
                <button onclick="window.print()" class="btn btn-outline-primary me-md-2">
                    <i class="fas fa-print me-2"></i>Imprimir
                </button>
                <a href="caja_historial.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Volver al Historial
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>