<?php

namespace App\Contracts\Repositories;

/**
 * Arranca con un solo método porque es lo único que necesita el
 * dashboard hoy. Cuando se migre la Sección 2 (Caja) completa
 * (apertura, cierre, historial, resumen), este contrato crece con los
 * métodos que esos scripts necesiten — es la misma clase, no una nueva.
 */
interface CajaRepositoryInterface
{
    public function abiertaPara(int $usuarioId, int $sucursalId): ?array;
}
