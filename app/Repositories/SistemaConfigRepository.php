<?php

namespace App\Repositories;

use App\Contracts\Repositories\SistemaConfigRepositoryInterface;
use PDO;

class SistemaConfigRepository implements SistemaConfigRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function actual(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT nombre_empresa, rfc, telefono, email, color_primario, color_secundario, logo
             FROM sistema_config
             LIMIT 1'
        );

        return $stmt->fetch() ?: null;
    }
}
