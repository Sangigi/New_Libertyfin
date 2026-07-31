<?php
// eliminar_producto.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\MovimientoInventarioRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\SucursalRepository;
use App\Services\ProductoService;

header('Content-Type: application/json');
Auth::requireRole('admin');

$producto_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$confirmacion = isset($_POST['confirmacion']) && ($_POST['confirmacion'] === 'true' || $_POST['confirmacion'] === true);

if ($producto_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de producto inválido']);
    exit();
}

if (!$confirmacion) {
    echo json_encode(['success' => false, 'message' => 'Se requiere confirmación para eliminar el producto']);
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
    $resultado = $productoService->eliminar($producto_id);

    $sufijoArchivos = $resultado['archivos_eliminados'] > 0
        ? " (incluyendo {$resultado['archivos_eliminados']} imagen(es))"
        : '';

    echo json_encode([
        'success' => true,
        'message' => "Producto \"{$resultado['nombre']}\" eliminado exitosamente{$sufijoArchivos}",
    ]);
} catch (RuntimeException $e) {
    // Mensaje ya completo (dependencias encontradas, o producto no existe) — sin prefijo genérico.
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al eliminar producto: ' . $e->getMessage()]);
}
