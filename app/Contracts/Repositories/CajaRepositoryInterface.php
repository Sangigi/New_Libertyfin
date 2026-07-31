<?php

namespace App\Contracts\Repositories;

/**
 * Creció junto con la Sección 2 (apertura, cierre, resumen, historial).
 * Falta lo que necesite `caja.php` (terminal de venta) cuando se migre
 * — mismo contrato, no uno nuevo.
 */
interface CajaRepositoryInterface
{
    /** Caja abierta actual para un usuario/sucursal, con nombre de usuario y sucursal. */
    public function abiertaPara(int $usuarioId, int $sucursalId): ?array;

    /** Una caja por su ID, con nombre de usuario y sucursal (para el resumen/corte). */
    public function encontrarPorId(int $id): ?array;

    /** @return int ID de la caja recién abierta */
    public function crear(int $sucursalId, int $usuarioId, float $montoApertura, string $observaciones): int;

    /** @param array{monto_cierre:float, monto_esperado:float, diferencia:float, ventas_efectivo:float, ventas_tarjeta:float, ventas_transferencia:float, total_ventas:float, otros_ingresos:float, otros_egresos:float, observaciones:string} $datos */
    public function cerrar(int $cajaId, array $datos): bool;

    /**
     * @param array{fecha_desde?:string, fecha_hasta?:string, usuario_id?:int, estado?:string} $filtros
     * @return array lista de cajas de una sucursal (con nombre de usuario/sucursal), máx 50, más recientes primero
     */
    public function historialFiltrado(int $sucursalId, array $filtros): array;

    /** Una caja por ID, solo si sigue abierta (para el atajo de caja cacheada en sesión). */
    public function abiertaPorId(int $id): ?array;

    /** Suma una venta recién cobrada a los totales acumulados de la caja. */
    public function registrarVenta(int $cajaId, float $total, string $metodoPago): void;
}
