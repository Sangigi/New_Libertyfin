<?php
// transferir_stock.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\MovimientoInventarioRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\SucursalRepository;
use App\Services\ProductoService;

header('Content-Type: application/json');
Auth::requireLogin();

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$producto_id = isset($input['producto_id']) ? intval($input['producto_id']) : 0;
$sucursal_origen_id = isset($input['sucursal_origen_id']) ? intval($input['sucursal_origen_id']) : 0;
$sucursal_destino_id = isset($input['sucursal_destino_id']) ? intval($input['sucursal_destino_id']) : 0;
$cantidad = isset($input['cantidad']) ? floatval($input['cantidad']) : 0;
$observaciones = isset($input['observaciones']) ? trim($input['observaciones']) : '';
$usuario_id = $_SESSION['usuario_id'] ?? 1;

if ($producto_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de producto inválido']);
    exit();
}
if ($sucursal_origen_id <= 0 || $sucursal_destino_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'IDs de sucursal inválidos']);
    exit();
}

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);
    $productoService = new ProductoService(
        $pdoEmpresa,
        new ProductoRepository($pdoEmpresa),
        new MovimientoInventarioRepository($pdoEmpresa),
        new SucursalRepository($pdoEmpresa)
    );

    $resultado = $productoService->transferirStock(
        $producto_id,
        $sucursal_origen_id,
        $sucursal_destino_id,
        $cantidad,
        $observaciones,
        (int) $usuario_id
    );

    $producto = $resultado['producto'];
    $origen = $resultado['sucursal_origen'];
    $destino = $resultado['sucursal_destino'];

    echo json_encode([
        'success' => true,
        'message' => "✅ Transferencia exitosa: {$cantidad} {$producto['unidad_medida']} de '{$producto['nombre']}' de {$origen['nombre']} a {$destino['nombre']}",
        'data' => [
            'producto'         => $producto,
            'cantidad'         => $cantidad,
            'sucursal_origen'  => $origen,
            'sucursal_destino' => $destino,
            'stocks_actualizados' => [
                $sucursal_origen_id  => $origen['stock_nuevo'],
                $sucursal_destino_id => $destino['stock_nuevo'],
            ],
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
