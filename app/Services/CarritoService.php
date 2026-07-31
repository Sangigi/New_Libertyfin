<?php

namespace App\Services;

use App\Contracts\Repositories\ClienteRepositoryInterface;
use App\Contracts\Repositories\ProductoRepositoryInterface;
use RuntimeException;

/**
 * El carrito vive en $_SESSION['carrito'] — no hay tabla de BD detrás,
 * así que no hay (ni hace falta) un "CarritoRepository": esta clase
 * opera directo sobre la sesión y usa ProductoRepository/
 * ClienteRepository solo para las validaciones que sí requieren datos
 * de la base de datos (stock, precio de mayoreo, etc.).
 *
 * Reemplaza los 8 manejadores `isset($_POST['xxx_ajax'])` que antes
 * vivían inline en caja.php.
 */
class CarritoService
{
    private const UNIDADES_DECIMALES = [
        'kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
        'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
        'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros',
    ];

    private const UNIDADES_ENTERAS = ['pieza', 'piezas', 'unidad', 'unidades', 'pza', 'pzas'];

    public function __construct(
        private readonly ProductoRepositoryInterface $productos,
        private readonly ClienteRepositoryInterface $clientes,
    ) {
    }

    /** @return array{success:bool, message:string, carrito_actualizado:array, totales:array} */
    public function agregarProducto(int $productoId, float $cantidad, int $sucursalId): array
    {
        if ($productoId <= 0) {
            throw new RuntimeException('ID de producto no válido');
        }
        if ($cantidad <= 0) {
            throw new RuntimeException('La cantidad debe ser mayor a 0');
        }

        $producto = $this->productos->conPrecioCalculado($productoId, $cantidad, $sucursalId);
        if (!$producto) {
            throw new RuntimeException('Producto no encontrado o inactivo');
        }

        $rutaImagen = $this->productos->imagenPath($producto['id']);
        $stockDisponible = (float) ($producto['stock_sucursal'] ?? 0);
        $descuentoPorcentaje = (float) ($producto['descuento'] ?? 0);
        $precioUnitario = (float) ($producto['precio_calculado'] ?? $producto['precio_sin_iva']);
        $precioBase = (float) ($producto['precio_base'] ?? $producto['precio_sin_iva']);
        $tieneMayoreo = $producto['tiene_precio_mayoreo'] ?? false;

        $permiteDecimales = $this->permiteDecimales($producto['unidad_medida'], (int) $producto['permite_fracciones']);

        if ($permiteDecimales) {
            $cantidad = (float) $cantidad;
        } else {
            $cantidad = (int) $cantidad;
            $enCarrito = 0;
            foreach ($this->carrito() as $item) {
                if ($item['id'] == $productoId) {
                    $enCarrito += (int) $item['cantidad'];
                }
            }
            if ($stockDisponible < $enCarrito + $cantidad) {
                throw new RuntimeException("Stock insuficiente. Disponible: {$stockDisponible}");
            }
        }

        $index = $this->buscarEnCarrito($productoId);

        if ($index !== null) {
            $nuevaCantidad = (float) $_SESSION['carrito'][$index]['cantidad'] + $cantidad;
            $precioMayoreo = $this->productos->precioConMayoreo($productoId, $nuevaCantidad);

            $_SESSION['carrito'][$index]['cantidad'] = $nuevaCantidad;
            $_SESSION['carrito'][$index]['precio'] = $precioMayoreo;
            $_SESSION['carrito'][$index]['precio_base'] = $precioBase;
            $_SESSION['carrito'][$index]['costo'] = (float) ($producto['costo'] ?? 0);
            $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = $precioMayoreo < $precioBase;

            $this->recalcularSubtotal($index, $descuentoPorcentaje);
            $_SESSION['carrito'][$index]['imagen_ruta'] = $rutaImagen;

            $mensaje = 'Producto actualizado: ' . $producto['nombre'] . ($precioMayoreo < $precioBase ? ' (Precio mayoreo aplicado)' : '');
        } else {
            $subtotal = $precioUnitario * $cantidad;
            $descuentoTotal = $descuentoPorcentaje > 0 ? $subtotal * ($descuentoPorcentaje / 100) : 0;

            $_SESSION['carrito'][] = [
                'id'                     => $producto['id'],
                'codigo'                 => $producto['codigo'],
                'nombre'                 => $producto['nombre'],
                'precio'                 => $precioUnitario,
                'precio_base'            => $precioBase,
                'precio_sin_iva'         => $precioUnitario,
                'precio_original'        => $precioBase,
                'costo'                  => (float) ($producto['costo'] ?? 0),
                'tiene_precio_mayoreo'   => $tieneMayoreo,
                'cantidad'               => $permiteDecimales ? (float) $cantidad : (int) $cantidad,
                'subtotal'               => (float) $subtotal,
                'descuento'              => (float) $descuentoTotal,
                'descuento_porcentaje'   => (float) $descuentoPorcentaje,
                'subtotal_con_descuento' => (float) ($subtotal - $descuentoTotal),
                'tipo_venta'             => $permiteDecimales ? $producto['unidad_medida'] : 'unidad',
                'unidad_medida'          => $producto['unidad_medida'],
                'peso_kg'                => $producto['peso_kg'],
                'permite_fracciones'     => $permiteDecimales ? 1 : 0,
                'imagen'                 => $producto['imagen'],
                'imagen_ruta'            => $rutaImagen,
            ];

            $mensaje = 'Producto agregado: ' . $producto['nombre'] . ($tieneMayoreo ? ' (Precio mayoreo aplicado)' : '');
        }

        return $this->respuesta(true, $mensaje);
    }

