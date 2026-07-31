<?php

namespace App\Contracts\Repositories;

/**
 * Arranca con lo que necesita caja.php (listar para el selector de
 * cliente en el carrito). Cuando se migre la Sección 4 (Ventas/
 * Clientes) completa, este contrato crece.
 */
interface ClienteRepositoryInterface
{
    /** Todas las columnas de clientes activos, para el selector del carrito. */
    public function activos(): array;

    /** @return array{id:int, nombre:string}|null */
    public function encontrarActivoPorId(int $id): ?array;

    /** Datos de facturación de un cliente (para el ticket/factura). */
    public function datosParaFacturacion(int $id): ?array;
}
