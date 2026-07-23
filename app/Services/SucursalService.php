<?php

namespace App\Services;

use App\Contracts\Repositories\SucursalRepositoryInterface;
use RuntimeException;

/**
 * Regla de negocio "crear sucursal" — antes vivía como función suelta
 * crearSucursal($conn) dentro de guardar_sucursal.php, mezclada con el
 * manejo de la petición HTTP.
 */
class SucursalService
{
    public function __construct(private readonly SucursalRepositoryInterface $sucursales)
    {
    }

    /**
     * @return array{id: int, nombre: string}
     * @throws RuntimeException si el nombre es inválido o ya existe
     */
    public function crear(string $nombre): array
    {
        $nombre = trim($nombre);

        if ($nombre === '') {
            throw new RuntimeException('El nombre de la sucursal es requerido');
        }

        if ($this->sucursales->existsByNombre($nombre)) {
            throw new RuntimeException('Ya existe una sucursal con ese nombre');
        }

        $id = $this->sucursales->create($nombre);

        return ['id' => $id, 'nombre' => $nombre];
    }
}