    public function actualizarCantidad(int $index, mixed $cantidadCruda, int $sucursalId): array
    {
        $this->requerirEnCarrito($index);

        $productoId = (int) $_SESSION['carrito'][$index]['id'];
        $permiteDecimales = $_SESSION['carrito'][$index]['permite_fracciones'] == 1;

        $cantidad = $permiteDecimales ? (float) $cantidadCruda : (int) $cantidadCruda;
        if ($cantidad <= 0) {
            throw new RuntimeException($permiteDecimales
                ? 'La cantidad debe ser mayor a 0'
                : 'La cantidad debe ser un número entero mayor a 0');
        }

        $info = $this->productos->stockYPrecioBase($productoId, $sucursalId);
        $stockActual = (float) ($info['stock_sucursal'] ?? 0);
        $nombreProducto = $info['nombre'] ?? 'Producto';
        $descuentoPorcentaje = (float) ($info['descuento'] ?? 0);
        $precioBase = (float) ($info['precio_base'] ?? 0);

        if (!$permiteDecimales && $cantidad > $stockActual) {
            throw new RuntimeException("Stock insuficiente para: {$nombreProducto} (Stock: {$stockActual})");
        }

        $_SESSION['carrito'][$index]['cantidad'] = $cantidad;

        // Recalcular precio según mayoreo (solo si no se editó manualmente el precio).
        $precioActual = floatval($_SESSION['carrito'][$index]['precio']);
        $precioMayoreo = $this->productos->precioConMayoreo($productoId, $cantidad);
        $precioBaseOriginal = floatval($_SESSION['carrito'][$index]['precio_original'] ?? $precioBase);

        if ($precioActual == $precioBaseOriginal || $precioActual == $precioBase) {
            $precioUnitario = $precioMayoreo;
            $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = $precioUnitario < $precioBase;
            $_SESSION['carrito'][$index]['precio_base'] = $precioBase;
            $_SESSION['carrito'][$index]['precio_original'] = $precioBase;
        } else {
            $precioUnitario = $precioActual;
        }

        $_SESSION['carrito'][$index]['precio'] = $precioUnitario;
        $_SESSION['carrito'][$index]['precio_sin_iva'] = $precioUnitario;

        $this->recalcularSubtotal($index, $descuentoPorcentaje);

        $mensajeMayoreo = ($_SESSION['carrito'][$index]['tiene_precio_mayoreo'] ?? false) ? ' (Precio mayoreo aplicado)' : '';

        return $this->respuesta(true, "Cantidad actualizada: {$nombreProducto}{$mensajeMayoreo}");
    }

    public function actualizarPrecio(int $index, float $nuevoPrecio): array
    {
        $this->requerirEnCarrito($index);

        if ($nuevoPrecio <= 0) {
            throw new RuntimeException('El precio debe ser mayor a 0');
        }

        $_SESSION['carrito'][$index]['precio'] = $nuevoPrecio;
        $_SESSION['carrito'][$index]['precio_base'] = $nuevoPrecio;
        $_SESSION['carrito'][$index]['precio_sin_iva'] = $nuevoPrecio;
        $_SESSION['carrito'][$index]['precio_original'] = $nuevoPrecio;
        $_SESSION['carrito'][$index]['tiene_precio_mayoreo'] = false;

        $descuentoPorcentaje = floatval($_SESSION['carrito'][$index]['descuento_porcentaje'] ?? 0);
        $this->recalcularSubtotal($index, $descuentoPorcentaje);

        return $this->respuesta(true, 'Precio unitario actualizado a $' . number_format($nuevoPrecio, 2));
    }

    /** También persiste el % en productos.descuento — es una propiedad del producto, no solo de esta venta. */
    public function actualizarDescuento(int $index, int $productoId, float $descuentoPorcentaje): array
    {
        $this->requerirEnCarrito($index);

        $descuentoPorcentaje = max(0, min(100, $descuentoPorcentaje));

        $this->productos->actualizarDescuento($productoId, $descuentoPorcentaje);
        $this->recalcularSubtotal($index, $descuentoPorcentaje, guardarPorcentaje: true);

        return $this->respuesta(true, "Descuento actualizado a {$descuentoPorcentaje}%");
    }

