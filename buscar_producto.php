<?php
// buscar_producto.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

Auth::requireLogin();

header('Content-Type: application/json');

$codigo_barras = $_POST['codigo_barras'] ?? '';
$sucursal_id = (int) ($_POST['sucursal_id'] ?? $_SESSION['sucursal_id'] ?? 0);

if (empty($codigo_barras)) {
    echo json_encode(['success' => false, 'message' => 'Código de barras vacío']);
    exit();
}

try {
    $productos = new ProductoRepository(Database::pdo($_SESSION['empresa_db']));
    $producto = $productos->buscarPorCodigo($codigo_barras, $sucursal_id);

    if ($producto) {
        echo json_encode(['success' => true, 'producto' => $producto, 'message' => 'Producto encontrado']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Producto no encontrado o sin stock en esta sucursal']);
    }
} catch (Exception $e) {
    Logger::error('productos', 'Error en buscar_producto', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
