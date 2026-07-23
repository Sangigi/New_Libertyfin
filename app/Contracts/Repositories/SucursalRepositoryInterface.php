<?php

namespace App\Contracts\Repositories;

/**
 * Contrato de acceso a datos de sucursales.
 *
 * El Service depende de esta interfaz, no de la clase concreta —
 * SucursalService no sabe (ni le importa) si por debajo hay PDO,
 * un mock en un test, u otra fuente de datos el día de mañana.
 */
interface SucursalRepositoryInterface
{
    public function existsByNombre(string $nombre): bool;

    /** @return int ID de la sucursal creada */
    public function create(string $nombre): int;

    /** @return array{id:int, nombre:string, es_matriz:mixed, activo:mixed}|null */
    public function findActiveById(int $id): ?array;
}
