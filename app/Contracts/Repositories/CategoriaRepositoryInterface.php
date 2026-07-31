<?php

namespace App\Contracts\Repositories;

interface CategoriaRepositoryInterface
{
    /** Categorías activas con el conteo de productos disponibles (con stock) en una sucursal — para el punto de venta. */
    public function conConteoProductos(int $sucursalId): array;

    /** Categorías activas con el conteo TOTAL de productos activos (sin filtrar por sucursal/stock) — para la gestión de categorías. */
    public function todasConConteoTotal(): array;

    /** @return array{id:int, nombre:string}|null */
    public function encontrarActivaPorId(int $id): ?array;

    public function existeNombreActivo(string $nombre, ?int $excluirId = null): bool;

    /** @return int ID de la categoría creada */
    public function crear(string $nombre, string $descripcion): int;

    public function actualizar(int $id, string $nombre, string $descripcion): void;

    public function contarProductosActivos(int $categoriaId): int;

    public function desactivar(int $categoriaId): void;
}
