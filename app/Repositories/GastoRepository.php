<?php

namespace App\Repositories;

use App\Contracts\Repositories\GastoRepositoryInterface;
use PDO;

class GastoRepository implements GastoRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrarCostoVenta(array $datos): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO gastos (concepto, categoria, monto, tipo, origen, venta_id, usuario_id, sucursal_id, metodo_pago, fecha, descripcion)
             VALUES (?, 'Costo de venta', ?, 'automatico', 'venta', ?, ?, ?, ?, NOW(), ?)"
        );

        $stmt->execute([
            $datos['concepto'],
            $datos['monto'],
            $datos['venta_id'],
            $datos['usuario_id'],
            $datos['sucursal_id'],
            $datos['metodo_pago'],
            $datos['descripcion'],
        ]);
    }
}
