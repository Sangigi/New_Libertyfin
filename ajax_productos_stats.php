<?php
// ajax_productos_stats.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;
use App\Repositories\SistemaConfigRepository;

Auth::requireLogin();

header('Content-Type: application/json');

$tipo = $_GET['tipo'] ?? 'total';

if (!in_array($tipo, ['total', 'con_stock', 'bajo_stock', 'sin_stock'], true)) {
    echo json_encode(['success' => false, 'message' => 'Tipo no válido']);
    exit();
}

try {
    $pdo = Database::pdo($_SESSION['empresa_db']);
    $productos = new ProductoRepository($pdo);

    $sistemaConfig = (new SistemaConfigRepository($pdo))->actual();
    $stockMinimo = $sistemaConfig['stock_minimo_global'] ?? 5;

    $filas = $productos->porFiltroStock($tipo, $stockMinimo);

    $resultado = array_map(fn ($p) => [
        'id'            => $p['id'],
        'codigo'        => $p['codigo'],
        'nombre'        => $p['nombre'],
        'stock_total'   => (float) $p['stock'],
        'unidad_medida' => $p['unidad_medida'] ?? 'pieza',
    ], $filas);

    echo json_encode([
        'success'      => true,
        'productos'    => $resultado,
        'total'        => count($resultado),
        'stock_minimo' => $stockMinimo,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
}
