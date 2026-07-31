<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProductoRepositoryInterface;
use App\Support\LogoResolver;
use PDO;
use PDOException;

class ProductoRepository implements ProductoRepositoryInterface
{
    private const COLUMNAS = "
        p.id, p.codigo, p.nombre, p.descripcion,
        p.subprecio as precio_sin_iva, p.subprecio as precio,
        p.costo, p.categoria_id, p.activo, p.imagen, p.descuento,
        p.unidad_medida, p.peso_kg, p.permite_fracciones,
        c.nombre as categoria_nombre,
        COALESCE(ps.stock, 0) as stock_sucursal,
        COALESCE(ps.stock_minimo, 0) as stock_minimo,
        p.stock as stock_general
    ";

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listar(int $sucursalId, ?int $categoriaId, string $busqueda): array
    {
        $sql = 'SELECT ' . self::COLUMNAS . '
            FROM productos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
            WHERE p.activo = 1
            AND (COALESCE(ps.stock, 0) > 0)';
        $params = [$sucursalId];

        if ($categoriaId) {
            $sql .= ' AND p.categoria_id = ?';
            $params[] = $categoriaId;
        }

        if ($busqueda !== '') {
            $sql .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)';
            $like = '%' . $busqueda . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY p.nombre';

        // Igual que el original: el límite de 100 solo aplica cuando NO
        // hay categoría seleccionada (con o sin texto de búsqueda). Con
        // categoría, la lista completa de esa categoría.
        if (!$categoriaId) {
            $sql .= ' LIMIT 100';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function conPrecioCalculado(int $productoId, float $cantidad, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                p.id, p.codigo, p.nombre, p.descripcion,
                p.subprecio as precio_base, p.subprecio as precio_sin_iva,
                p.costo, p.categoria_id, p.activo, p.imagen, p.descuento,
                p.unidad_medida, p.peso_kg, p.permite_fracciones,
                c.nombre as categoria_nombre,
                COALESCE(ps.stock, 0) as stock_sucursal,
                COALESCE(ps.stock_minimo, 0) as stock_minimo,
                p.stock as stock_general
            FROM productos p
            INNER JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
            WHERE p.id = ? AND p.activo = 1'
        );
        $stmt->execute([$sucursalId, $productoId]);
        $producto = $stmt->fetch();

        if (!$producto) {
            return null;
        }

        $precioFinal = $this->precioConMayoreo($productoId, $cantidad);
        $producto['precio_calculado'] = $precioFinal;
        $producto['precio_original'] = (float) $producto['precio_base'];
        $producto['tiene_precio_mayoreo'] = $precioFinal < (float) $producto['precio_base'];

        return $producto;
    }

    public function precioConMayoreo(int $productoId, float $cantidad): float
    {
        $stmt = $this->pdo->prepare('SELECT subprecio as precio FROM productos WHERE id = ? AND activo = 1');
        $stmt->execute([$productoId]);
        $producto = $stmt->fetch();

        if (!$producto) {
            return 0.0;
        }

        $precioNormal = (float) $producto['precio'];

        $stmtMayoreo = $this->pdo->prepare(
            'SELECT cantidad_minima, precio_especial
             FROM producto_precios_mayoreo
             WHERE producto_id = ? AND activo = 1 AND cantidad_minima <= ?
             ORDER BY cantidad_minima DESC
             LIMIT 1'
        );
        $stmtMayoreo->execute([$productoId, $cantidad]);
        $mayoreo = $stmtMayoreo->fetch();

        return $mayoreo ? (float) $mayoreo['precio_especial'] : $precioNormal;
    }

    public function preciosMayoreo(int $productoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, cantidad_minima, precio_especial
             FROM producto_precios_mayoreo
             WHERE producto_id = ? AND activo = 1
             ORDER BY cantidad_minima ASC'
        );
        $stmt->execute([$productoId]);

        return $stmt->fetchAll();
    }

    public function stockPorSucursal(int $sucursalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.nombre, p.codigo, COALESCE(ps.stock, 0) as stock_sucursal
             FROM productos p
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
             WHERE p.activo = 1
             ORDER BY p.nombre'
        );
        $stmt->execute([$sucursalId]);