    public function actualizarComisiones(int $index, array $comisiones): void
    {
        $this->requerirEnCarrito($index);
        $_SESSION['carrito'][$index]['comisiones'] = $comisiones;
    }

    public function eliminarProducto(int $index): array
    {
        if (!isset($_SESSION['carrito']) || !array_key_exists($index, $_SESSION['carrito'])) {
            return $this->respuesta(false, 'Producto no encontrado en el carrito');
        }

        $nombre = $_SESSION['carrito'][$index]['nombre'];
        array_splice($_SESSION['carrito'], $index, 1);

        return $this->respuesta(true, "Producto eliminado: {$nombre}");
    }

    public function vaciar(): array
    {
        if (empty($_SESSION['carrito'])) {
            return $this->respuesta(false, 'El carrito ya está vacío');
        }

        $_SESSION['carrito'] = [];

        return $this->respuesta(true, 'Carrito vaciado exitosamente');
    }

    /** @return array{success:bool, message:string, cliente_id:?int, cliente_nombre?:string} */
    public function actualizarCliente(?int $clienteId): array
    {
        if ($clienteId === null) {
            unset($_SESSION['cliente_venta']);
            return ['success' => true, 'message' => 'Cliente cambiado a Cliente General', 'cliente_id' => null];
        }

        $cliente = $this->clientes->encontrarActivoPorId($clienteId);
        if (!$cliente) {
            return ['success' => false, 'message' => 'Cliente no encontrado', 'cliente_id' => $clienteId];
        }

        $_SESSION['cliente_venta'] = $clienteId;

        return [
            'success'        => true,
            'message'        => 'Cliente seleccionado: ' . htmlspecialchars($cliente['nombre']),
            'cliente_id'     => $clienteId,
            'cliente_nombre' => htmlspecialchars($cliente['nombre']),
        ];
    }

    // -----------------------------------------------------------------

    private function carrito(): array
    {
        return $_SESSION['carrito'] ?? [];
    }

    private function buscarEnCarrito(int $productoId): ?int
    {
        foreach ($this->carrito() as $i => $item) {
            if ($item['id'] == $productoId) {
                return $i;
            }
        }

        return null;
    }

    private function requerirEnCarrito(int $index): void
    {
        if ($index < 0 || !isset($_SESSION['carrito'][$index])) {
            throw new RuntimeException('Producto no encontrado en el carrito');
        }
    }

    private function permiteDecimales(string $unidadMedida, int $permiteFracciones): bool
    {
        $unidad = strtolower(trim($unidadMedida));

        if (in_array($unidad, self::UNIDADES_ENTERAS, true)) {
            return false;
        }

        return $permiteFracciones === 1 || in_array($unidad, self::UNIDADES_DECIMALES, true);
    }

    private function recalcularSubtotal(int $index, float $descuentoPorcentaje, bool $guardarPorcentaje = false): void
    {
        $item = &$_SESSION['carrito'][$index];
        $subtotal = (float) $item['cantidad'] * (float) $item['precio'];
        $item['subtotal'] = $subtotal;

        if ($guardarPorcentaje) {
            $item['descuento_porcentaje'] = $descuentoPorcentaje;
        }

        if ($descuentoPorcentaje > 0) {
            $descuentoTotal = $subtotal * ($descuentoPorcentaje / 100);
            $item['descuento'] = $descuentoTotal;
            $item['subtotal_con_descuento'] = $subtotal - $descuentoTotal;
        } else {
            $item['descuento'] = 0;
            $item['subtotal_con_descuento'] = $subtotal;
        }
        unset($item);
    }

    /** Misma agregación de subtotal/descuento/subtotal_con_descuento que se repetía en cada manejador. */
    public function calcularTotales(): array
    {
        $subtotal = 0;
        $descuento = 0;
        $subtotalConDescuento = 0;

        foreach ($this->carrito() as $item) {
            $subtotal += (float) $item['subtotal'];
            $descuento += (float) ($item['descuento'] ?? 0);
            $subtotalConDescuento += (float) ($item['subtotal_con_descuento'] ?? $item['subtotal']);
        }

        return [
            'subtotal'               => $subtotal,
            'descuento'              => $descuento,
            'subtotal_con_descuento' => $subtotalConDescuento,
            'iva'                    => 0,
            'total'                  => $subtotalConDescuento,
        ];
    }

    private function respuesta(bool $success, string $message): array
    {
        return [
            'success'             => $success,
            'message'             => $message,
            'carrito_actualizado' => $this->carrito(),
            'totales'             => $this->calcularTotales(),
        ];
    }
}
