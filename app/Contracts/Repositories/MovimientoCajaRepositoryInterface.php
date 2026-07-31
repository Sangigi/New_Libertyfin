<?php

namespace App\Contracts\Repositories;

interface MovimientoCajaRepositoryInterface
{
    /** Todos los movimientos de una caja, más recientes primero. */
    public function porCaja(int $cajaId): array;

    /**
     * Totales por tipo (ingreso/egreso) de movimientos que NO son ventas
     * — para el cálculo de monto esperado al cerrar caja.
     *
     * @return array<string, float> ej. ['ingreso' => 100.0, 'egreso' => 50.0]
     */
    public function totalesPorTipoExcluyendoVentas(int $cajaId): array;
}
