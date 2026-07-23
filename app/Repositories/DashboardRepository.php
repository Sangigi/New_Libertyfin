<?php

namespace App\Repositories;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use PDO;

class DashboardRepository implements DashboardRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function estadisticasHoy(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM productos WHERE activo = TRUE) as total_productos,
                (SELECT COUNT(*) FROM clientes WHERE activo = TRUE) as total_clientes,
                (SELECT COUNT(*) FROM usuarios WHERE activo = TRUE) as total_usuarios,
                (SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()) as ventas_hoy,
                (SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = CURDATE()) as ingresos_hoy"
        );

        return $stmt->fetch();
    }
}
