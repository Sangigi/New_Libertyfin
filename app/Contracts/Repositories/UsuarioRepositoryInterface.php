<?php

namespace App\Contracts\Repositories;

/**
 * Repositorio de usuarios — opera sobre la conexión PDO de UNA empresa
 * específica (el sistema es multi-tenant: cada empresa tiene su propia
 * tabla `usuarios` en su propia base de datos).
 */
interface UsuarioRepositoryInterface
{
    /** @return array{id:int, username:string, password:string, nombre:string, rol:string, sucursal_id:int, email:?string}|null */
    public function findActiveByUsernameOrEmail(string $usuario): ?array;

    public function updatePasswordHash(int $userId, string $newHash): void;
}
