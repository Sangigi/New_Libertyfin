<?php

namespace App\Repositories;

use App\Contracts\Repositories\EmpresaRepositoryInterface;
use PDO;

class EmpresaRepository implements EmpresaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activas(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nombre_empresa, nombre_base_datos FROM empresas WHERE activo = TRUE'
        );

        return $stmt->fetchAll();
    }

    public function findIdByDbName(string $dbName): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM empresas WHERE nombre_base_datos = ? AND activo = TRUE LIMIT 1'
        );
        $stmt->execute([$dbName]);
        $row = $stmt->fetch();

        return $row ? (int) $row['id'] : null;
    }

    public function findPlanInfo(int $empresaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT plan, timbres_totales, timbres_disponibles FROM empresas WHERE id = ?'
        );
        $stmt->execute([$empresaId]);

        return $stmt->fetch() ?: null;
    }
}
