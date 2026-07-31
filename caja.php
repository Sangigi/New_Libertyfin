<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/storage/logs/php_errors.log');

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Http\Middleware\Auth;
use App\Repositories\CajaRepository;
use App\Repositories\CategoriaRepository;
use App\Repositories\ClienteRepository;
use App\Repositories\EmpresaRepository;
use App\Repositories\GastoRepository;
use App\Repositories\MovimientoCajaRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VentaRepository;
use App\Services\CajaService;
use App\Services\CarritoService;
use App\Services\Exceptions\CajaNoAbiertaException;
use App\Services\FacturapiKeyService;
use App\Services\FacturapiReceiptService;
use App\Services\VentaService;
use App\Support\LogoResolver;
use Facturapi\Facturapi;

// Registrar errores fatales — si es una petición AJAX de las que
// esperan JSON, responder con JSON de error en vez de dejar salir
// HTML crudo. Antes esta lista cubría solo 5 de las 8 acciones AJAX;
// eliminar/vaciar/actualizar_cliente también responden JSON y se
// habían quedado fuera.
register_shutdown_function(function () {
    $error = error_get_last();
    $accionesJson = [
        'actualizar_descuento_ajax', 'agregar_producto_ajax', 'actualizar_cantidad_ajax',
        'actualizar_precio_ajax', 'actualizar_comisiones_carrito_ajax',
        'eliminar_producto_ajax', 'vaciar_carrito_ajax', 'actualizar_cliente_ajax',
    ];

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        Logger::error('caja', 'Fatal error', ['error' => $error]);

        foreach ($accionesJson as $accion) {
            if (isset($_POST[$accion])) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Error fatal: ' . $error['message']]);
                exit();
            }
        }
    }
});

Auth::requireLoginForPage('login.php');

// -----------------------------------------------------------------------
// Compatibilidad: el HTML de abajo llama estas dos funciones por su
// nombre original varias veces al renderizar cada fila de producto o
// carrito — la lógica real vive en ProductoRepository. $conn se acepta
// y se ignora, solo por compatibilidad de firma.
// -----------------------------------------------------------------------
function obtenerImagenProducto($productoId, $conn = null)
{
    global $productoRepository;
    return $productoRepository->imagenPath((int) $productoId);
}

function obtenerPrecioConMayoreo($productoId, $cantidad, $conn = null)
{
    global $productoRepository;
    return $productoRepository->precioConMayoreo((int) $productoId, (float) $cantidad);
}

// ========== EMPRESA, PLAN Y FACTURAPI ==========
try {
    $empresaRepoMain = new EmpresaRepository(Database::pdo());
    $empresaId = (int) ($_SESSION['empresa_id'] ?? 0);
    $planInfo = $empresaRepoMain->findPlanInfo($empresaId);

    $empresa_plan = $planInfo['plan'] ?? 'prueba';
    $organization_id = $planInfo['facturapi_organization_id'] ?? null;
    $timbres_totales = $planInfo['timbres_totales'] ?? 0;
    $timbres_disponibles = $planInfo['timbres_disponibles'] ?? 0;

    $_SESSION['empresa_plan'] = $empresa_plan;
    $_SESSION['organization_id'] = $organization_id;
} catch (Exception $e) {
    Logger::error('caja', 'Error al conectar a BD principal', ['error' => $e->getMessage()]);
    $_SESSION['error_message'] = 'Error de conexión a la base de datos. Contacte al administrador.';
    header('Location: dashboard.php');
    exit();
}

// ========== CONEXIÓN A LA BASE DE DATOS DE LA EMPRESA ==========
$dbname = $_SESSION['empresa_db'] ?? '';
if (empty($dbname)) {
    Logger::error('caja', 'No se ha especificado la base de datos de la empresa');
    $_SESSION['error_message'] = 'Error de configuración. Contacte al administrador.';
    header('Location: dashboard.php');
    exit();
}

try {
    $conn = Database::pdo($dbname);
} catch (Exception $e) {
    Logger::error('caja', 'Error al conectar a BD de empresa', ['error' => $e->getMessage()]);
    $_SESSION['error_message'] = 'Error de conexión a la base de datos de la empresa. Contacte al administrador.';
    header('Location: dashboard.php');
    exit();
}

// Repositorios/servicios que dependen de la conexión de empresa.
$sistemaConfigRepo = new SistemaConfigRepository($conn);
$productoRepository = new ProductoRepository($conn); // usado también por los shims globales de arriba
$categoriaRepository = new CategoriaRepository($conn);
$clienteRepository = new ClienteRepository($conn);
$cajaRepository = new CajaRepository($conn);
$ventaRepository = new VentaRepository($conn);
$movimientoCajaRepository = new MovimientoCajaRepository($conn);
$gastoRepository = new GastoRepository($conn);
$usuarioRepository = new UsuarioRepository($conn);

$cajaService = new CajaService($cajaRepository, $ventaRepository, $movimientoCajaRepository);
$carritoService = new CarritoService($productoRepository, $clienteRepository);
$facturapiReceiptService = new FacturapiReceiptService($productoRepository, $clienteRepository, $ventaRepository);
$ventaService = new VentaService($conn, $ventaRepository, $productoRepository, $cajaRepository, $gastoRepository, $facturapiReceiptService);

// ========== API KEY DE PRUEBA DE FACTURAPI ==========
$facturapiKeyService = new FacturapiKeyService($sistemaConfigRepo);
$test_api_key_working = $facturapiKeyService->obtenerParaOrganizacion($organization_id, $empresa_plan);
if ($test_api_key_working) {
    $_SESSION['test_api_key'] = $test_api_key_working;
}

// ========== CONFIGURACIÓN DEL SISTEMA Y LOGO (una sola consulta; antes eran 2) ==========
$empresa_nombre = $_SESSION['empresa_nombre'] ?? 'Sistema';
$sistema_config = $sistemaConfigRepo->actual();

$iva_porcentaje = 0; // FORZAR IVA CERO — igual que el original
$moneda = $sistema_config['moneda'] ?? 'MXN';
$color_primario = $sistema_config['color_primario'] ?? '#27ae60';
$color_secundario = $sistema_config['color_secundario'] ?? '#2ecc71';

if (!empty($sistema_config['nombre_empresa'])) {
    $empresa_nombre = $sistema_config['nombre_empresa'];
    $_SESSION['empresa_nombre'] = $empresa_nombre;
}

$logo = LogoResolver::resolver($sistema_config['logo'] ?? null);
$logo_path = $logo['path'];
$logo_empresa = $logo['path'];
$logo_src_base64 = $logo['base64'];

// ========== VERIFICACIÓN DE CAJA ABIERTA ==========
$usuario_id = (int) ($_SESSION['usuario_id'] ?? 0);
$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);

$caja_actual = $cajaService->resolverActivaParaSesion(
    isset($_SESSION['caja_actual_id']) ? (int) $_SESSION['caja_actual_id'] : null,
    $usuario_id,
    $sucursal_id
);

if ($caja_actual) {
    $_SESSION['caja_actual_id'] = $caja_actual['id'];
    $_SESSION['caja_actual'] = $caja_actual;
} else {
    header('Location: caja_apertura.php');
    exit();
}

if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

