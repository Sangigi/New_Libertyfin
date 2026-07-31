<?php
// buscar_productos_ajax.php
//
// Igual que cargar_precios_mayoreo.php, el original fijaba una ruta de
// sesión distinta a la de login.php — se usa el arranque estándar.

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

header('Content-Type: application/json');
Auth::requireLogin();

$filtros = [
    'busqueda'          => trim($_GET['search'] ?? ''),
    'categoria_id'      => intval($_GET['categoria'] ?? 0),
    'proveedor_id'      => intval($_GET['proveedor'] ?? 0),
    'sucursal_id'       => intval($_GET['sucursal'] ?? 0),
    'mostrar_inactivos' => ($_GET['show_inactive'] ?? '') === '1',
];

try {
    $productos = new ProductoRepository(Database::pdo($_SESSION['empresa_db']));
    $resultado = $productos->buscarAdministracion($filtros);

    echo json_encode([
        'success'         => true,
        'productos'       => $resultado['productos'],
        'total_registros' => $resultado['total'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
