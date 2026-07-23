<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\SucursalRepository;
use App\Services\SucursalService;

header('Content-Type: application/json');

// Antes: session_start() + ini_set de endurecimiento + check de sesión/rol
// repetidos a mano (13+ líneas). Ahora Auth::requireRole() arranca la
// sesión reforzada y valida todo en una sola línea:
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['accion'] ?? '') !== 'crear') {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida']);
    exit;
}

try {
    // Antes: host/usuario/password hardcodeados + new mysqli(...) aquí mismo.
    $pdo = Database::pdo($_SESSION['empresa_db']);

    $service = new SucursalService(new SucursalRepository($pdo));
    $resultado = $service->crear($_POST['nombre'] ?? '');

    echo json_encode([
        'success'     => true,
        'message'     => 'Sucursal creada exitosamente',
        'sucursal_id' => $resultado['id'],
        'nombre'      => $resultado['nombre'],
    ]);
} catch (RuntimeException $e) {
    // Errores de validación / regla de negocio (nombre vacío, duplicado).
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
