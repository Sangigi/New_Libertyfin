<?php

namespace App\Repositories;

use App\Contracts\Repositories\ClienteRepositoryInterface;
use PDO;

class ClienteRepository implements ClienteRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activos(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM clientes WHERE activo = 1 ORDER BY nombre');

        return $stmt->fetchAll();
    }

    public function encontrarActivoPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre FROM clientes WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function datosParaFacturacion(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT rfc, nombre, email, telefono, direccion FROM clientes WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }
}
