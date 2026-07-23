<?php

namespace App\Repositories;

use App\Contracts\Repositories\CajaRepositoryInterface;
use PDO;

class CajaRepository implements CajaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function abiertaPara(int $usuarioId, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM caja WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'abierta'"
        );
        $stmt->execute([$usuarioId, $sucursalId]);

        return $stmt->fetch() ?: null;
    }
}
