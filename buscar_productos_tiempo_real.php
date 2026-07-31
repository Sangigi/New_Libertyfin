<?php
// buscar_productos_tiempo_real.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

header('Content-Type: application/json');
Auth::requireLogin();

$dbname = $_SESSION['empresa_db'] ?? '';
if (empty($dbname)) {
    echo json_encode(['success' => false, 'message' => 'Base de datos no especificada']);
    exit();
}

$busqueda = trim($_POST['busqueda'] ?? '');
$categoriaId = !empty($_POST['categoria_id']) ? (int) $_POST['categoria_id'] : null;
$sucursalId = (int) ($_POST['sucursal_id'] ?? $_SESSION['sucursal_id'] ?? 0);

try {
    $productoRepository = new ProductoRepository(Database::pdo($dbname));
    // Antes: consulta de imagen POR PRODUCTO dentro del foreach (N+1) —
    // buscarTiempoReal() ya resuelve las imágenes por lote internamente.
    $productos = $productoRepository->buscarTiempoReal($sucursalId, $busqueda, $categoriaId);

    echo json_encode([
        'success'   => true,
        'productos' => $productos,
        'count'     => count($productos),
    ]);
} catch (Exception $e) {
    Logger::error('productos', 'Error en buscar_productos_tiempo_real', ['error' => $e->getMessage()]);
    echo json_encode([
        'success'   => false,
        'message'   => 'Error del servidor: ' . $e->getMessage(),
        'productos' => [],
    ]);
}
