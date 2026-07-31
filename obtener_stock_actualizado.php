<?php
// obtener_stock_actualizado.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

Auth::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$sucursal_id = intval($_POST['sucursal_id'] ?? $_SESSION['sucursal_id']);

try {
    $productos = new ProductoRepository(Database::pdo($_SESSION['empresa_db']));

    echo json_encode([
        'success'           => true,
        'stock_actualizado' => $productos->stockPorSucursal($sucursal_id),
    ]);
} catch (Exception $e) {
    Logger::error('productos', 'Error en obtener_stock_actualizado', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Error del sistema. Contacte al administrador.']);
}