// =========================================================================
// ACCIONES AJAX DEL CARRITO — antes ~700 líneas inline, ahora CarritoService.
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_precio_ajax'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    try {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            throw new Exception('Sesión no válida');
        }
        $index = intval($_POST['index']);
        $nuevoPrecio = floatval(str_replace(',', '.', $_POST['precio']));
        $response = $carritoService->actualizarPrecio($index, $nuevoPrecio);
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage(), 'carrito_actualizado' => $_SESSION['carrito'] ?? [], 'totales' => $carritoService->calcularTotales()];
        Logger::error('caja', 'Error en actualizar_precio_ajax', ['error' => $e->getMessage()]);
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_comisiones_carrito_ajax'])) {
    header('Content-Type: application/json');
    try {
        $index = intval($_POST['index'] ?? -1);
        $comisiones = json_decode($_POST['comisiones'] ?? '[]', true);
        $carritoService->actualizarComisiones($index, is_array($comisiones) ? $comisiones : []);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cantidad_ajax'])) {
    header('Content-Type: application/json');
    try {
        $index = intval($_POST['index']);
        $response = $carritoService->actualizarCantidad($index, $_POST['cantidad'], $sucursal_id);
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage(), 'carrito_actualizado' => $_SESSION['carrito'] ?? [], 'totales' => $carritoService->calcularTotales()];
    }
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_descuento_ajax'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Sesión no válida. Por favor inicie sesión nuevamente.']);
        exit();
    }
    try {
        $productoIdDescuento = isset($_POST['producto_id']) ? (int) $_POST['producto_id'] : 0;
        $descuentoPorcentaje = isset($_POST['descuento_porcentaje']) ? (float) $_POST['descuento_porcentaje'] : 0;
        $indexDescuento = isset($_POST['index']) ? (int) $_POST['index'] : -1;

        if ($productoIdDescuento <= 0) {
            throw new Exception('ID de producto no válido');
        }

        $response = $carritoService->actualizarDescuento($indexDescuento, $productoIdDescuento, $descuentoPorcentaje);
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage(), 'carrito_actualizado' => [], 'totales' => []];
        Logger::error('caja', 'Error en actualizar_descuento_ajax', ['error' => $e->getMessage()]);
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_producto_ajax'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    try {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            throw new Exception('Sesión no válida');
        }
        $productoIdAgregar = intval($_POST['producto_id']);
        $cantidadAgregar = floatval($_POST['cantidad']);
        $response = $carritoService->agregarProducto($productoIdAgregar, $cantidadAgregar, $sucursal_id);
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage(), 'carrito_actualizado' => [], 'totales' => []];
        Logger::error('caja', 'Error en agregar_producto_ajax', ['error' => $e->getMessage()]);
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_producto_ajax'])) {
    header('Content-Type: application/json');
    $response = $carritoService->eliminarProducto(intval($_POST['index']));
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vaciar_carrito_ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($carritoService->vaciar());
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cliente_ajax'])) {
    header('Content-Type: application/json');
    $clienteIdPost = isset($_POST['cliente_id']) ? ($_POST['cliente_id'] === '' ? null : intval($_POST['cliente_id'])) : null;
    try {
        echo json_encode($carritoService->actualizarCliente($clienteIdPost));
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar cliente: ' . $e->getMessage()]);
    }
    exit();
}

// =========================================================================
// PROCESAR PAGO — antes ~360 líneas inline, ahora VentaService.
// =========================================================================
if (isset($_POST['procesar_pago'])) {
    try {
        $resultado = $ventaService->procesar(
            carrito: $_SESSION['carrito'] ?? [],
            metodoPago: $_POST['metodo_pago'] ?? 'efectivo',
            efectivoRecibido: floatval($_POST['efectivo_recibido'] ?? 0),
            cambio: floatval($_POST['cambio'] ?? 0),
            descuentoTotalPost: floatval($_POST['descuento_total'] ?? 0),
            descripcion: $_POST['descripcion'] ?? '',
            cajaId: (int) ($_SESSION['caja_actual_id'] ?? $caja_actual['id']),
            usuarioId: $usuario_id,
            sucursalId: $sucursal_id,
            clienteId: !empty($_SESSION['cliente_venta']) ? (int) $_SESSION['cliente_venta'] : null,
            empresaPlan: $empresa_plan,
            facturapiApiKey: $_SESSION['test_api_key'] ?? $test_api_key_working,
        );

        if ($resultado['warning']) {
            $_SESSION['warning_message'] = $resultado['warning'];
        }

        $_SESSION['venta_realizada'] = [
            'codigo_venta'          => $resultado['codigo_venta'],
            'total'                 => $resultado['total'],
            'efectivo_recibido'     => floatval($_POST['efectivo_recibido'] ?? 0),
            'cambio'                => floatval($_POST['cambio'] ?? 0),
            'metodo_pago'           => $_POST['metodo_pago'] ?? 'efectivo',
            'fecha'                 => date('Y-m-d H:i:s'),
            'productos'             => $_SESSION['carrito'],
            'subtotal'              => $resultado['subtotal'],
            'descuento'             => $resultado['descuento'],
            'iva'                   => $resultado['iva'],
            'iva_porcentaje'        => 0,
            'cliente_id'            => !empty($_SESSION['cliente_venta']) ? (int) $_SESSION['cliente_venta'] : null,
            'venta_id'              => $resultado['venta_id'],
            'plan_empresa'          => $empresa_plan,
            'timbres_disponibles'   => $timbres_disponibles,
            'facturapi_receipt_id'  => $resultado['facturapi_receipt_id'],
            'facturapi_invoice_url' => $resultado['facturapi_invoice_url'],
        ];

        $_SESSION['carrito'] = [];
        unset($_SESSION['cliente_venta']);

        header('Location: caja.php?venta_exitosa=true');
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        Logger::error('caja', 'Error en procesar_pago', ['error' => $e->getMessage()]);
        header('Location: caja.php');
        exit();
    }
}

// ========== CATEGORÍAS, PRODUCTOS Y CLIENTES PARA EL RENDER ==========
$categorias_con_count = $categoriaRepository->conConteoProductos($sucursal_id);

$categoria_seleccionada = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : null;
$busqueda_nombre = isset($_GET['busqueda_nombre']) ? trim($_GET['busqueda_nombre']) : '';

$productos = $productoRepository->listar($sucursal_id, $categoria_seleccionada, $busqueda_nombre);

// Antes: una consulta "¿tiene mayoreo?" POR PRODUCTO dentro del bucle
// de renderizado (N+1), repetida además en la vista de escritorio Y en
// la de móvil. Ahora, una sola consulta por lote.
$productosConMayoreo = array_flip($productoRepository->idsConMayoreo(array_column($productos, 'id')));

$clientes = $clienteRepository->activos();

// ========== TOTALES DEL CARRITO PARA EL RENDER ==========
$carrito_json = json_encode($_SESSION['carrito'] ?? []);
$carrito_count = count($_SESSION['carrito'] ?? []);
$totales_render = $carritoService->calcularTotales();
$subtotal_carrito = $totales_render['subtotal'];
$descuento_carrito = $totales_render['descuento'];
$subtotal_con_descuento_carrito = $totales_render['subtotal_con_descuento'];
$iva_carrito = 0;
$total_carrito = $totales_render['total'];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja - <?php echo htmlspecialchars($empresa_nombre); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/cajas.css">
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars($color_primario); ?>;
            --secondary-color: <?php echo htmlspecialchars($color_secundario); ?>;
            --dark-green: <?php echo htmlspecialchars($color_primario); ?>;
            --light-green: <?php echo htmlspecialchars($color_primario); ?>20;
        }
    </style>
</head>

