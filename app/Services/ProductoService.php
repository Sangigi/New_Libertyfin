<?php

namespace App\Services;

use App\Contracts\Repositories\MovimientoInventarioRepositoryInterface;
use App\Contracts\Repositories\ProductoRepositoryInterface;
use App\Contracts\Repositories\SucursalRepositoryInterface;
use App\Core\Logger;
use PDO;
use RuntimeException;
use Throwable;

class ProductoService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProductoRepositoryInterface $productos,
        private readonly MovimientoInventarioRepositoryInterface $movimientos,
        private readonly SucursalRepositoryInterface $sucursales,
    ) {
    }

    /**
     * Antes: ~155 líneas inline en eliminar_producto.php. Misma regla:
     * no se puede eliminar si el producto tiene ventas, compras o
     * movimientos de inventario asociados (hay que desactivarlo en vez
     * de eliminarlo); si no tiene dependencias, borra en cascada
     * (imágenes, precios de mayoreo, relación con sucursales, producto)
     * y limpia los archivos de imagen físicos.
     *
     * @return array{nombre: string, archivos_eliminados: int}
     * @throws RuntimeException si tiene dependencias o no existe
     */
    public function eliminar(int $productoId): array
    {
        $dependencias = $this->productos->contarDependencias($productoId);
        $mensajes = [];

        if ($dependencias['ventas'] > 0) {
            $mensajes[] = "• Tiene {$dependencias['ventas']} registro(s) en ventas";
        }
        if ($dependencias['compras'] > 0) {
            $mensajes[] = "• Tiene {$dependencias['compras']} registro(s) en compras";
        }
        if ($dependencias['movimientos'] > 0) {
            $mensajes[] = "• Tiene {$dependencias['movimientos']} registro(s) en movimientos de inventario";
        }

        if ($mensajes !== []) {
            throw new RuntimeException(
                "No se puede eliminar el producto porque tiene registros asociados:\n"
                . implode("\n", $mensajes)
                . "\n💡 Sugerencia: Desactive el producto en lugar de eliminarlo."
            );
        }

        $nombre = $this->productos->nombre($productoId) ?? 'Desconocido';

        if (!$this->productos->eliminarCascada($productoId)) {
            throw new RuntimeException("No se encontró el producto con ID: {$productoId}");
        }

        Logger::info('productos', 'Producto eliminado', ['id' => $productoId, 'nombre' => $nombre]);

        $archivosEliminados = $this->limpiarImagenesFisicas($productoId);

        return ['nombre' => $nombre, 'archivos_eliminados' => $archivosEliminados];
    }

    private function limpiarImagenesFisicas(int $productoId): int
    {
        $directorios = array_unique([
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/uploads/productos/',
            __DIR__ . '/../../uploads/productos/',
        ]);

        $eliminados = 0;
        foreach ($directorios as $directorio) {
            if (!is_dir($directorio)) {
                continue;
            }
            foreach (glob($directorio . "producto_{$productoId}_*.*") ?: [] as $archivo) {
                if (is_file($archivo) && @unlink($archivo)) {
                    $eliminados++;
                }
            }
        }

        return $eliminados;
    }

    /**
     * Antes: ~230 líneas inline en ajax_productos.php. La tabla de
     * gestión de productos paginada, con imágenes/precios de
     * mayoreo/stock por sucursal de cada producto de la página (por
     * lote, no uno por uno), más estadísticas y valor de inventario.
     *
     * @param array{busqueda?:string, categoria_id?:int, proveedor_id?:int, sucursal_id?:int, mostrar_inactivos?:bool} $filtros
     */
    public function listarParaGestion(array $filtros, int $pagina, int $porPagina, float $stockMinimoGlobal): array
    {
        $resultado = $this->productos->listarConPaginacion($filtros, $pagina, $porPagina);
        $ids = array_column($resultado['productos'], 'id');

        $imagenes = $this->productos->imagenesPorProductos($ids);
        $mayoreo = $this->productos->preciosMayoreoPorProductos($ids);
        $stockPorSucursal = $this->productos->stockPorSucursalPorProductos($ids);

        $productos = array_map(function (array $producto) use ($imagenes, $mayoreo, $stockPorSucursal) {
            $id = $producto['id'];

            return [
                'id'                  => $producto['id'],
                'codigo'              => $producto['codigo'],
                'nombre'              => $producto['nombre'],
                'descripcion'         => $producto['descripcion'],
                'marca'               => $producto['marca'],
                'precio'              => (float) $producto['precio'],
                'subprecio'           => (float) $producto['subprecio'],
                'descuento'           => (float) $producto['descuento'],
                'costo'               => (float) $producto['costo'],
                'categoria_id'        => $producto['categoria_id'],
                'categoria_nombre'    => $producto['categoria_nombre'],
                'proveedor_id'        => $producto['proveedor_id'],
                'proveedor_nombre'    => $producto['proveedor_nombre'],
                'unidad_medida'       => $producto['unidad_medida'],
                'peso_kg'             => (float) $producto['peso_kg'],
                'permite_fracciones'  => (int) $producto['permite_fracciones'],
                'fecha_caducidad'     => $producto['fecha_caducidad'],
                'activo'              => (int) $producto['activo'],
                'stock_total'         => (float) $producto['stock_total'],
                'sucursales_ids'      => $producto['sucursales_ids'],
                'sucursales_nombres'  => $producto['sucursales_nombres'],
                'tiene_mayoreo'       => !empty($mayoreo[$id]),
                'imagenes'            => $imagenes[$id] ?? [],
                'precios_mayoreo'     => $mayoreo[$id] ?? [],
                'stocks_por_sucursal' => $stockPorSucursal[$id] ?? [],
            ];
        }, $resultado['productos']);

        return [
            'productos'              => $productos,
            'total_registros'        => $resultado['total'],
            'total_paginas'          => $resultado['total_paginas'],
            'pagina_actual'          => $resultado['pagina_actual'],
            'estadisticas'           => $this->productos->estadisticas($stockMinimoGlobal),
            'valor_total_inventario' => $this->productos->valorTotalInventario(),
        ];
    }

    /**
     * Antes: ~200 líneas inline en transferir_stock.php. Misma
     * secuencia: validar, verificar stock suficiente en origen, mover
     * en una transacción, y registrar el movimiento de inventario en
     * ambas sucursales (salida en origen, entrada en destino).
     *
     * @return array{producto: array, cantidad: float, sucursal_origen: array, sucursal_destino: array}
     * @throws RuntimeException validación o stock insuficiente
     */
    public function transferirStock(
        int $productoId,
        int $sucursalOrigenId,
        int $sucursalDestinoId,
        float $cantidad,
        string $observaciones,
        int $usuarioId,
    ): array {
        if ($sucursalOrigenId === $sucursalDestinoId) {
            throw new RuntimeException('No se puede transferir a la misma sucursal');
        }
        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad debe ser mayor a 0');
        }

        $producto = $this->productos->datosBasicos($productoId);
        if (!$producto) {
            throw new RuntimeException('Producto no encontrado o inactivo');
        }

        $stockOrigen = $this->productos->stockEnSucursal($productoId, $sucursalOrigenId);
        if ($stockOrigen === null) {
            throw new RuntimeException('El producto no existe en la sucursal de origen');
        }

        $stockActualOrigen = (float) $stockOrigen['stock'];
        if ($stockActualOrigen < $cantidad) {
            throw new RuntimeException(
                'Stock insuficiente en sucursal de origen. Disponible: '
                . number_format($stockActualOrigen, 2) . ' ' . $producto['unidad_medida']
            );
        }

        $stockDestino = $this->productos->stockEnSucursal($productoId, $sucursalDestinoId);
        $existeEnDestino = $stockDestino !== null;
        $stockActualDestino = $existeEnDestino ? (float) $stockDestino['stock'] : 0.0;

        $this->pdo->beginTransaction();

        try {
            $nuevoStockOrigen = $stockActualOrigen - $cantidad;
            $this->productos->fijarStockSucursal($productoId, $sucursalOrigenId, $nuevoStockOrigen);

            $this->movimientos->registrar([
                'producto_id'       => $productoId,
                'sucursal_id'       => $sucursalOrigenId,
                'tipo'              => 'salida',
                'cantidad'          => (int) $cantidad,
                'cantidad_anterior' => (int) $stockActualOrigen,
                'cantidad_nueva'    => (int) $nuevoStockOrigen,
                'referencia_tipo'   => 'ajuste',
                'observaciones'     => $observaciones,
                'usuario_id'        => $usuarioId,
            ]);

            $nuevoStockDestino = $stockActualDestino + $cantidad;
            if ($existeEnDestino) {
                $this->productos->fijarStockSucursal($productoId, $sucursalDestinoId, $nuevoStockDestino);
            } else {
                $this->productos->crearStockSucursal($productoId, $sucursalDestinoId, $nuevoStockDestino, 5.0);
            }

            $this->movimientos->registrar([
                'producto_id'       => $productoId,
                'sucursal_id'       => $sucursalDestinoId,
                'tipo'              => 'entrada',
                'cantidad'          => (int) $cantidad,
                'cantidad_anterior' => (int) $stockActualDestino,
                'cantidad_nueva'    => (int) $nuevoStockDestino,
                'referencia_tipo'   => 'ajuste',
                'observaciones'     => $observaciones,
                'usuario_id'        => $usuarioId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Error al transferir stock: ' . $e->getMessage());
        }

        $nombreOrigen = $this->sucursales->findActiveById($sucursalOrigenId)['nombre'] ?? 'Desconocida';
        $nombreDestino = $this->sucursales->findActiveById($sucursalDestinoId)['nombre'] ?? 'Desconocida';

        Logger::info('productos', 'Transferencia de stock', [
            'producto_id' => $productoId, 'de' => $sucursalOrigenId, 'a' => $sucursalDestinoId, 'cantidad' => $cantidad,
        ]);

        return [
            'producto'         => $producto,
            'cantidad'         => $cantidad,
            'sucursal_origen'  => ['id' => $sucursalOrigenId, 'nombre' => $nombreOrigen, 'stock_anterior' => $stockActualOrigen, 'stock_nuevo' => $nuevoStockOrigen],
            'sucursal_destino' => ['id' => $sucursalDestinoId, 'nombre' => $nombreDestino, 'stock_anterior' => $stockActualDestino, 'stock_nuevo' => $nuevoStockDestino],
        ];
    }
}
