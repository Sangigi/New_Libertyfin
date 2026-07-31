<?php
// categorias.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Http\Middleware\Auth;
use App\Repositories\CategoriaRepository;
use App\Repositories\SistemaConfigRepository;
use App\Services\CategoriaService;

Auth::requireLoginForPage('login.php');

$mensaje = '';
$tipo_mensaje = '';

try {
    $conn = Database::pdo($_SESSION['empresa_db']);

    $empresa_info = (new SistemaConfigRepository($conn))->actual();

    $categoriaRepository = new CategoriaRepository($conn);
    $categoriaService = new CategoriaService($categoriaRepository);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_categoria'])) {
        try {
            $categoriaService->crear($_POST['nombre'] ?? '', $_POST['descripcion'] ?? '');
            $mensaje = 'Categoría creada correctamente';
            $tipo_mensaje = 'success';
        } catch (RuntimeException $e) {
            $mensaje = $e->getMessage();
            $tipo_mensaje = 'warning';
        } catch (Exception $e) {
            $mensaje = 'Error al crear la categoría: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_categoria'])) {
        try {
            $categoriaService->actualizar((int) ($_POST['id'] ?? 0), $_POST['nombre'] ?? '', $_POST['descripcion'] ?? '');
            $mensaje = 'Categoría actualizada correctamente';
            $tipo_mensaje = 'success';
        } catch (RuntimeException $e) {
            $mensaje = $e->getMessage();
            $tipo_mensaje = 'warning';
        } catch (Exception $e) {
            $mensaje = 'Error al actualizar la categoría: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }

    if (isset($_GET['eliminar'])) {
        try {
            $categoriaService->eliminar((int) $_GET['eliminar']);
            $mensaje = 'Categoría eliminada correctamente';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = $e->getMessage();
            $tipo_mensaje = 'warning';
        }
    }

    $categorias = $categoriaRepository->todasConConteoTotal();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorías - <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        }

        .sidebar {
            background: #2c3e50;
            color: white;
            min-height: calc(100vh - 56px);
            transition: all 0.3s ease;
        }

        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .stat-card {
            border-left: 4px solid var(--primary-color);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .welcome-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .ingresos-card {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        /* Botón hamburguesa para móvil */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.25rem;
            padding: 0.5rem;
            margin-right: 1rem;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
        }

        /* Responsive */
        @media (max-width: 767.98px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                position: fixed;
                top: 56px;
                left: -100%;
                width: 280px;
                height: calc(100vh - 56px);
                z-index: 1050;
                overflow-y: auto;
            }

            .sidebar.show {
                left: 0;
            }

            .sidebar-backdrop.show {
                display: block;
            }

            main {
                margin-left: 0 !important;
            }

            /* Ajustes para estadísticas en móvil */
            .stat-card .card-body {
                padding: 1rem;
            }

            .metric-value {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 575.98px) {
            .col-md-2 {
                flex: 0 0 50%;
                max-width: 50%;
            }

            .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .btn-group-actions .btn {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
        }

        /* Mejoras visuales */
        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-group-actions .btn {
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-group-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Estilos específicos para categorías */
        .category-card {
            border-left: 4px solid var(--primary-color);
        }

        .btn-action {
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .table-actions {
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <!-- Botón hamburguesa para móvil -->
            <button class="sidebar-toggle" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand" href="#">
                <i class="fas fa-cash-register me-2"></i>
                <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><span class="dropdown-item-text">
                                <small>Empresa: <?php echo htmlspecialchars($_SESSION['empresa_nombre']); ?></small>
                            </span></li>
                        <li><span class="dropdown-item-text">
                                <small>Rol: <?php echo htmlspecialchars($_SESSION['usuario_rol']); ?></small>
                            </span></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                    </ul>
                </li>
            </div>
        </div>
    </nav>

    <!-- Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar" id="sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="usuarios.php">
                                    <i class="fas fa-user-cog"></i>
                                    Usuarios
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="caja.php">
                                <i class="fas fa-cash-register"></i>
                                Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="productos.php">
                                <i class="fas fa-boxes"></i>
                                Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inventario.php">
                                <i class="fas fa-clipboard-list"></i>
                                Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="clientes.php">
                                <i class="fas fa-users"></i>
                                Clientes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ventas_lista.php">
                                <i class="fas fa-receipt"></i>
                                Ventas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="caja_historial.php">
                                <i class="fas fa-cash-register"></i>
                                Cortes de Caja
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="gastos.php">
                                <i class="fas fa-money-bill-wave"></i>
                                Gastos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="proveedores.php">
                                <i class="fas fa-truck"></i>
                                Proveedores
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="sucursales.php">
                                <i class="fas fa-store"></i>
                                Sucursales
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="fas fa-chart-bar"></i>
                                Reportes
                            </a>
                        </li>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="comisiones_config.php">
                                    <i class="fas fa-percentage"></i>
                                    Comisiones
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($_SESSION['usuario_rol'] === 'admin'): ?>
                                <a class="nav-link" href="configuracion.php">
                                    <i class="fas fa-cogs"></i>
                                    Configuración
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-tags me-2"></i>Gestión de Categorías
                    </h1>
                    <button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#modalCategoria">
                        <i class="fas fa-plus me-2"></i>Nueva Categoría
                    </button>
                </div>

                <!-- Alertas -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Total Categorías</div>
                                        <div class="metric-value text-primary"><?php echo count($categorias); ?></div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-tags fa-2x text-primary opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Categorías con Productos</div>
                                        <div class="metric-value text-success">
                                            <?php
                                            $categorias_con_productos = array_filter($categorias, function ($cat) {
                                                return $cat['total_productos'] > 0;
                                            });
                                            echo count($categorias_con_productos);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-boxes fa-2x text-success opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="metric-label">Categorías Vacías</div>
                                        <div class="metric-value text-warning">
                                            <?php
                                            $categorias_vacias = array_filter($categorias, function ($cat) {
                                                return $cat['total_productos'] == 0;
                                            });
                                            echo count($categorias_vacias);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-folder fa-2x text-warning opacity-25"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de Categorías -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Lista de Categorías
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($categorias)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No hay categorías registradas</h5>
                                <p class="text-muted">Comienza agregando tu primera categoría.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCategoria">
                                    <i class="fas fa-plus me-2"></i>Agregar Primera Categoría
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Descripción</th>
                                            <th>Productos</th>
                                            <th>Fecha Creación</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($categoria['nombre']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($categoria['descripcion'] ?: 'Sin descripción'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $categoria['total_productos'] > 0 ? 'primary' : 'secondary'; ?>">
                                                        <?php echo $categoria['total_productos']; ?> productos
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($categoria['fecha_creacion'])); ?></td>
                                                <td class="table-actions">
                                                    <button class="btn btn-sm btn-outline-primary me-1"
                                                        onclick="editarCategoria(<?php echo $categoria['id']; ?>, '<?php echo addslashes($categoria['nombre']); ?>', '<?php echo addslashes($categoria['descripcion']); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="categorias.php?eliminar=<?php echo $categoria['id']; ?>"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('¿Estás seguro de que deseas eliminar la categoría <?php echo addslashes($categoria['nombre']); ?>?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para agregar/editar categoría -->
    <div class="modal fade" id="modalCategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="formCategoria">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitulo">Nueva Categoría</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="categoriaId" name="id">
                        <input type="hidden" name="crear_categoria" value="1">

                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre de la categoría *</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required
                                placeholder="Ej: Electrónicos, Ropa, Hogar...">
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"
                                placeholder="Descripción opcional de la categoría..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarCategoria">
                            <i class="fas fa-save me-2"></i>Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Control del sidebar en móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            // Función para mostrar/ocultar sidebar
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }

            // Event listeners
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarBackdrop.addEventListener('click', toggleSidebar);

            // Cerrar sidebar al hacer clic en un enlace (en móvil)
            const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        toggleSidebar();
                    }
                });
            });

            // Mejorar la experiencia táctil
            let startX = 0;
            let currentX = 0;

            sidebar.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
            }, {
                passive: true
            });

            sidebar.addEventListener('touchmove', (e) => {
                currentX = e.touches[0].clientX;
                const diff = startX - currentX;

                if (diff > 50) { // Deslizar hacia la izquierda para cerrar
                    toggleSidebar();
                }
            }, {
                passive: true
            });
        });

        // Función para editar categoría
        function editarCategoria(id, nombre, descripcion) {
            document.getElementById('categoriaId').value = id;
            document.getElementById('nombre').value = nombre;
            document.getElementById('descripcion').value = descripcion || '';

            // Cambiar el formulario para actualizar
            const form = document.getElementById('formCategoria');
            const hiddenInput = document.querySelector('input[name="crear_categoria"]');
            hiddenInput.name = 'actualizar_categoria';

            document.getElementById('modalTitulo').textContent = 'Editar Categoría';
            document.getElementById('btnGuardarCategoria').innerHTML = '<i class="fas fa-save me-2"></i>Actualizar';

            const modal = new bootstrap.Modal(document.getElementById('modalCategoria'));
            modal.show();
        }

        // Limpiar modal al cerrar
        document.getElementById('modalCategoria').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formCategoria').reset();
            document.getElementById('categoriaId').value = '';

            // Restaurar el formulario para crear
            const hiddenInput = document.querySelector('input[name="actualizar_categoria"]');
            if (hiddenInput) {
                hiddenInput.name = 'crear_categoria';
            }

            document.getElementById('modalTitulo').textContent = 'Nueva Categoría';
            document.getElementById('btnGuardarCategoria').innerHTML = '<i class="fas fa-save me-2"></i>Guardar';
        });

        // Auto-enfocar el campo nombre al abrir el modal
        document.getElementById('modalCategoria').addEventListener('shown.bs.modal', function() {
            document.getElementById('nombre').focus();
        });
    </script>
</body>

</html>