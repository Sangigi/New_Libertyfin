<?php

namespace App\Contracts\Repositories;

/**
 * Arranca con un solo método porque es lo único que necesita el cierre
 * de caja hoy. Cuando se migre la Sección 4 (Ventas/Clientes), este
 * contrato crece con los métodos que esos scripts necesiten.
 */
interface VentaRepositoryInterface
{
    /**
     * Totales agrupados por método de pago, para las ventas completadas
     * de una caja específica.
     *
     * @return array<string, array{cantidad:int, total:float}> indexado por metodo_pago
     */
    public function totalesPorMetodoPago(int $cajaId): array;

    /** @param array{codigo_venta:string, cliente_id:?int, usuario_id:int, sucursal_id:int, caja_id:int, subtotal:float, descuento:float, iva:float, total:float, metodo_pago:string, efectivo_recibido:float, cambio:float, descripcion:?string} $datos
     * @return int ID de la venta creada */
    public function crear(array $datos): int;

    /** @param array{producto_id:int, cantidad:float, precio_unitario:float, subtotal:float, descuento:float, total:float, unidad_medida:string} $detalle */
    public function agregarDetalle(int $ventaId, array $detalle): void;

    public function actualizarFacturapiReceipt(int $ventaId, ?string $receiptId, ?string $url): void;
}
