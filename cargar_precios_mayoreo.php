<?php
// cargar_precios_mayoreo.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

// Antes: session_save_path() a una carpeta distinta a la que usa
// login.php — con eso, la sesión que abre el login nunca se veía
// aquí (los datos de sesión quedan guardados en otro lugar) y
// "No autorizado" salía siempre. Se usa la sesión estándar, la misma
// que ya usan login.php/dashboard.php/caja.php.
Auth::requireLogin();

header('Content-Type: application/json');

$producto_id = isset($_GET['producto_id']) ? intval($_GET['producto_id']) : 0;

if ($producto_id <= 0) {
    echo json_encode(['success' => true, 'precios' => []]);
    exit();
}

try {
    $productos = new ProductoRepository(Database::pdo($_SESSION['empresa_db']));
    $precios = $productos->preciosMayoreo($producto_id);

    echo json_encode([
        'success' => true,
        'precios' => array_map(fn ($p) => [
            'id'              => (int) $p['id'],
            'cantidad_minima' => (float) $p['cantidad_minima'],
            'precio_especial' => (float) $p['precio_especial'],
        ], $precios),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
}