<body>
    <!-- Navbar Principal (Desktop) -->
    <nav class="navbar navbar-expand-lg navbar-dark main-navbar">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="dashboard.php">
                <?php if (isset($logo_src_base64) && !empty($logo_src_base64)): ?>
                    <img src="<?php echo $logo_src_base64; ?>"
                        alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                        class="me-2"
                        style="height: 40px; width: auto; max-width: 120px; object-fit: contain; border-radius: 4px;">
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                    <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                        alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                        class="me-2"
                        style="height: 40px; width: auto; max-width: 120px; object-fit: contain; border-radius: 4px;">
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php else: ?>
                    <i class="fas fa-cash-register me-2"></i>
                    <span>
                        <?php echo htmlspecialchars($empresa_nombre); ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="navbar-nav ms-auto align-items-center">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?>
                </span>
                <span class="status-badge me-3">
                    <i class="fas fa-circle me-1"></i>Caja Abierta
                </span>
                <a href="dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <!-- Navbar Móvil -->
    <div class="mobile-navbar">
        <div class="mobile-navbar-brand d-flex align-items-center">
            <?php if (isset($logo_src_base64) && !empty($logo_src_base64)): ?>
                <img src="<?php echo $logo_src_base64; ?>"
                    alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                    class="me-2">
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php elseif ($logo_empresa && file_exists($logo_empresa)): ?>
                <img src="<?php echo htmlspecialchars($logo_empresa); ?>"
                    alt="<?php echo htmlspecialchars($empresa_nombre); ?>"
                    class="me-2">
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php else: ?>
                <i class="fas fa-cash-register me-2"></i>
                <span>
                    <?php echo htmlspecialchars($empresa_nombre); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center">
            <span class="status-badge me-2">
                <i class="fas fa-circle me-1"></i>Caja Abierta
            </span>
            <a href="dashboard.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>
    </div>

    <!-- Mensajes de Alerta con Auto-ocultamiento -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-2 auto-hide-alert" role="alert" data-auto-hide="2000">
            <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-2 auto-hide-alert" role="alert" data-auto-hide="2000">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Modal para cantidad de productos por peso/volumen -->
    <div class="modal fade cantidad-modal" id="cantidadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cantidadModalTitle">Seleccionar Cantidad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="cantidadForm">
                        <input type="hidden" id="productoIdModal" name="producto_id">
                        <div class="mb-3">
                            <label class="form-label" id="cantidadLabel">Cantidad</label>
                            <div class="cantidad-input-group">
                                <input type="number" class="form-control" id="cantidadInput" name="cantidad"
                                    step="0.001" min="0.001" value="1.000" required>
                                <span class="unidad-medida" id="unidadMedidaText">kg</span>
                            </div>
                            <small class="form-text text-muted" id="cantidadHelp">Ingrese la cantidad deseada</small>
                        </div>
                        <div class="cantidad-preset" id="presetContainer">
                            <!-- Los botones de cantidad predefinida se generarán con JavaScript -->
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnAgregarConCantidad">Agregar al Carrito</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para editar descuento -->
    <div class="modal fade" id="editarDescuentoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-tag me-2"></i>Editar Descuento
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto:</label>
                        <p id="productoNombreEditar" class="mb-2 text-primary fw-bold"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Precio unitario:</label>
                        <p id="precioUnitarioEditar" class="mb-2">$0.00</p>
                    </div>

                    <div class="mb-3">
                        <label for="porcentajeDescuento" class="form-label fw-bold">Porcentaje de Descuento (%)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="porcentajeDescuento"
                                step="0.01" min="0" max="100" value="0">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">Ingrese el porcentaje de descuento (0-100%)</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Vista previa:</label>
                        <div class="bg-light p-3 rounded">
                            <div class="row">
                                <div class="col-6">
                                    <span class="text-muted">Subtotal:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewSubtotal" class="fw-bold">$0.00</span>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <span class="text-muted">Descuento:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewDescuento" class="text-danger fw-bold">-$0.00</span>
                                </div>
                            </div>
                            <div class="row mt-2 border-top pt-2">
                                <div class="col-6">
                                    <span class="fw-bold">Total:</span>
                                </div>
                                <div class="col-6 text-end">
                                    <span id="previewTotal" class="fw-bold text-success">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning" id="descuentoGuardarAdvertencia" style="display: none;">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Este descuento se guardará en la base de datos para futuras ventas.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" id="btnGuardarDescuento">
                        <i class="fas fa-save me-1"></i>Guardar Descuento
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para asignar comisión a un producto del carrito -->
    <div class="modal fade" id="asignarComisionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-tag me-2"></i>Asignar Comisión</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Producto: <strong id="comisionProductoNombre"></strong></p>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small">Área</label>
                            <select class="form-select form-select-sm" id="comisionArea"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Concepto / Rol</label>
                            <select class="form-select form-select-sm" id="comisionRegla"></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Colaborador</label>
                            <select class="form-select form-select-sm" id="comisionColaborador"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">% de reparto (si el rol se divide entre varios)</label>
                            <select class="form-select form-select-sm" id="comisionPorcentajeReparto">
                                <option value="100">100% (una sola persona)</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-success btn-sm w-100" id="btnAgregarComisionLinea">
                                <i class="fas fa-plus me-1"></i>Agregar a la lista
                            </button>
                        </div>
                    </div>

                    <table class="table table-sm">
                        <thead><tr><th>Área</th><th>Concepto</th><th>Colaborador</th><th>% reparto</th><th></th></tr></thead>
                        <tbody id="comisionesListaTbody"></tbody>
                    </table>
                    <small class="text-muted">Estas comisiones se guardarán al confirmar el pago de la venta.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para editar precio unitario -->
    <div class="modal fade precio-edit-modal" id="editarPrecioModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-dollar-sign me-2"></i>Editar Precio Unitario
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Producto:</label>
                        <p id="precioProductoNombre" class="mb-2 text-primary fw-bold"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Cantidad:</label>
                        <p id="precioProductoCantidad" class="mb-2">0</p>
                    </div>

                    <div class="mb-3">
                        <label for="nuevoPrecio" class="form-label fw-bold">Nuevo Precio Unitario ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="nuevoPrecio"
                                step="0.01" min="0.01" value="0">
                        </div>
                        <small class="text-muted">Ingrese el nuevo precio unitario para este producto</small>
                    </div>

                    <div class="alert alert-info" id="precioPreviewInfo">
                        <i class="fas fa-calculator me-2"></i>
                        <strong>Vista previa:</strong><br>
                        Subtotal actual: <span id="precioSubtotalActual">$0.00</span><br>
                        Nuevo subtotal: <span id="precioNuevoSubtotal">$0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-guardar-precio" id="btnGuardarPrecio">
                        <i class="fas fa-save me-1"></i>Actualizar Precio
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Pago -->
    <div class="modal fade modal-pago" id="pagoModal" tabindex="-1" aria-labelledby="pagoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="pagoModalLabel">
                        <i class="fas fa-cash-register me-2"></i>Confirmar Pago
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-receipt me-2"></i>Resumen de Venta
                        </h6>

                        <table class="totals-table">
                            <tr>
                                <td class="label">Subtotal:</td>
                                <td class="value" id="modal-subtotal">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label">Descuento:</td>
                                <td class="value text-danger" id="modal-descuento">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                            </tr>
                            <tr>
                                <td class="label">Subtotal con Descuento:</td>
                                <td class="value" id="modal-subtotal-con-descuento">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                            </tr>
                            <tr style="display: none;">
                                <td class="label">IVA (0%):</td>
                                <td class="value">$0.00</td>
                            </tr>
                            <tr style="border-top: 2px solid #dee2e6;">
                                <td class="label"><strong>TOTAL A PAGAR:</strong></td>
                                <td class="value total-grande" id="modal-total">$<?php echo number_format($total_carrito, 2); ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-credit-card me-2"></i>Método de Pago
                        </h6>

                        <div class="payment-methods-grid">
                            <div class="payment-btn active" data-method="efectivo">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="efectivo" id="modal-efectivo" checked required>
                                    <label class="form-check-label" for="modal-efectivo">
                                        <i class="fas fa-money-bill-wave me-2"></i>Efectivo
                                    </label>
                                </div>
                            </div>

                            <div class="payment-btn" data-method="tarjeta">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="tarjeta" id="modal-tarjeta" required>
                                    <label class="form-check-label" for="modal-tarjeta">
                                        <i class="fas fa-credit-card me-2"></i>Tarjeta
                                    </label>
                                </div>
                            </div>

                            <div class="payment-btn" data-method="transferencia">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="modal_metodo_pago"
                                        value="transferencia" id="modal-transferencia" required>
                                    <label class="form-check-label" for="modal-transferencia">
                                        <i class="fas fa-university me-2"></i>Transferencia
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="section-title">
                            <i class="fas fa-comment-alt me-2"></i>Descripción (Opcional)
                        </h6>
                        <textarea class="form-control" id="modal-descripcion" rows="2" maxlength="500"
                            placeholder="Agregar nota o descripción para esta venta (opcional)..."
                            data-form-field="true"></textarea>
                        <small class="text-muted">Máximo 500 caracteres</small>
                    </div>

                    <div class="efectivo-section">
                        <h6 class="section-title">
                            <i class="fas fa-money-bill-wave me-2"></i>Pago en Efectivo
                        </h6>

                        <div class="efectivo-fields">
                            <div class="efectivo-field">
                                <span class="efectivo-label">Total a Pagar</span>
                                <input type="text" class="efectivo-input text-success fw-bold"
                                    id="modal-total-pagar"
                                    value="$<?php echo number_format($total_carrito, 2); ?>"
                                    readonly
                                    style="font-size: 13px; font-weight: bold;">
                            </div>
                            <div class="efectivo-field">
                                <span class="efectivo-label">Efectivo Recibido</span>
                                <input type="text" class="efectivo-input fw-bold"
                                    id="modal-efectivo-recibido"
                                    value=""
                                    placeholder="0.00"
                                    onfocus="this.select()"
                                    style="font-size: 13px; font-weight: bold;">
                            </div>
                        </div>
                        <div class="efectivo-fields">
                            <div class="efectivo-field" style="grid-column: span 2;">
                                <span class="efectivo-label">Cambio</span>
                                <input type="text" class="efectivo-input cambio-input fw-bold"
                                    id="modal-cambio"
                                    value="$0.00"
                                    readonly
                                    style="font-size: 13px; font-weight: bold; color: var(--primary-color);">
                            </div>
                        </div>

                        <div class="numpad">
                            <button type="button" class="numpad-btn" data-value="1">1</button>
                            <button type="button" class="numpad-btn" data-value="2">2</button>
                            <button type="button" class="numpad-btn" data-value="3">3</button>
                            <button type="button" class="numpad-btn" data-value="4">4</button>
                            <button type="button" class="numpad-btn" data-value="5">5</button>
                            <button type="button" class="numpad-btn" data-value="6">6</button>
                            <button type="button" class="numpad-btn" data-value="7">7</button>
                            <button type="button" class="numpad-btn" data-value="8">8</button>
                            <button type="button" class="numpad-btn" data-value="9">9</button>
                            <button type="button" class="numpad-btn" data-value=".">.</button>
                            <button type="button" class="numpad-btn" data-value="0">0</button>
                            <button type="button" class="numpad-btn numpad-clear" data-value="clear">
                                <i class="fas fa-backspace"></i>
                            </button>
                        </div>
                    </div>

                    <div class="qr-section" id="qrLinkSection" style="display: none;">
                        <h6 class="section-title">
                            <i class="fas fa-link me-2"></i>Link de Pago
                        </h6>
                        <div class="qr-container text-center p-4" style="background: white; border-radius: 10px; border: 2px dashed #e9ecef;">
                            <div id="qrLinkContainer" class="mb-4">
                                <h6 class="text-muted mb-2">Código QR del link de pago:</h6>
                                <div id="qrLinkContent">
                                    <img id="qrLinkImage"
                                        src=""
                                        alt="Código QR del link de pago"
                                        style="max-width: 250px; max-height: 250px; border: 1px solid #dee2e6; padding: 10px; border-radius: 10px; margin-bottom: 15px;">

                                    <div class="mt-3 p-3 bg-light rounded">
                                        <p class="fw-bold mb-2">Link de pago:</p>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <a href="" id="paymentLinkElement" target="_blank"
                                                class="text-primary text-break" style="font-size: 14px; word-break: break-all;">
                                                Cargando link...
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="copiarLinkPago(event)" title="Copiar link">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <p class="mb-1 fw-bold">Total a pagar:
                                        <span id="qrLinkTotalAmount" class="text-success">$0.00</span>
                                    </p>

                                    <p class="text-muted small mb-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Escanea el código QR o haz clic en el link para realizar el pago
                                    </p>

                                    <div class="d-flex justify-content-center gap-2 mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="refreshLinkQrBtn">
                                            <i class="fas fa-sync-alt me-1"></i>Generar nuevo link
                                        </button>
                                        
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="spei-section" id="speiSection" style="display: none;">
                        <div style="margin-top: 20px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 15px; color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                            <div style="display: flex; align-items: center; margin-bottom: 15px;">
                                <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 50%; margin-right: 15px;">
                                    <i class="fas fa-university" style="font-size: 24px;"></i>
                                </div>
                                <h5 style="margin: 0; font-weight: bold; font-size: 18px; color: white;">Pago por Transferencia SPEI</h5>
                            </div>

                            <div style="background: rgba(255,255,255,0.1); padding: 20px; border-radius: 12px; margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                    <span style="font-size: 14px; opacity: 0.9; color: rgba(255,255,255,0.9);">CLABE Interbancaria:</span>
                                    <span id="clabeDisplay" style="font-size: 22px; font-weight: bold; font-family: monospace; letter-spacing: 2px; color: white; background: rgba(0,0,0,0.2); padding: 8px 15px; border-radius: 8px;">
                                        <span class="spinner-border spinner-border-sm me-2" style="width: 1rem; height: 1rem;"></span>Generando...
                                    </span>
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn" onclick="copiarCLABE(event)" style="flex: 1; background: white; color: #667eea; font-weight: bold; border: none; padding: 12px; border-radius: 8px; transition: all 0.3s ease;">
                                    <i class="fas fa-copy me-2"></i>Copiar CLABE
                                </button>
                            </div>

                            <div style="margin-top: 15px; font-size: 12px; opacity: 0.8; text-align: center;">
                                <i class="fas fa-info-circle me-1"></i>
                                La CLABE se actualiza automáticamente. El pago será verificado en línea.
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <form method="POST" id="formPagoModal" class="w-100">
                        <input type="hidden" name="metodo_pago" id="modal-metodoPagoInput" value="efectivo">
                        <input type="hidden" name="efectivo_recibido" id="modal-efectivoRecibidoHidden" value="0">
                        <input type="hidden" name="cambio" id="modal-cambioHidden" value="0">
                        <input type="hidden" name="descuento_total" id="modal-descuentoTotal" value="<?php echo $descuento_carrito; ?>">
                        <input type="hidden" name="descripcion" id="modal-descripcionHidden" value="">
                        <button type="submit" name="procesar_pago" class="btn btn-pagar w-100" id="modal-btnPagar">
                            <i class="fas fa-check-circle me-2"></i>
                            CONFIRMAR PAGO - $<?php echo number_format($total_carrito, 2); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Cliente -->
    <div class="modal fade" id="clienteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nuevo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="clienteForm">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="formAction" value="crear">
                        <input type="hidden" name="id" id="clienteId">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre del Cliente *</label>
                                <input type="text" class="form-control" name="nombre" id="nombre" required
                                    placeholder="Nombre completo del cliente">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">RFC</label>
                                <input type="text" class="form-control" name="rfc" id="rfc"
                                    placeholder="RFC del cliente">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" id="email"
                                    placeholder="Correo electrónico">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="tel" class="form-control" name="telefono" id="telefono"
                                    placeholder="Número de teléfono">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Dirección</label>
                            <textarea class="form-control" name="direccion" id="direccion" rows="3"
                                placeholder="Dirección completa del cliente"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Guardar Cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="main-container">
        <!-- Layout Desktop -->
        <div class="desktop-layout">
            <!-- Left Panel -->
            <div class="left-panel">
                <div class="left-section">
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>Cliente
                        <?php if (isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta']): ?>
                            <span class="badge bg-success ms-2">Seleccionado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta'] ? 'cliente-seleccionado' : ''; ?>">
                        <div class="client-select-container" id="clienteContainer">
                            <select name="cliente_id" class="form-select client-select" id="clienteSelect">
                                <option value="">Cliente General</option>
                                <?php if ($clientes): ?>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="left-section scrollable-cart">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="section-title">
                            <i class="fas fa-shopping-cart me-2"></i>Detalles de Venta
                            <?php if (!empty($_SESSION['carrito'])): ?>
                                <span class="badge bg-primary ms-2"><?php echo count($_SESSION['carrito']); ?> productos</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($_SESSION['carrito'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnVaciarCarrito">
                                <i class="fas fa-trash me-1"></i>Vaciar Todo
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="cart-table-container">
                        <table class="cart-table">
                            <thead>
                                <tr>
                                    <th width="8%">IMAGEN</th>
                                    <th width="22%">PRODUCTO</th>
                                    <th width="12%">CANT.</th>
                                    <th width="12%">P. UNIT.</th>
                                    <th width="12%">DESCUENTO</th>
                                    <th width="12%">TOTAL</th>
                                    <th width="10%"></th>
                                </tr>
                            </thead>
                            <tbody id="carrito-body">
                                <?php if (empty($_SESSION['carrito'])): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                            <br>
                                            <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($_SESSION['carrito'] as $index => $item): ?>
                                        <?php
                                        $imagen_path = obtenerImagenProducto($item['id'], $conn);
                                        $imagen_src = $imagen_path ? $imagen_path : '';
                                        $descuento_producto = isset($item['descuento']) ? floatval($item['descuento']) : 0;
                                        $descuento_porcentaje = isset($item['descuento_porcentaje']) ? floatval($item['descuento_porcentaje']) : 0;
                                        $subtotal_con_descuento = isset($item['subtotal_con_descuento']) ? floatval($item['subtotal_con_descuento']) : floatval($item['subtotal']);
                                        $tiene_descuento = $descuento_producto > 0;
                                        $tiene_precio_mayoreo = isset($item['tiene_precio_mayoreo']) && $item['tiene_precio_mayoreo'] === true;
                                        $precio_base = isset($item['precio_base']) ? floatval($item['precio_base']) : floatval($item['precio']);

                                        $cantidad_raw = $item['cantidad'];
                                        $permite_decimales = $item['permite_fracciones'] == 1;

                                        if ($permite_decimales) {
                                            $cantidad_mostrar = number_format((float)$cantidad_raw, 3, '.', '');
                                            $step = '0.001';
                                            $min = '0.001';
                                            $input_class = 'cantidad-input';
                                            $input_width = '80px';
                                            $show_buttons = false;
                                            $unidad_text = isset($item['unidad_medida']) ? $item['unidad_medida'] : '';
                                        } else {
                                            $cantidad_mostrar = (int)$cantidad_raw;
                                            $step = '1';
                                            $min = '1';
                                            $input_class = 'quantity-input';
                                            $input_width = '60px';
                                            $show_buttons = true;
                                            $unidad_text = '';
                                        }
                                        ?>
                                        <tr data-index="<?php echo $index; ?>">
                                            <td width="8%">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                        class="product-image-cart"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="product-image-placeholder-cart" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder-cart">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td width="22%">
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['nombre']); ?></div>
                                                <small class="text-muted">Código: <?php echo htmlspecialchars($item['codigo']); ?></small>
                                                <?php if (!empty($item['costo'])): ?>
                                                    <br><small class="text-muted" title="Costo del producto, solo informativo, no afecta el precio de venta">
                                                        <i class="fas fa-tag me-1"></i>Costo: $<?php echo number_format((float)$item['costo'], 2); ?>
                                                    </small>
                                                <?php endif; ?>
                                                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                    <?php if ($item['permite_fracciones'] == 1): ?>
                                                        <div>
                                                            <span class="badge tipo-venta-badge tipo-peso">
                                                                <?php echo ucfirst($item['unidad_medida']); ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($tiene_precio_mayoreo): ?>
                                                        <div>
                                                            <span class="badge mayoreo-badge">
                                                                <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio" 
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                            data-cantidad="<?php echo $cantidad_raw; ?>"
                                                            data-precio-actual="<?php echo floatval($item['precio']); ?>">
                                                        <i class="fas fa-edit me-1"></i>Editar Precio
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                        <i class="fas fa-user-tag me-1"></i>Comisión
                                                        <?php if (!empty($item['comisiones'])): ?>
                                                            <span class="badge bg-success ms-1"><?php echo count($item['comisiones']); ?></span>
                                                        <?php endif; ?>
                                                    </button>
                                                </div>
                                            </td>
                                            <td width="12%">
                                                <div class="quantity-control">
                                                    <?php if ($show_buttons): ?>
                                                        <button type="button" class="quantity-btn decrease" data-index="<?php echo $index; ?>">-</button>
                                                    <?php endif; ?>
                                                    <input type="number"
                                                        name="cantidad"
                                                        value="<?php echo $cantidad_mostrar; ?>"
                                                        min="<?php echo $min; ?>"
                                                        step="<?php echo $step; ?>"
                                                        class="<?php echo $input_class; ?>"
                                                        data-index="<?php echo $index; ?>"
                                                        style="width: <?php echo $input_width; ?>;">
                                                    <?php if ($show_buttons): ?>
                                                        <button type="button" class="quantity-btn increase" data-index="<?php echo $index; ?>">+</button>
                                                    <?php else: ?>
                                                        <span class="unidad-medida ms-1"><?php echo $item['unidad_medida']; ?></span>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar" data-index="<?php echo $index; ?>">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="fw-bold text-success precio-unitario" data-index="<?php echo $index; ?>">
                                                <?php if ($tiene_precio_mayoreo): ?>
                                                    <div class="d-flex flex-column">
                                                        <span class="text-muted small" style="text-decoration: line-through;">$<?php echo number_format($precio_base, 2); ?></span>
                                                        <span>$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    $<?php echo number_format(floatval($item['precio']), 2); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td width="12%">
                                                <div class="descuento-control">
                                                    <div class="descuento-info d-flex align-items-center gap-2">
                                                        <?php if ($tiene_descuento): ?>
                                                            <span class="badge bg-danger">-<?php echo number_format($descuento_porcentaje, 0); ?>%</span>
                                                            <span class="small text-muted">-$<?php echo number_format($descuento_producto, 2); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">0%</span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento"
                                                            data-index="<?php echo $index; ?>"
                                                            data-producto-id="<?php echo $item['id']; ?>"
                                                            data-descuento-actual="<?php echo $descuento_porcentaje; ?>"
                                                            data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="subtotal-descuento" data-index="<?php echo $index; ?>">
                                                <?php if ($tiene_descuento): ?>
                                                    <span class="subtotal-original">$<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                    <span class="subtotal-final">$<?php echo number_format($subtotal_con_descuento, 2); ?></span>
                                                <?php else: ?>
                                                    <span class="fw-bold text-primary">$<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td width="10%">
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-index="<?php echo $index; ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="totals-section-fixed">
                    <div class="section-title">
                        <i class="fas fa-receipt me-2"></i>Resumen y Pago
                    </div>

                    <div class="totals-payment-container">
                        <div class="totals-table-container">
                            <table class="totals-table">
                                <tr>
                                    <td class="label">Total:</td>
                                    <td class="value" id="subtotal-display">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Descuento:</td>
                                    <td class="value text-danger" id="descuento-display">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Total con Descuento:</td>
                                    <td class="value" id="subtotal-con-descuento-display">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                                </tr>
                                <tr style="display: none;">
                                    <td class="label">IVA (0%):</td>
                                    <td class="value">$0.00</span></td>
                                </tr>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <td class="label"><strong>TOTAL:</strong></td>
                                    <td class="value total-grande" id="total-display">$<?php echo number_format($total_carrito, 2); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="payment-button-container">
                            <button type="button" class="btn btn-pagar-integrado" id="btnAbrirModalPago"
                                <?php echo empty($_SESSION['carrito']) ? 'disabled' : ''; ?>>
                                <div class="pay-text">
                                    <i class="fas fa-cash-register me-1"></i>PAGAR
                                </div>
                                <div class="total-amount" id="total-pagar-display">
                                    $<?php echo number_format($total_carrito, 2); ?>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Panel -->
            <div class="right-panel">
                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-search me-2"></i>Buscar Producto
                        <?php if (!empty($busqueda_nombre)): ?>
                            <span class="badge bg-primary ms-2">Búsqueda activa</span>
                        <?php endif; ?>
                        <span class="badge bg-<?php echo $empresa_plan === 'premium' ? 'warning' : 'info'; ?> ms-2">
                            <?php echo strtoupper($empresa_plan); ?>
                        </span>
                    </div>
                    <div class="search-section <?php echo !empty($busqueda_nombre) ? 'search-active' : ''; ?>" id="searchSection">
                        <div class="search-container">
                            <input type="text"
                                name="busqueda_nombre"
                                class="form-control search-input"
                                placeholder="🔍 Escriba el nombre del producto..."
                                value="<?php echo htmlspecialchars($busqueda_nombre); ?>"
                                id="searchInput"
                                autocomplete="off">
                            <button type="button" class="search-btn" id="btnClearSearch" title="Limpiar búsqueda" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted search-results-count" id="searchResultsCount">
                                <?php if (!empty($productos)): ?>
                                    Mostrando <?php echo count($productos); ?> productos
                                <?php else: ?>
                                    Escriba para buscar productos
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-tags me-2"></i>Filtrar por Categoría
                        <?php if ($categoria_seleccionada): ?>
                            <span class="badge bg-primary ms-2">Filtrado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo $categoria_seleccionada ? 'categoria-filtrada' : ''; ?>">
                        <form method="GET" class="categoria-select-container" id="categoriaForm">
                            <select name="categoria_id" class="form-select categoria-select" id="categoriaSelect">
                                <option value="">Todas las Categorías</option>
                                <?php
                                // Antes: esta misma consulta se repetía aquí (y otra vez en la
                                // vista móvil) — ya se cargó una vez arriba como $categorias_con_count.
                                foreach ($categorias_con_count as $categoria):
                                    $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?> productos)
                                            </option>
                                <?php
                                endforeach;
                                ?>
                            </select>
                            <?php if ($categoria_seleccionada): ?>
                                <a href="caja.php<?php echo !empty($busqueda_nombre) ? '?busqueda_nombre=' . urlencode($busqueda_nombre) : ''; ?>"
                                    class="btn btn-outline-danger"
                                    title="Quitar filtro">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="right-section scrollable">
                    <div class="section-title">
                        <i class="fas fa-boxes me-2"></i>Productos Disponibles en Sucursal
                        <?php if (!empty($productos)): ?>
                            <span class="badge bg-primary ms-2" id="productCount"><?php echo count($productos); ?> productos</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2" id="productCount">0 productos</span>
                        <?php endif; ?>
                        <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                            <small class="text-muted ms-2" id="filterInfo">
                                (
                                <?php
                                $filtros = [];
                                if ($categoria_seleccionada) {
                                    if ($categorias_con_count) {
                                        foreach ($categorias_con_count as $cat) {
                                            if ($cat['id'] == $categoria_seleccionada) {
                                                $filtros[] = "Categoría: " . htmlspecialchars($cat['nombre']);
                                                break;
                                            }
                                        }
                                    }
                                }
                                if (!empty($busqueda_nombre)) {
                                    $filtros[] = "Búsqueda: \"" . htmlspecialchars($busqueda_nombre) . "\"";
                                }
                                echo implode(', ', $filtros);
                                ?>
                                )
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="product-grid-container">
                        <div class="product-grid" id="productGrid">
                            <?php if (empty($productos)): ?>
                                <div class="col-12 text-center py-4" id="emptyProductsMessage">
                                    <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">
                                        <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                                            No se encontraron productos con stock que coincidan con los filtros
                                        <?php else: ?>
                                            No se encontraron productos con stock en esta sucursal
                                        <?php endif; ?>
                                    </p>
                                    <small class="text-muted">
                                        <?php if ($categoria_seleccionada): ?>
                                            Categoría ID: <?php echo $categoria_seleccionada; ?><br>
                                        <?php endif; ?>
                                        <?php if (!empty($busqueda_nombre)): ?>
                                            Búsqueda: "<?php echo htmlspecialchars($busqueda_nombre); ?>"<br>
                                        <?php endif; ?>
                                        Sucursal ID: <?php echo $_SESSION['sucursal_id'] ?? 'No definido'; ?>
                                    </small>
                                </div>
                            <?php else: ?>
                                <?php foreach ($productos as $producto): ?>
                                    <?php
                                    $imagen_path = obtenerImagenProducto($producto['id'], $conn);
                                    $imagen_src = $imagen_path ? $imagen_path : '';
                                    $tiene_descuento = $producto['descuento'] > 0;
                                    $precio_con_descuento = $producto['precio_sin_iva'] - ($producto['precio_sin_iva'] * $producto['descuento'] / 100);
                                    
                                    // Antes: una consulta a producto_precios_mayoreo POR PRODUCTO
                                    // aquí en el bucle (N+1) — ya se resolvió por lote arriba en
                                    // $productosConMayoreo.
                                    $tiene_mayoreo = isset($productosConMayoreo[$producto['id']]);
                                    ?>
                                    <div class="product-btn"
                                        onclick="agregarProducto(
                                            <?php echo $producto['id']; ?>, 
                                            '<?php echo $producto['permite_fracciones']; ?>', 
                                            '<?php echo addslashes($producto['unidad_medida']); ?>', 
                                            this)">
                                        <div class="product-image-container">
                                            <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                    alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                    class="product-image"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="product-image-placeholder" style="display: none;">
                                                    <i class="fas fa-box"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="product-image-placeholder">
                                                    <i class="fas fa-box"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                        <div class="product-price-descuento">
                                            <?php if ($tiene_descuento): ?>
                                                <span class="precio-original">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                <span class="precio-con-descuento">$<?php echo number_format($precio_con_descuento, 2); ?></span>
                                                <span class="descuento-badge">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                            <?php else: ?>
                                                <span class="product-price">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($tiene_mayoreo): ?>
                                            <div class="mt-1">
                                                <span class="badge mayoreo-badge">
                                                    <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($producto['permite_fracciones'] == 1): ?>
                                            <div class="unidad-medida">
                                                <span class="badge tipo-venta-badge tipo-peso">
                                                    <?php echo ucfirst($producto['unidad_medida']); ?>
                                                </span>
                                                por <?php echo $producto['unidad_medida']; ?>
                                            </div>
                                        <?php endif; ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                                        </small>
                                        <small class="text-muted d-block mt-1">
                                            <i class="fas fa-store me-1"></i>Stock Sucursal:
                                            <?php
                                            $stock_sucursal = (float)($producto['stock_sucursal'] ?? 0);
                                            $permite_fracciones = (int)($producto['permite_fracciones'] ?? 0);
                                            $unidad_medida = strtolower(trim($producto['unidad_medida'] ?? ''));

                                            $unidades_decimales = [
                                                'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                                                'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                                                'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                                            ];

                                            $mostrar_decimales = ($permite_fracciones == 1) || in_array($unidad_medida, $unidades_decimales);

                                            if ($mostrar_decimales) {
                                                $stock_display = number_format($stock_sucursal, 3, '.', '');
                                            } else {
                                                $stock_display = (int)$stock_sucursal;
                                            }

                                            $stock_class = ($stock_sucursal <= 5 && $stock_sucursal > 0) ? 'stock-bajo' : '';
                                            ?>
                                            <span class="<?php echo $stock_class; ?>">
                                                <?php echo $stock_display; ?>
                                            </span>
                                            <?php if ($mostrar_decimales && !empty($producto['unidad_medida'])): ?>
                                                <span class="unidad-medida" style="font-size: 10px;"><?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                                            <?php endif; ?>

                                            <?php if ($stock_sucursal <= 0): ?>
                                                <span class="badge bg-danger ms-1">Sin Stock</span>
                                            <?php elseif ($stock_sucursal <= 5): ?>
                                                <span class="badge bg-warning ms-1">Stock Bajo</span>
                                            <?php endif; ?>
                                        </small>
                                        <small class="text-muted d-block">
                                            Código: <?php echo htmlspecialchars($producto['codigo']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Layout Móvil -->
        <div class="mobile-layout">
            <div class="mobile-tabs">
                <button class="mobile-tab active" data-tab="productos">
                    <i class="fas fa-boxes me-1"></i>Productos
                </button>
                <button class="mobile-tab" data-tab="carrito">
                    <i class="fas fa-shopping-cart me-1"></i>Carrito
                    <?php if (!empty($_SESSION['carrito'])): ?>
                        <span class="badge bg-danger ms-1"><?php echo count($_SESSION['carrito']); ?></span>
                    <?php endif; ?>
                </button>
                <button class="mobile-tab" data-tab="pago">
                    <i class="fas fa-credit-card me-1"></i>Pago
                </button>
            </div>

            <div class="mobile-content active" id="mobile-productos">
                <div class="left-section compact">
                    <div class="section-title">
                        <i class="fas fa-search me-2"></i>Buscar Producto
                        <?php if (!empty($busqueda_nombre)): ?>
                            <span class="badge bg-primary ms-2">Búsqueda activa</span>
                        <?php endif; ?>
                        <span class="badge bg-success ms-2" id="mobileRealTimeStatus">Tiempo Real</span>
                    </div>
                    <div class="search-section <?php echo !empty($busqueda_nombre) ? 'search-active' : ''; ?>" id="mobileSearchSection">
                        <div class="search-container">
                            <input type="text"
                                name="busqueda_nombre"
                                class="form-control search-input"
                                placeholder="🔍 Escriba el nombre del producto..."
                                value="<?php echo htmlspecialchars($busqueda_nombre); ?>"
                                id="mobileSearchInput"
                                autocomplete="off">
                            <button type="button" class="search-btn" id="mobileBtnClearSearch" title="Limpiar búsqueda" style="display: none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted search-results-count" id="mobileSearchResultsCount">
                                <?php if (!empty($productos)): ?>
                                    Mostrando <?php echo count($productos); ?> productos
                                <?php else: ?>
                                    Escriba para buscar productos
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <div class="left-section compact">
                    <div class="section-title">
                        <i class="fas fa-tags me-2"></i>Filtrar por Categoría
                        <?php if ($categoria_seleccionada): ?>
                            <span class="badge bg-primary ms-2">Filtrado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo $categoria_seleccionada ? 'categoria-filtrada' : ''; ?>">
                        <form method="GET" class="categoria-select-container" id="mobileCategoriaForm">
                            <select name="categoria_id" class="form-select categoria-select" id="mobileCategoriaSelect">
                                <option value="">Todas las Categorías</option>
                                <?php
                                // Misma lista ya cargada arriba como $categorias_con_count — antes
                                // esta consulta se repetía aquí para la vista móvil.
                                foreach ($categorias_con_count as $categoria):
                                    $producto_count = $categoria['producto_count'];
                                ?>
                                            <option value="<?php echo $categoria['id']; ?>"
                                                <?php echo $categoria_seleccionada == $categoria['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                (<?php echo $producto_count; ?>)
                                            </option>
                                <?php
                                endforeach;
                                ?>
                            </select>
                        </form>
                    </div>
                </div>

                <div class="left-section scrollable">
                    <div class="scrollable-content">
                        <div class="section-title mb-3">
                            <i class="fas fa-boxes me-2"></i>Productos Disponibles en Sucursal
                            <?php if (!empty($productos)): ?>
                                <span class="badge bg-primary ms-2" id="mobileProductCount"><?php echo count($productos); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-2" id="mobileProductCount">0</span>
                            <?php endif; ?>
                        </div>

                        <div class="product-grid-container">
                            <div class="product-grid" id="mobileProductGrid">
                                <?php if (empty($productos)): ?>
                                    <div class="col-12 text-center py-4" id="mobileEmptyProductsMessage">
                                        <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                        <p class="text-muted">
                                            <?php if ($categoria_seleccionada || !empty($busqueda_nombre)): ?>
                                                No se encontraron productos con stock que coincidan con los filtros
                                            <?php else: ?>
                                                No se encontraron productos con stock en esta sucursal
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($productos as $producto): ?>
                                        <?php
                                        $imagen_path = obtenerImagenProducto($producto['id'], $conn);
                                        $imagen_src = $imagen_path ? $imagen_path : '';
                                        $tiene_descuento = $producto['descuento'] > 0;
                                        $precio_con_descuento = $producto['precio_sin_iva'] - ($producto['precio_sin_iva'] * $producto['descuento'] / 100);
                                        
                                        // Mismo lookup por lote que la vista de escritorio.
                                        $tiene_mayoreo_mobile = isset($productosConMayoreo[$producto['id']]);
                                        ?>
                                        <div class="product-btn"
                                            onclick="agregarProducto(
                                                <?php echo $producto['id']; ?>, 
                                                '<?php echo $producto['permite_fracciones']; ?>', 
                                                '<?php echo addslashes($producto['unidad_medida']); ?>', 
                                                this)">
                                            <div class="product-image-container">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                        class="product-image"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="product-image-placeholder" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                            <div class="product-price-descuento">
                                                <?php if ($tiene_descuento): ?>
                                                    <span class="precio-original">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                    <span class="precio-con-descuento">$<?php echo number_format($precio_con_descuento, 2); ?></span>
                                                    <span class="descuento-badge">-<?php echo number_format($producto['descuento'], 0); ?>%</span>
                                                <?php else: ?>
                                                    <span class="product-price">$<?php echo number_format($producto['precio_sin_iva'], 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($tiene_mayoreo_mobile): ?>
                                                <div class="mt-1">
                                                    <span class="badge mayoreo-badge">
                                                        <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($producto['permite_fracciones'] == 1): ?>
                                                <div class="unidad-medida">
                                                    <span class="badge tipo-venta-badge tipo-peso">
                                                        <?php echo ucfirst($producto['unidad_medida']); ?>
                                                    </span>
                                                    por <?php echo $producto['unidad_medida']; ?>
                                                </div>
                                            <?php endif; ?>
                                            <small class="text-muted d-block mt-1">
                                                <i class="fas fa-store me-1"></i>Stock:
                                                <?php
                                                $stock_sucursal = (float)($producto['stock_sucursal'] ?? 0);
                                                $permite_fracciones = (int)($producto['permite_fracciones'] ?? 0);
                                                $unidad_medida = strtolower(trim($producto['unidad_medida'] ?? ''));

                                                $unidades_decimales = [
                                                    'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                                                    'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                                                    'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                                                ];

                                                $mostrar_decimales = ($permite_fracciones == 1) || in_array($unidad_medida, $unidades_decimales);

                                                if ($mostrar_decimales) {
                                                    $stock_display = number_format($stock_sucursal, 3, '.', '');
                                                } else {
                                                    $stock_display = (int)$stock_sucursal;
                                                }

                                                $stock_class = ($stock_sucursal <= 5 && $stock_sucursal > 0) ? 'stock-bajo' : '';
                                                ?>
                                                <span class="<?php echo $stock_class; ?>">
                                                    <?php echo $stock_display; ?>
                                                </span>
                                                <?php if ($mostrar_decimales && !empty($producto['unidad_medida'])): ?>
                                                    <span class="unidad-medida" style="font-size: 10px;"><?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                                                <?php endif; ?>

                                                <?php if ($stock_sucursal <= 0): ?>
                                                    <span class="badge bg-danger ms-1">Sin Stock</span>
                                                <?php elseif ($stock_sucursal <= 5): ?>
                                                    <span class="badge bg-warning ms-1">Stock Bajo</span>
                                                <?php endif; ?>
                                            </small>
                                            <small class="text-muted d-block">
                                                Código: <?php echo htmlspecialchars($producto['codigo']); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mobile-content" id="mobile-carrito">
                <div class="left-section scrollable">
                    <div class="d-flex justify-content-between align-items-center mb-3" style="flex-shrink: 0; padding: 15px 15px 0 15px;">
                        <div class="section-title">
                            <i class="fas fa-shopping-cart me-2"></i>Carrito de Compra
                            <?php if (!empty($_SESSION['carrito'])): ?>
                                <span class="badge bg-primary ms-2"><?php echo count($_SESSION['carrito']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($_SESSION['carrito'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="mobileBtnVaciarCarrito">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="scrollable-content" id="mobile-carrito-container" style="padding: 0 15px 15px 15px;">
                        <?php if (empty($_SESSION['carrito'])): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Carrito vacío</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($_SESSION['carrito'] as $index => $item): ?>
                                <?php
                                $imagen_path = obtenerImagenProducto($item['id'], $conn);
                                $imagen_src = $imagen_path ? $imagen_path : '';
                                $descuento_producto = isset($item['descuento']) ? floatval($item['descuento']) : 0;
                                $descuento_porcentaje = isset($item['descuento_porcentaje']) ? floatval($item['descuento_porcentaje']) : 0;
                                $subtotal_con_descuento = isset($item['subtotal_con_descuento']) ? floatval($item['subtotal_con_descuento']) : floatval($item['subtotal']);
                                $tiene_descuento = $descuento_producto > 0;
                                $tiene_precio_mayoreo = isset($item['tiene_precio_mayoreo']) && $item['tiene_precio_mayoreo'] === true;
                                $precio_base = isset($item['precio_base']) ? floatval($item['precio_base']) : floatval($item['precio']);
                                
                                $cantidad_raw = $item['cantidad'];
                                $permite_decimales = $item['permite_fracciones'] == 1;

                                if ($permite_decimales) {
                                    $cantidad_mostrar = number_format((float)$cantidad_raw, 3, '.', '');
                                    $step = '0.001';
                                    $min = '0.001';
                                    $input_class = 'cantidad-input';
                                    $input_width = '80px';
                                    $show_buttons = false;
                                    $unidad_text = isset($item['unidad_medida']) ? $item['unidad_medida'] : '';
                                } else {
                                    $cantidad_mostrar = (int)$cantidad_raw;
                                    $step = '1';
                                    $min = '1';
                                    $input_class = 'quantity-input';
                                    $input_width = '60px';
                                    $show_buttons = true;
                                    $unidad_text = '';
                                }
                                ?>
                                <div class="card mb-3" data-index="<?php echo $index; ?>">
                                    <div class="card-body">
                                        <div class="row align-items-start">
                                            <div class="col-3">
                                                <?php if ($imagen_src && file_exists($imagen_src)): ?>
                                                    <img src="<?php echo htmlspecialchars($imagen_src); ?>"
                                                        alt="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                        class="product-image-cart"
                                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                        onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                                                    <div class="product-image-placeholder-cart" style="display: none;">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-image-placeholder-cart">
                                                        <i class="fas fa-box"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-9">
                                                <div class="row align-items-center">
                                                    <div class="col-12">
                                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($item['nombre']); ?></h6>
                                                        <p class="card-text text-muted small mb-1">Código: <?php echo htmlspecialchars($item['codigo']); ?></p>
                                                        <?php if (!empty($item['costo'])): ?>
                                                            <p class="card-text text-muted small mb-1" title="Costo del producto, solo informativo, no afecta el precio de venta">
                                                                <i class="fas fa-tag me-1"></i>Costo: $<?php echo number_format((float)$item['costo'], 2); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                            <?php if ($item['permite_fracciones'] == 1): ?>
                                                                <span class="badge tipo-venta-badge tipo-peso">
                                                                    <?php echo ucfirst($item['unidad_medida']); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if ($tiene_precio_mayoreo): ?>
                                                                <span class="badge mayoreo-badge">
                                                                    <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                                                </span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio-mobile" 
                                                                    data-index="<?php echo $index; ?>"
                                                                    data-producto-id="<?php echo $item['id']; ?>"
                                                                    data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>"
                                                                    data-cantidad="<?php echo $cantidad_raw; ?>"
                                                                    data-precio-actual="<?php echo floatval($item['precio']); ?>">
                                                                <i class="fas fa-edit me-1"></i>Editar Precio
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                                                    data-index="<?php echo $index; ?>"
                                                                    data-producto-id="<?php echo $item['id']; ?>"
                                                                    data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                                <i class="fas fa-user-tag me-1"></i>Comisión
                                                                <?php if (!empty($item['comisiones'])): ?>
                                                                    <span class="badge bg-success ms-1"><?php echo count($item['comisiones']); ?></span>
                                                                <?php endif; ?>
                                                            </button>
                                                        </div>
                                                        
                                                        <div class="descuento-info mt-1">
                                                            <?php if ($tiene_descuento): ?>
                                                                <span class="badge bg-danger">-<?php echo number_format($descuento_porcentaje, 0); ?>%</span>
                                                                <span class="small text-muted">-$<?php echo number_format($descuento_producto, 2); ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">0%</span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento-mobile ms-1"
                                                                data-index="<?php echo $index; ?>"
                                                                data-producto-id="<?php echo $item['id']; ?>"
                                                                data-descuento-actual="<?php echo $descuento_porcentaje; ?>"
                                                                data-producto-nombre="<?php echo htmlspecialchars($item['nombre']); ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        </div>

                                                        <p class="card-text mb-0 mt-2">
                                                            <?php if ($tiene_precio_mayoreo): ?>
                                                                <div class="d-flex flex-column">
                                                                    <span class="text-muted small" style="text-decoration: line-through;">$<?php echo number_format($precio_base, 2); ?></span>
                                                                    <span class="text-success fw-bold">$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-success fw-bold">$<?php echo number_format(floatval($item['precio']), 2); ?></span>
                                                            <?php endif; ?>
                                                            <span class="text-muted"> x </span>
                                                        </p>
                                                        <div class="quantity-control d-inline-flex align-items-center mt-1">
                                                            <?php if ($show_buttons): ?>
                                                                <button type="button" class="quantity-btn decrease" data-index="<?php echo $index; ?>">-</button>
                                                            <?php endif; ?>
                                                            <input type="number"
                                                                name="cantidad"
                                                                value="<?php echo $cantidad_mostrar; ?>"
                                                                min="<?php echo $min; ?>"
                                                                step="<?php echo $step; ?>"
                                                                class="<?php echo $input_class; ?>"
                                                                data-index="<?php echo $index; ?>"
                                                                style="width: <?php echo $input_width; ?>; font-size: 12px;">
                                                            <?php if ($show_buttons): ?>
                                                                <button type="button" class="quantity-btn increase" data-index="<?php echo $index; ?>">+</button>
                                                            <?php else: ?>
                                                                <span class="unidad-medida ms-1" style="font-size: 11px;"><?php echo $item['unidad_medida']; ?></span>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar-mobile" data-index="<?php echo $index; ?>">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </div>
                                                        <p class="card-text mt-2">
                                                            <?php if ($tiene_descuento): ?>
                                                                <span class="text-muted small" style="text-decoration: line-through;">Total: $<?php echo number_format(floatval($item['subtotal']), 2); ?></span><br>
                                                                <span class="fw-bold text-primary">Total con descuento: $<?php echo number_format($subtotal_con_descuento, 2); ?></span>
                                                            <?php else: ?>
                                                                <span class="fw-bold text-primary">Total: $<?php echo number_format(floatval($item['subtotal']), 2); ?></span>
                                                            <?php endif; ?>
                                                        </p>
                                                    </div>
                                                    <div class="col-12 text-end mt-2">
                                                        <button type="button" class="btn btn-outline-danger btn-sm btn-eliminar" data-index="<?php echo $index; ?>">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mobile-content" id="mobile-pago">
                <div class="right-section compact">
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>Cliente
                        <?php if (isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta']): ?>
                            <span class="badge bg-success ms-2">Seleccionado</span>
                        <?php endif; ?>
                    </div>
                    <div class="client-section <?php echo isset($_SESSION['cliente_venta']) && $_SESSION['cliente_venta'] ? 'cliente-seleccionado' : ''; ?>">
                        <div class="client-select-container" id="mobileClienteContainer">
                            <select name="cliente_id" class="form-select client-select" id="mobileClienteSelect">
                                <option value="">Cliente General</option>
                                <?php if ($clientes): ?>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?php echo $cliente['id']; ?>"
                                            <?php echo ($_SESSION['cliente_venta'] ?? '') == $cliente['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clienteModal">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="right-section compact scrollable">
                    <div class="section-title">
                        <i class="fas fa-receipt me-2"></i>Resumen y Pago
                    </div>

                    <div class="totals-payment-container">
                        <div class="totals-table-container">
                            <table class="totals-table">
                                <tr>
                                    <td class="label">Total:</td>
                                    <td class="value" id="mobile-subtotal-display">$<?php echo number_format($subtotal_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Descuento:</td>
                                    <td class="value text-danger" id="mobile-descuento-display">-$<?php echo number_format($descuento_carrito, 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Total con Descuento:</td>
                                    <td class="value" id="mobile-subtotal-con-descuento-display">$<?php echo number_format($subtotal_con_descuento_carrito, 2); ?></td>
                                </tr>
                                <tr style="display: none;">
                                    <td class="label">IVA (0%):</td>
                                    <td class="value">$0.00</span></td>
                                </tr>
                                <tr style="border-top: 2px solid #dee2e6;">
                                    <td class="label"><strong>TOTAL:</strong></td>
                                    <td class="value total-grande" id="mobile-total-display">$<?php echo number_format($total_carrito, 2); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="payment-button-container">
                            <button type="button" class="btn btn-pagar-integrado" id="mobile-btnAbrirModalPago"
                                <?php echo empty($_SESSION['carrito']) ? 'disabled' : ''; ?>>
                                <div class="pay-text">
                                    <i class="fas fa-cash-register me-1"></i>PAGAR
                                </div>
                                <div class="total-amount" id="mobile-total-pagar-display">
                                    $<?php echo number_format($total_carrito, 2); ?>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    window.CajaConfig = {
        carrito: <?php echo json_encode($_SESSION['carrito'] ?? []); ?>,
        sucursalId: <?php echo $_SESSION['sucursal_id'] ?? 0; ?>,
        clienteActual: '<?php echo $_SESSION['cliente_venta'] ?? ''; ?>',
        busquedaNombre: '<?php echo addslashes($busqueda_nombre ?? ''); ?>',
        
        // Datos de la venta realizada
        ventaRealizada: <?php echo isset($_SESSION['venta_realizada']) ? json_encode($_SESSION['venta_realizada']) : 'null'; ?>,
        ventaId: <?php echo isset($_SESSION['venta_realizada']['venta_id']) ? $_SESSION['venta_realizada']['venta_id'] : '0'; ?>,
        
        // Totales iniciales
        totalInicial: <?php echo $total_carrito ?? 0; ?>,
        subtotalInicial: <?php echo $subtotal_carrito ?? 0; ?>,
        descuentoInicial: <?php echo $descuento_carrito ?? 0; ?>,
        subtotalConDescuentoInicial: <?php echo $subtotal_con_descuento_carrito ?? 0; ?>,
        carritoCountInicial: <?php echo count($_SESSION['carrito'] ?? []); ?>
    };
</script>

<script src="js/cajas.js"></script>
</body>  

</html>