        return $stmt->fetchAll();
    }

    public function generarCodigoAutomatico(string $prefijo = 'PROD'): string
    {
        // Antes usaba "'^' || ? || '[0-9]+$'" para armar el REGEXP —
        // el operador || solo concatena strings en MySQL si el modo SQL
        // PIPES_AS_CONCAT está activo; por defecto es un OR lógico. Con
        // CONCAT() funciona igual sin depender de esa configuración.
        $stmt = $this->pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING(codigo, LENGTH(?) + 1) AS UNSIGNED)) as ultimo_num
             FROM productos
             WHERE codigo LIKE CONCAT(?, '%')
             AND codigo REGEXP CONCAT('^', ?, '[0-9]+$')"
        );
        $stmt->execute([$prefijo, $prefijo, $prefijo]);
        $row = $stmt->fetch();

        $ultimoNum = $row['ultimo_num'] ? (int) $row['ultimo_num'] : 0;
        $nuevoNum = $ultimoNum + 1;
        $codigo = sprintf('%s%04d', $prefijo, $nuevoNum);

        if ($this->codigoExiste($codigo)) {
            $nuevoNum++;
            $codigo = sprintf('%s%04d', $prefijo, $nuevoNum);
        }

        return $codigo;
    }

    private function codigoExiste(string $codigo): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM productos WHERE codigo = ?');
        $stmt->execute([$codigo]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function porFiltroStock(string $tipo, int $stockMinimo): array
    {
        $base = 'SELECT id, codigo, nombre, stock, unidad_medida FROM productos WHERE activo = 1';

        $sql = match ($tipo) {
            'total'      => "{$base} ORDER BY nombre ASC",
            'con_stock'  => "{$base} AND stock > 0 ORDER BY nombre ASC",
            'bajo_stock' => "{$base} AND stock > 0 AND stock <= ? ORDER BY stock ASC, nombre ASC",
            'sin_stock'  => "{$base} AND stock = 0 ORDER BY nombre ASC",
            default      => throw new \InvalidArgumentException("Tipo de filtro no válido: {$tipo}"),
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($tipo === 'bajo_stock' ? [$stockMinimo] : []);

        return $stmt->fetchAll();
    }

    public function buscarPorCodigo(string $codigo, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                p.id, p.codigo, p.nombre, p.descripcion,
                p.precio as precio_sin_iva, p.precio as precio,
                p.costo, p.categoria_id, p.activo,
                p.unidad_medida, p.peso_kg, p.permite_fracciones, p.imagen,
                c.nombre as categoria_nombre,
                COALESCE(ps.stock, 0) as stock_sucursal,
                COALESCE(ps.stock_minimo, 0) as stock_minimo,
                p.stock as stock_general
             FROM productos p
             INNER JOIN categorias c ON p.categoria_id = c.id
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
             WHERE p.codigo = ? AND p.activo = 1 AND (COALESCE(ps.stock, 0) > 0)
             LIMIT 1'
        );
        $stmt->execute([$sucursalId, $codigo]);
        $producto = $stmt->fetch();

        if (!$producto) {
            return null;
        }

        $rutaImagen = $this->rutaImagenPrincipal((int) $producto['id']);
        if ($rutaImagen !== null) {
            $producto['imagen'] = $rutaImagen;
        }

        return $producto;
    }

    public function rutaImagenPrincipal(int $productoId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT ruta_imagen FROM producto_imagenes WHERE producto_id = ? ORDER BY es_principal DESC, orden ASC LIMIT 1'
        );
        $stmt->execute([$productoId]);
        $row = $stmt->fetch();

        return $row['ruta_imagen'] ?? null;
    }

    public function buscarTiempoReal(int $sucursalId, string $busqueda, ?int $categoriaId): array
    {
        $sql = 'SELECT
                p.id, p.codigo, p.nombre, p.descripcion,
                p.precio as precio_sin_iva, p.precio as precio,
                p.costo, p.categoria_id, p.activo,
                p.unidad_medida, p.peso_kg, p.permite_fracciones, p.imagen,
                c.nombre as categoria_nombre,
                COALESCE(ps.stock, 0) as stock_sucursal,
                COALESCE(ps.stock_minimo, 0) as stock_minimo,
                p.stock as stock_general
             FROM productos p
             INNER JOIN categorias c ON p.categoria_id = c.id
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
             WHERE p.activo = 1 AND (COALESCE(ps.stock, 0) > 0)';
        $params = [$sucursalId];

        if ($busqueda !== '') {
            $sql .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)';
            $like = '%' . $busqueda . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($categoriaId) {
            $sql .= ' AND p.categoria_id = ?';
            $params[] = $categoriaId;
        }

        $sql .= ' ORDER BY p.nombre LIMIT 100';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll();

        $imagenes = $this->imagenesPrincipales(array_column($productos, 'id'));
        foreach ($productos as &$producto) {
            if (isset($imagenes[$producto['id']])) {
                $producto['imagen'] = $imagenes[$producto['id']];
            }
        }
        unset($producto);

        return $productos;
    }

    public function imagenesPrincipales(array $productoIds): array
    {
        $productoIds = array_values(array_unique(array_map('intval', $productoIds)));

        if ($productoIds === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($productoIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT producto_id, ruta_imagen FROM producto_imagenes
             WHERE producto_id IN ({$marcadores})
             ORDER BY es_principal DESC, orden ASC"
        );
        $stmt->execute($productoIds);

        // La primera fila por producto_id ya es la principal, por el ORDER BY de arriba.
        $mapa = [];
        foreach ($stmt->fetchAll() as $fila) {
            if (!isset($mapa[$fila['producto_id']])) {
                $mapa[$fila['producto_id']] = $fila['ruta_imagen'];
            }
        }

        return $mapa;
    }

    public function imagenPath(int $productoId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT ruta_imagen FROM producto_imagenes WHERE producto_id = ? ORDER BY es_principal DESC, orden ASC LIMIT 1'
        );
        $stmt->execute([$productoId]);
        $row = $stmt->fetch();

        if ($row) {
            $resuelta = LogoResolver::resolverImagenProducto($row['ruta_imagen']);
            if ($resuelta['path'] !== null) {
                return $resuelta['path'];
            }
        }

        // No está en producto_imagenes (o no se encontró en disco) — usar productos.imagen.
        $stmt = $this->pdo->prepare('SELECT imagen FROM productos WHERE id = ?');
        $stmt->execute([$productoId]);
        $row = $stmt->fetch();

        if ($row && !empty($row['imagen'])) {
            return LogoResolver::resolverImagenProducto($row['imagen'])['path'];
        }

        return null;
    }

    public function idsConMayoreo(array $productoIds): array
    {
        $productoIds = array_values(array_unique(array_map('intval', $productoIds)));

        if ($productoIds === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($productoIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT producto_id FROM producto_precios_mayoreo
             WHERE activo = 1 AND producto_id IN ({$marcadores})"
        );
        $stmt->execute($productoIds);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function stockYPrecioBase(int $productoId, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                COALESCE(ps.stock, 0) as stock_sucursal,
                p.nombre, p.permite_fracciones, p.descuento,
                p.subprecio as precio_base
             FROM productos p
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id AND ps.sucursal_id = ?
             WHERE p.id = ? AND p.activo = 1'
        );
        $stmt->execute([$sucursalId, $productoId]);

        return $stmt->fetch() ?: null;
    }

    public function actualizarDescuento(int $productoId, float $porcentaje): void
    {
        // Igual que con facturapi_test_api_key: la columna se creaba
        // dinámicamente en el código original la primera vez que se
        // necesitaba. Se conserva ese comportamiento por compatibilidad.
        try {
            $this->pdo->query('SELECT descuento FROM productos LIMIT 1');
        } catch (PDOException) {
            $this->pdo->exec('ALTER TABLE productos ADD COLUMN descuento DECIMAL(5,2) DEFAULT 0');
        }

        $stmt = $this->pdo->prepare('UPDATE productos SET descuento = ? WHERE id = ?');
        $stmt->execute([$porcentaje, $productoId]);
    }

    public function descontarStock(int $productoId, int $sucursalId, float $cantidad): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE producto_sucursal SET stock = stock - ? WHERE producto_id = ? AND sucursal_id = ?'
        );
        $stmt->execute([$cantidad, $productoId, $sucursalId]);
    }

    public function facturapiProductoId(int $productoId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT facturapi_producto_id FROM productos WHERE id = ?');
        $stmt->execute([$productoId]);
        $row = $stmt->fetch();

        return $row['facturapi_producto_id'] ?? null;
    }

    public function buscarAdministracion(array $filtros): array
    {
        [$where, $params] = $this->whereAdministracion($filtros);

        $stmtTotal = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT p.id) as total
             FROM productos p
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
             {$where}"
        );
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetch()['total'];

        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre,
                    COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') as sucursales_ids,
                    COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') as sucursales_nombres,
                    COALESCE(SUM(ps.stock), 0) as stock_total
             FROM productos p
             LEFT JOIN categorias c ON p.categoria_id = c.id
             LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
             LEFT JOIN sucursales s ON ps.sucursal_id = s.id
             {$where}
             GROUP BY p.id
             ORDER BY p.fecha_creacion DESC
             LIMIT 100"
        );
        $stmt->execute($params);

        return ['productos' => $stmt->fetchAll(), 'total' => $total];
    }

    public function contarDependencias(int $productoId): array
    {
        $contar = function (string $tabla) use ($productoId): int {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE producto_id = ?");
            $stmt->execute([$productoId]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'ventas'      => $contar('venta_detalles'),
            'compras'     => $contar('compra_detalles'),
            'movimientos' => $contar('movimientos_inventario'),
        ];
    }

    public function nombre(int $productoId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT nombre FROM productos WHERE id = ?');
        $stmt->execute([$productoId]);

        return $stmt->fetch()['nombre'] ?? null;
    }

    public function eliminarCascada(int $productoId): bool
    {
        foreach (['producto_imagenes', 'producto_precios_mayoreo', 'producto_sucursal'] as $tabla) {
            $stmt = $this->pdo->prepare("DELETE FROM {$tabla} WHERE producto_id = ?");
            $stmt->execute([$productoId]);
        }

        $stmt = $this->pdo->prepare('DELETE FROM productos WHERE id = ?');
        $stmt->execute([$productoId]);

        return $stmt->rowCount() > 0;
    }

    public function datosBasicos(int $productoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre, codigo, unidad_medida FROM productos WHERE id = ? AND activo = 1');
        $stmt->execute([$productoId]);

        return $stmt->fetch() ?: null;
    }

    public function stockEnSucursal(int $productoId, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT stock, stock_minimo FROM producto_sucursal WHERE producto_id = ? AND sucursal_id = ?'
        );
        $stmt->execute([$productoId, $sucursalId]);

        return $stmt->fetch() ?: null;
    }

    public function fijarStockSucursal(int $productoId, int $sucursalId, float $stock): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE producto_sucursal SET stock = ? WHERE producto_id = ? AND sucursal_id = ?'
        );
        $stmt->execute([$stock, $productoId, $sucursalId]);
    }

    public function crearStockSucursal(int $productoId, int $sucursalId, float $stock, float $stockMinimo): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$productoId, $sucursalId, $stock, $stockMinimo]);
    }

    private function whereAdministracion(array $filtros): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($filtros['busqueda'])) {
            $like = '%' . $filtros['busqueda'] . '%';
            $where .= ' AND (p.codigo LIKE ? OR p.nombre LIKE ? OR p.marca LIKE ? OR p.descripcion LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        if (!empty($filtros['categoria_id'])) {
            $where .= ' AND p.categoria_id = ?';
            $params[] = $filtros['categoria_id'];
        }

        if (!empty($filtros['proveedor_id'])) {
            $where .= ' AND p.proveedor_id = ?';
            $params[] = $filtros['proveedor_id'];
        }

        if (!empty($filtros['sucursal_id'])) {
            $where .= ' AND ps.sucursal_id = ?';
            $params[] = $filtros['sucursal_id'];
        }

        if (empty($filtros['mostrar_inactivos'])) {
            $where .= ' AND p.activo = 1';
        }

        return [$where, $params];
    }

    public function listarConPaginacion(array $filtros, int $pagina, int $porPagina): array
    {
        [$where, $params] = $this->whereAdministracion($filtros);

        $stmtTotal = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT p.id) as total
             FROM productos p
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
             {$where}"
        );
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetch()['total'];

        $totalPaginas = (int) ceil($total / $porPagina);
        if ($pagina > $totalPaginas && $totalPaginas > 0) {
            $pagina = $totalPaginas;
        }
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre,
                    COALESCE(GROUP_CONCAT(DISTINCT ps.sucursal_id), '') as sucursales_ids,
                    COALESCE(GROUP_CONCAT(DISTINCT s.nombre SEPARATOR ', '), 'Sin sucursales') as sucursales_nombres,
                    COALESCE(SUM(ps.stock), 0) as stock_total,
                    COALESCE(MIN(ps.stock_minimo), 0) as stock_minimo_total
             FROM productos p
             LEFT JOIN categorias c ON p.categoria_id = c.id
             LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
             LEFT JOIN producto_sucursal ps ON p.id = ps.producto_id
             LEFT JOIN sucursales s ON ps.sucursal_id = s.id
             {$where}
             GROUP BY p.id
             ORDER BY p.fecha_creacion DESC, p.id DESC
             LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'productos'     => $stmt->fetchAll(),
            'total'         => $total,
            'total_paginas' => $totalPaginas,
            'pagina_actual' => $pagina,
        ];
    }

    /** @param int[] $ids */
    private function agruparPorProductoId(string $tabla, array $ids, string $orden): array
    {
        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE producto_id IN ({$marcadores}) {$orden}");
        $stmt->execute($ids);

        $agrupado = [];
        foreach ($stmt->fetchAll() as $fila) {
            $agrupado[$fila['producto_id']][] = $fila;
        }

        return $agrupado;
    }

    public function imagenesPorProductos(array $productoIds): array
    {
        return $this->agruparPorProductoId(
            'producto_imagenes',
            array_map('intval', $productoIds),
            'ORDER BY producto_id, es_principal DESC, orden ASC'
        );
    }

    public function preciosMayoreoPorProductos(array $productoIds): array
    {
        $ids = array_map('intval', $productoIds);
        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM producto_precios_mayoreo
             WHERE producto_id IN ({$marcadores}) AND activo = 1
             ORDER BY cantidad_minima ASC"
        );
        $stmt->execute($ids);

        $agrupado = [];
        foreach ($stmt->fetchAll() as $fila) {
            $agrupado[$fila['producto_id']][] = $fila;
        }

        return $agrupado;
    }

    public function stockPorSucursalPorProductos(array $productoIds): array
    {
        $ids = array_map('intval', $productoIds);
        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT producto_id, sucursal_id, stock, stock_minimo
             FROM producto_sucursal
             WHERE producto_id IN ({$marcadores})"
        );
        $stmt->execute($ids);

        $resultado = [];
        foreach ($stmt->fetchAll() as $fila) {
            $resultado[$fila['producto_id']][$fila['sucursal_id']] = [
                'stock'        => $fila['stock'],
                'stock_minimo' => $fila['stock_minimo'],
            ];
        }

        return $resultado;
    }

    public function estadisticas(float $stockMinimo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) as total_productos,
                SUM(CASE WHEN p.stock > 0 THEN 1 ELSE 0 END) as con_stock,
                SUM(CASE WHEN p.stock = 0 THEN 1 ELSE 0 END) as sin_stock,
                SUM(CASE WHEN p.stock > 0 AND p.stock <= ? THEN 1 ELSE 0 END) as bajo_stock
             FROM productos p
             WHERE p.activo = 1"
        );
        $stmt->execute([$stockMinimo]);
        $stats = $stmt->fetch() ?: [];

        return [
            'total_productos' => (int) ($stats['total_productos'] ?? 0),
            'con_stock'       => (int) ($stats['con_stock'] ?? 0),
            'sin_stock'       => (int) ($stats['sin_stock'] ?? 0),
            'bajo_stock'      => (int) ($stats['bajo_stock'] ?? 0),
        ];
    }

    public function valorTotalInventario(): float
    {
        $stmt = $this->pdo->query(
            "SELECT SUM(p.precio * ps.stock) as valor_total
             FROM productos p
             INNER JOIN producto_sucursal ps ON p.id = ps.producto_id
             WHERE p.activo = 1"
        );

        return (float) ($stmt->fetch()['valor_total'] ?? 0);
    }
}
