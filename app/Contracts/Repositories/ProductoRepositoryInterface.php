<?php

namespace App\Contracts\Repositories;

interface ProductoRepositoryInterface
{
    /**
     * Reemplaza las 4 variantes de SQL casi idénticas que caja.php armaba
     * a mano según los filtros (sin filtro / por categoría / por
     * búsqueda / ambos). $busqueda = '' significa "sin filtro de texto".
     */
    public function listar(int $sucursalId, ?int $categoriaId, string $busqueda): array;

    /** Un producto con su precio ya calculado (aplica mayoreo si corresponde) para una cantidad dada. */
    public function conPrecioCalculado(int $productoId, float $cantidad, int $sucursalId): ?array;

    /** Precio unitario aplicando precio de mayoreo si existe uno vigente para esa cantidad. */
    public function precioConMayoreo(int $productoId, float $cantidad): float;

    /** Ruta física (o null) de la imagen de un producto, ya resuelta entre las carpetas posibles. */
    public function imagenPath(int $productoId): ?string;

    /**
     * De una lista de IDs, cuáles tienen precio de mayoreo activo
     * configurado. Reemplaza una consulta por producto (N+1) dentro
     * del bucle de renderizado por una sola consulta por lote.
     *
     * @param int[] $productoIds
     * @return int[] subconjunto de $productoIds que sí tienen mayoreo
     */
    public function idsConMayoreo(array $productoIds): array;

    /** Stock en sucursal + datos base para revalidar cantidad/precio en el carrito. */
    public function stockYPrecioBase(int $productoId, int $sucursalId): ?array;

    /** Persiste el % de descuento del producto (columna se crea si no existe, igual que antes). */
    public function actualizarDescuento(int $productoId, float $porcentaje): void;

    /** Descuenta stock de una sucursal tras una venta. */
    public function descontarStock(int $productoId, int $sucursalId, float $cantidad): void;

    /** ID de producto en Facturapi, si el producto lo tiene vinculado. */
    public function facturapiProductoId(int $productoId): ?string;

    /** Lista completa de precios de mayoreo de un producto (para mostrarla, no solo para calcular). */
    public function preciosMayoreo(int $productoId): array;

    /** Stock actual de todos los productos activos en una sucursal (para refrescar la vista sin recargar). */
    public function stockPorSucursal(int $sucursalId): array;

    /** Siguiente código disponible con un prefijo (ej. PROD0001, PROD0002...). */
    public function generarCodigoAutomatico(string $prefijo = 'PROD'): string;

    /**
     * Lista de productos por filtro de stock GLOBAL (columna
     * productos.stock — no el stock por sucursal). $tipo: total,
     * con_stock, bajo_stock, sin_stock.
     */
    public function porFiltroStock(string $tipo, int $stockMinimo): array;

    /**
     * Búsqueda exacta por código de barras, con stock en una sucursal.
     * A diferencia de listar()/conPrecioCalculado(), usa `precio`
     * directo (precio final ya con descuento aplicado) — no
     * `subprecio` — porque este flujo no vuelve a calcular descuento,
     * solo muestra el precio final tal cual.
     */
    public function buscarPorCodigo(string $codigo, int $sucursalId): ?array;

    /** Ruta cruda (sin resolver a filesystem) de la imagen principal en producto_imagenes, si existe. */
    public function rutaImagenPrincipal(int $productoId): ?string;

    /**
     * Búsqueda rápida por texto/categoría para autocompletar mientras
     * se escribe. Como buscarPorCodigo(), usa `precio` directo, no
     * `subprecio`.
     */
    public function buscarTiempoReal(int $sucursalId, string $busqueda, ?int $categoriaId): array;

    /**
     * Igual que rutaImagenPrincipal() pero para varios productos a la
     * vez — evita una consulta por producto en un listado.
     *
     * @param int[] $productoIds
     * @return array<int, string> producto_id => ruta_imagen
     */
    public function imagenesPrincipales(array $productoIds): array;

