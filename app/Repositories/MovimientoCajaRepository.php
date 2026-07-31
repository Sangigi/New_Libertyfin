<?php

namespace App\Repositories;

use App\Contracts\Repositories\MovimientoCajaRepositoryInterface;
use PDO;

class MovimientoCajaRepository implements MovimientoCajaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function porCaja(int $cajaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM movimientos_caja WHERE caja_id = ? ORDER BY fecha DESC'
        );
        $stmt->execute([$cajaId]);

        return $stmt->fetchAll();
    }

    public function totalesPorTipoExcluyendoVentas(int $cajaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tipo, SUM(monto) as total
             FROM movimientos_caja
             WHERE caja_id = ? AND referencia_tipo != 'venta'
             GROUP BY tipo"
        );
        $stmt->execute([$cajaId]);

        $totales = ['ingreso' => 0.0, 'egreso' => 0.0];
        foreach ($stmt->fetchAll() as $fila) {
            if (isset($totales[$fila['tipo']])) {
                $totales[$fila['tipo']] = (float) $fila['total'];
            }
        }

        return $totales;
    }
}
