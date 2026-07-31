<?php

namespace App\Contracts\Repositories;

/**
 * `movimientos_inventario` — distinta de `movimientos_caja`
 * (MovimientoCajaRepository), que es otra tabla para otra cosa
 * (entradas/salidas de efectivo de una caja, no de stock).
 */
interface MovimientoInventarioRepositoryInterface
{
    /** @param array{producto_id:int, sucursal_id:int, tipo:string, cantidad:float, cantidad_anterior:float, cantidad_nueva:float, referencia_tipo:string, observaciones:string, usuario_id:int} $datos */
    public function registrar(array $datos): void;

    /**
     * Movimientos en un rango de fechas, con nombre de producto/sucursal/
     * usuario ya resueltos — para el reporte de movimientos.
     */
    public function filtrados(string $fechaInicio, string $fechaFin, ?int $sucursalId, ?int $productoId): array;
}