    /**
     * Búsqueda administrativa con filtros combinables (texto, categoría,
     * proveedor, sucursal, incluir inactivos) — la que usa la gestión de
     * productos, distinta de listar()/buscarTiempoReal() que son para el
     * punto de venta. Incluye resumen de sucursales/stock total por
     * producto.
     *
     * @param array{busqueda?:string, categoria_id?:int, proveedor_id?:int, sucursal_id?:int, mostrar_inactivos?:bool} $filtros
     * @return array{productos: array, total: int}
     */
    public function buscarAdministracion(array $filtros): array;

    /** @return array{ventas:int, compras:int, movimientos:int} conteos de registros que referencian este producto */
    public function contarDependencias(int $productoId): array;

    public function nombre(int $productoId): ?string;

    /** Borra imágenes, precios de mayoreo y relación con sucursales, y por último el producto. */
    public function eliminarCascada(int $productoId): bool;

    /** @return array{id:int, nombre:string, codigo:string, unidad_medida:string}|null */
    public function datosBasicos(int $productoId): ?array;

    /** @return array{stock:float, stock_minimo:float}|null null si el producto no tiene registro en esa sucursal */
    public function stockEnSucursal(int $productoId, int $sucursalId): ?array;

    /** Fija (no incrementa) el stock de un producto en una sucursal donde ya existe registro. */
    public function fijarStockSucursal(int $productoId, int $sucursalId, float $stock): void;

    /** Crea el registro de stock de un producto en una sucursal donde todavía no existía. */
    public function crearStockSucursal(int $productoId, int $sucursalId, float $stock, float $stockMinimo): void;

    /**
     * Búsqueda administrativa PAGINADA — la que usa la tabla de gestión
     * de productos completa (distinta de buscarAdministracion(), que es
     * para un cuadro de filtro rápido sin paginar).
     *
     * @param array{busqueda?:string, categoria_id?:int, proveedor_id?:int, sucursal_id?:int, mostrar_inactivos?:bool} $filtros
     * @return array{productos: array, total: int, total_paginas: int, pagina_actual: int}
     */
    public function listarConPaginacion(array $filtros, int $pagina, int $porPagina): array;

    /** @param int[] $productoIds @return array<int, array> producto_id => [imagenes...] */
    public function imagenesPorProductos(array $productoIds): array;

    /** @param int[] $productoIds @return array<int, array> producto_id => [tiers de mayoreo...] */
    public function preciosMayoreoPorProductos(array $productoIds): array;

    /** @param int[] $productoIds @return array<int, array<int, array{stock:float, stock_minimo:float}>> producto_id => sucursal_id => stock */
    public function stockPorSucursalPorProductos(array $productoIds): array;

    /** @return array{total_productos:int, con_stock:int, sin_stock:int, bajo_stock:int} */
    public function estadisticas(float $stockMinimo): array;

    public function valorTotalInventario(): float;

    /**
     * Productos agotados o por debajo de su stock mínimo — para el
     * reporte de reabastecimiento. Si $sucursalId es null, agrega
     * stock de todas las sucursales; si no, solo esa.
     *
     * @return array cada fila: codigo, nombre, categoria, descripcion, precio, stock, stock_minimo, estado_stock
     */
    public function bajoStock(?int $sucursalId): array;

    /**
     * Listado completo de inventario con filtros combinables de
     * sucursal/categoría/nivel de stock — para el reporte de inventario
     * completo (distinto de bajoStock(), que siempre filtra a solo lo
     * que necesita reabastecerse).
     */
    public function inventarioCompleto(?int $sucursalId, ?int $categoriaId, string $filtroStock): array;

    /** @return array{bajo_stock:int, sin_stock:int} con los mismos filtros de sucursal/categoría que inventarioCompleto() */
    public function contarBajoYSinStock(?int $sucursalId, ?int $categoriaId): array;
}
