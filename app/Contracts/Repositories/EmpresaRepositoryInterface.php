<?php

namespace App\Contracts\Repositories;

/**
 * Repositorio contra la base de datos PRINCIPAL (no la de una empresa
 * específica) — la tabla `empresas` vive ahí.
 */
interface EmpresaRepositoryInterface
{
    /** @return array<int, array{id:int, nombre_empresa:string, nombre_base_datos:string}> */
    public function activas(): array;

    public function findIdByDbName(string $dbName): ?int;

    /** @return array{plan:string, timbres_totales:int, timbres_disponibles:int}|null */
    public function findPlanInfo(int $empresaId): ?array;
}
