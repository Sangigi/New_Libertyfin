<?php

namespace App\Services;

use App\Contracts\Repositories\EmpresaRepositoryInterface;
use App\Core\Database;
use App\Repositories\SucursalRepository;
use App\Repositories\UsuarioRepository;
use App\Services\Exceptions\InvalidCredentialsException;
use Throwable;

/**
 * Antes vivía inline en login.php: un while() recorriendo cada empresa
 * activa, abriendo conexión, buscando al usuario y su sucursal. Misma
 * lógica exacta, ahora en un solo lugar, reutilizable y testeable sin
 * tener que simular un POST.
 */
class AuthService
{
    public function __construct(private readonly EmpresaRepositoryInterface $empresas)
    {
    }

    /**
     * @return array{empresa_db: string, empresa_nombre: string, usuario: array, sucursal: array}
     * @throws InvalidCredentialsException cuando no hay coincidencia de usuario/contraseña/sucursal
     */
    public function attempt(string $usuario, string $password): array
    {
        foreach ($this->empresas->activas() as $empresa) {
            // Validar nombre de base de datos (solo caracteres permitidos)
            // — misma regla que ya tenías en login.php.
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $empresa['nombre_base_datos'])) {
                continue;
            }

            try {
                $pdoEmpresa = Database::pdo($empresa['nombre_base_datos']);
            } catch (Throwable) {
                // No se pudo conectar a esta empresa — seguir con la siguiente.
                continue;
            }

            $usuarios = new UsuarioRepository($pdoEmpresa);
            $usuarioEncontrado = $usuarios->findActiveByUsernameOrEmail($usuario);

            if (!$usuarioEncontrado || !password_verify($password, $usuarioEncontrado['password'])) {
                continue;
            }

            if (password_needs_rehash($usuarioEncontrado['password'], PASSWORD_DEFAULT)) {
                $usuarios->updatePasswordHash(
                    (int) $usuarioEncontrado['id'],
                    password_hash($password, PASSWORD_DEFAULT)
                );
            }

            $sucursal = (new SucursalRepository($pdoEmpresa))
                ->findActiveById((int) $usuarioEncontrado['sucursal_id']);

            if (!$sucursal) {
                continue;
            }

            return [
                'empresa_db'     => $empresa['nombre_base_datos'],
                'empresa_nombre' => $empresa['nombre_empresa'],
                'usuario'        => $usuarioEncontrado,
                'sucursal'       => $sucursal,
            ];
        }

        throw new InvalidCredentialsException('Usuario/Email o contraseña incorrectos.');
    }
}
