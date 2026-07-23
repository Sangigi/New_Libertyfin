<?php

namespace App\Repositories;

use App\Contracts\Repositories\UsuarioRepositoryInterface;
use PDO;

class UsuarioRepository implements UsuarioRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveByUsernameOrEmail(string $usuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password, nombre, rol, sucursal_id, email
             FROM usuarios
             WHERE (username = ? OR email = ?)
             AND activo = TRUE
             LIMIT 1'
        );
        $stmt->execute([$usuario, $usuario]);

        return $stmt->fetch() ?: null;
    }

    public function updatePasswordHash(int $userId, string $newHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?');
        $stmt->execute([$newHash, $userId]);
    }
}
