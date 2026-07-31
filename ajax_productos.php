<?php
// ajax_productos.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\MovimientoInventarioRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\SistemaConfigRepository;
use App\Repositories\SucursalRepository;
use App\Services\ProductoService;

Auth::requireLogin();

$response = [
    'success'                => true,
    'productos'              => [],
    'total_registros'        => 0,
    'total_paginas'          => 0,
    'pagina_actual'          => 1,
    'stock_minimo_global'    => 5,
    'estadisticas'           => [],
    'valor_total_inventario' => 0,
];

try {
    $pdoEmpresa = Database::pdo($_SESSION['empresa_db']);

    $sistemaConfig = (new SistemaConfigRepository($pdoEmpresa))->actual();
    $stockMinimoGlobal = (float) ($sistemaConfig['stock_minimo_global'] ?? 5);
    $response['stock_minimo_global'] = $stockMinimoGlobal;

    $filtros = [
        'busqueda'          => trim($_GET['search'] ?? ''),
        'categoria_id'      => intval($_GET['categoria'] ?? 0),
        'proveedor_id'      => intval($_GET['proveedor'] ?? 0),
        'sucursal_id'       => intval($_GET['sucursal'] ?? 0),
        'mostrar_inactivos' => ($_GET['show_inactive'] ?? '') === '1',
    ];
    $pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $porPagina = 5;

    $productoService = new ProductoService(
        $pdoEmpresa,
        new ProductoRepository($pdoEmpresa),
        new MovimientoInventarioRepository($pdoEmpresa),
        new SucursalRepository($pdoEmpresa)
    );

    $resultado = $productoService->listarParaGestion($filtros, $pagina, $porPagina, $stockMinimoGlobal);

    $response['productos'] = $resultado['productos'];
    $response['total_registros'] = $resultado['total_registros'];
    $response['total_paginas'] = $resultado['total_paginas'];
    $response['pagina_actual'] = $resultado['pagina_actual'];
    $response['estadisticas'] = $resultado['estadisticas'];
    $response['valor_total_inventario'] = $resultado['valor_total_inventario'];
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);
