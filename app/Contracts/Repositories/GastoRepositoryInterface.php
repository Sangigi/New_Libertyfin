<?php

namespace App\Contracts\Repositories;

/**
 * Arranca con lo único que necesita procesar_pago: registrar el costo
 * de mercancía como gasto automático. Cuando se migre un módulo de
 * Gastos completo, este contrato crece.
 */
interface GastoRepositoryInterface
{
    /** @param array{concepto:string, monto:float, venta_id:int, usuario_id:int, sucursal_id:int, metodo_pago:string, descripcion:string} $datos */
    public function registrarCostoVenta(array $datos): void;
}
