<?php
// generar_codigo.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\ProductoRepository;

Auth::requireLogin();

header('Content-Type: application/json');

try {
    $productos = new ProductoRepository(Database::pdo($_SESSION['empresa_db']));
    $codigo = $productos->generarCodigoAutomatico();

    echo json_encode([
        'success' => true,
        'codigo'  => $codigo,
        'message' => 'Código generado exitosamente',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
