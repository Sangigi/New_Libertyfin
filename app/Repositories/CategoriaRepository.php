<?php

namespace App\Repositories;

use App\Contracts\Repositories\CategoriaRepositoryInterface;
use PDO;

class CategoriaRepository implements CategoriaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function conConteoProductos(int $sucursalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, COUNT(p.id) as producto_count
             FROM categorias c
             LEFT JOIN productos p ON c.id = p.categoria_id AND p.activo = 1
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
                 AND (COALESCE(ps.stock, 0) > 0)
             WHERE c.activo = 1
             GROUP BY c.id
             ORDER BY c.nombre'
        );
        $stmt->execute([$sucursalId]);

        return $stmt->fetchAll();
    }

    public function todasConConteoTotal(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*,
                (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id AND p.activo = TRUE) as total_productos
             FROM categorias c
             WHERE c.activo = TRUE
             ORDER BY c.nombre'
        );

        return $stmt->fetchAll();
    }

    public function existeNombreActivo(string $nombre, ?int $excluirId = null): bool
    {
        $sql = 'SELECT id FROM categorias WHERE nombre = ? AND activo = TRUE';
        $params = [$nombre];

        if ($excluirId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excluirId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function crear(string $nombre, string $descripcion): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO categorias (nombre, descripcion) VALUES (?, ?)');
        $stmt->execute([$nombre, $descripcion]);

        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, string $descripcion): void
    {
        $stmt = $this->pdo->prepare('UPDATE categorias SET nombre = ?, descripcion = ? WHERE id = ?');
        $stmt->execute([$nombre, $descripcion, $id]);
    }

    public function contarProductosActivos(int $categoriaId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ? AND activo = TRUE');
        $stmt->execute([$categoriaId]);

        return (int) $stmt->fetchColumn();
    }

    public function desactivar(int $categoriaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE categorias SET activo = FALSE WHERE id = ?');
        $stmt->execute([$categoriaId]);
    }
}
