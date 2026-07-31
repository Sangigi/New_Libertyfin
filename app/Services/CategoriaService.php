<?php

namespace App\Services;

use App\Contracts\Repositories\CategoriaRepositoryInterface;
use App\Core\Logger;
use RuntimeException;

/**
 * Antes vivía inline en categorias.php (~100 líneas entre las 3
 * operaciones). Mismas reglas: nombre obligatorio y único entre
 * categorías activas; no se puede desactivar una categoría con
 * productos activos asignados.
 */
class CategoriaService
{
    public function __construct(private readonly CategoriaRepositoryInterface $categorias)
    {
    }

    public function crear(string $nombre, string $descripcion): array
    {
        $nombre = trim($nombre);
        $descripcion = trim($descripcion);

        if ($nombre === '') {
            throw new RuntimeException('El nombre de la categoría es obligatorio');
        }

        if ($this->categorias->existeNombreActivo($nombre)) {
            throw new RuntimeException("Ya existe una categoría con el nombre '{$nombre}'");
        }

        $id = $this->categorias->crear($nombre, $descripcion);
        Logger::info('categorias', 'Categoría creada', ['id' => $id, 'nombre' => $nombre]);

        return ['id' => $id, 'nombre' => $nombre];
    }

    public function actualizar(int $id, string $nombre, string $descripcion): array
    {
        $nombre = trim($nombre);
        $descripcion = trim($descripcion);

        if ($nombre === '') {
            throw new RuntimeException('El nombre de la categoría es obligatorio');
        }

        if ($this->categorias->existeNombreActivo($nombre, $id)) {
            throw new RuntimeException("Ya existe otra categoría con el nombre '{$nombre}'");
        }

        $this->categorias->actualizar($id, $nombre, $descripcion);
        Logger::info('categorias', 'Categoría actualizada', ['id' => $id, 'nombre' => $nombre]);

        return ['id' => $id, 'nombre' => $nombre];
    }

    /** "Eliminar" es en realidad desactivar — igual que el original. */
    public function eliminar(int $id): void
    {
        if ($this->categorias->contarProductosActivos($id) > 0) {
            throw new RuntimeException('No se puede eliminar la categoría porque tiene productos asociados');
        }

        $this->categorias->desactivar($id);
        Logger::info('categorias', 'Categoría desactivada', ['id' => $id]);
    }
}
