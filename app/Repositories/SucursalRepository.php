<?php

namespace App\Repositories;

use App\Contracts\Repositories\SucursalRepositoryInterface;
use PDO;

/**
 * Implementación PDO de SucursalRepositoryInterface.
 *
 * Toda la lógica de acceso a datos que antes vivía inline en
 * guardar_sucursal.php (las dos queries con prepare/execute) vive
 * ahora aquí, y solo aquí.
 */
class SucursalRepository implements SucursalRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function existsByNombre(string $nombre): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM sucursales WHERE nombre = ? AND activo = 1'
        );
        $stmt->execute([$nombre]);

        return (bool) $stmt->fetch();
    }

    public function create(string $nombre): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sucursales (nombre, activo) VALUES (?, 1)'
        );
        $stmt->execute([$nombre]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findActiveById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, es_matriz, activo FROM sucursales WHERE id = ? AND activo = TRUE'
        );
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }
}
