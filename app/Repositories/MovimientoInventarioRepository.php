<?php

namespace App\Repositories;

use App\Contracts\Repositories\MovimientoInventarioRepositoryInterface;
use PDO;

class MovimientoInventarioRepository implements MovimientoInventarioRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(array $datos): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO movimientos_inventario
                (producto_id, sucursal_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, referencia_tipo, observaciones, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $datos['producto_id'],
            $datos['sucursal_id'],
            $datos['tipo'],
            $datos['cantidad'],
            $datos['cantidad_anterior'],
            $datos['cantidad_nueva'],
            $datos['referencia_tipo'],
            $datos['observaciones'],
            $datos['usuario_id'],
        ]);
    }
}
