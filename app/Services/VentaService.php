<?php

namespace App\Services;

use App\Contracts\Repositories\CajaRepositoryInterface;
use App\Contracts\Repositories\GastoRepositoryInterface;
use App\Contracts\Repositories\ProductoRepositoryInterface;
use App\Contracts\Repositories\VentaRepositoryInterface;
use App\Core\Logger;
use PDO;
use RuntimeException;
use Throwable;

/**
 * El flujo transaccional completo de "cobrar" — antes vivía inline en
 * el bloque `if (isset($_POST['procesar_pago']))` de caja.php (~360
 * líneas). Misma lógica y mismos mensajes de validación.
 *
 * Nota heredada del original: no se revalida el stock disponible
 * antes de descontarlo aquí (el código original tenía un comentario
 * "// [Mantén tu código de validación de stock aquí]" sin completar).
 * Se conserva ese comportamiento tal cual — no se agregó una
 * validación que no estaba, para no introducir un cambio de negocio
 * no solicitado. Vale la pena revisarlo aparte.
 */
class VentaService
{
    private const METODOS_VALIDOS = ['efectivo', 'tarjeta', 'transferencia'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly VentaRepositoryInterface $ventas,
        private readonly ProductoRepositoryInterface $productos,
        private readonly CajaRepositoryInterface $caja,
        private readonly GastoRepositoryInterface $gastos,
        private readonly FacturapiReceiptService $facturapiReceipts,
    ) {
    }

    /**
     * @param array $carrito líneas de $_SESSION['carrito']
     * @return array{venta_id:int, codigo_venta:string, total:float, subtotal:float, descuento:float,
     *               iva:float, facturapi_receipt_id:?string, facturapi_invoice_url:?string,
     *               warning:?string}
     * @throws RuntimeException validación (carrito vacío, método inválido, efectivo insuficiente)
     */
    public function procesar(
        array $carrito,
        string $metodoPago,
        float $efectivoRecibido,
        float $cambio,
        float $descuentoTotalPost,
        ?string $descripcion,
        int $cajaId,
        int $usuarioId,
        int $sucursalId,
        ?int $clienteId,
        string $empresaPlan,
        ?string $facturapiApiKey,
    ): array {
        if (empty($carrito)) {
            throw new RuntimeException('El carrito está vacío');
        }

        if (!in_array($metodoPago, self::METODOS_VALIDOS, true)) {
            throw new RuntimeException('Método de pago no válido');
        }

        $descripcion = trim((string) $descripcion);
        $descripcion = $descripcion === '' ? null : mb_substr($descripcion, 0, 500);

        $subtotalSinDescuento = 0.0;
        $descuentoCarrito = 0.0;
        foreach ($carrito as $item) {
            $subtotalSinDescuento += (float) $item['subtotal'];
            $descuentoCarrito += (float) ($item['descuento'] ?? 0);
        }

        $descuentoTotal = $descuentoTotalPost == 0.0 ? $descuentoCarrito : $descuentoTotalPost;

        $subtotalSinIva = max(0.0, $subtotalSinDescuento - $descuentoTotal);
        $ivaTotal = 0.0;
        $total = $subtotalSinIva;

        if ($metodoPago === 'efectivo' && $efectivoRecibido < $total) {
            throw new RuntimeException('El efectivo recibido es menor al total a pagar');
        }

        $this->pdo->beginTransaction();

        try {
            $codigoVenta = date('YmdHis');

            $ventaId = $this->ventas->crear([
                'codigo_venta'      => $codigoVenta,
                'cliente_id'        => $clienteId,
                'usuario_id'        => $usuarioId,
                'sucursal_id'       => $sucursalId,
                'caja_id'           => $cajaId,
                'subtotal'          => $subtotalSinDescuento,
                'descuento'         => $descuentoTotal,
                'iva'               => $ivaTotal,
                'total'             => $total,
                'metodo_pago'       => $metodoPago,
                'efectivo_recibido' => $efectivoRecibido,
                'cambio'            => $cambio,
                'descripcion'       => $descripcion,
            ]);

            $costoTotalVenta = 0.0;

            foreach ($carrito as $item) {
                $permiteDecimales = $item['permite_fracciones'] == 1;
                $cantidad = $permiteDecimales ? (float) $item['cantidad'] : (int) $item['cantidad'];
                $costoTotalVenta += (float) ($item['costo'] ?? 0) * (float) $item['cantidad'];

                $this->ventas->agregarDetalle($ventaId, [
                    'producto_id'      => $item['id'],
                    'cantidad'         => $cantidad,
                    'precio_unitario'  => $item['precio'],
                    'subtotal'         => $item['subtotal'],
                    'descuento'        => $item['descuento'] ?? 0,
                    'total'            => $item['subtotal_con_descuento'] ?? $item['subtotal'],
                    'unidad_medida'    => $item['unidad_medida'] ?? 'unidad',
                ]);

                $this->productos->descontarStock((int) $item['id'], $sucursalId, $cantidad);
            }

            $this->caja->registrarVenta($cajaId, $total, $metodoPago);

            if ($costoTotalVenta > 0) {
                $this->gastos->registrarCostoVenta([
                    'concepto'    => "Costo de mercancía - Venta #{$codigoVenta}",
                    'monto'       => $costoTotalVenta,
                    'venta_id'    => $ventaId,
                    'usuario_id'  => $usuarioId,
                    'sucursal_id' => $sucursalId,
                    'metodo_pago' => $metodoPago,
                    'descripcion' => "Costo generado automáticamente al concretar la venta {$codigoVenta}",
                ]);
            }

            $facturapiReceiptId = null;
            $facturapiInvoiceUrl = null;
            $warning = null;

            if ($empresaPlan === 'premium') {
                if (empty($facturapiApiKey)) {
                    $warning = 'Venta realizada, pero no se pudo generar el recibo electrónico: no se encontró API Key de Facturapi';
                } else {
                    $resultado = $this->facturapiReceipts->generarParaVenta(
                        $ventaId,
                        $facturapiApiKey,
                        $carrito,
                        $codigoVenta,
                        $metodoPago,
                        $clienteId
                    );

                    if ($resultado['success']) {
                        $facturapiReceiptId = $resultado['receipt_id'];
                        $facturapiInvoiceUrl = $resultado['invoice_url'];
                    } else {
                        // No cancelamos la venta, solo advertimos — igual que el original.
                        $warning = 'Venta realizada, pero no se pudo generar el recibo electrónico: ' . $resultado['error'];
                    }
                }
            }

            $this->pdo->commit();

            Logger::info('ventas', 'Venta procesada', ['venta_id' => $ventaId, 'codigo_venta' => $codigoVenta, 'total' => $total]);

            return [
                'venta_id'              => $ventaId,
                'codigo_venta'          => $codigoVenta,
                'total'                 => $total,
                'subtotal'              => $subtotalSinDescuento,
                'descuento'             => $descuentoTotal,
                'iva'                   => $ivaTotal,
                'facturapi_receipt_id'  => $facturapiReceiptId,
                'facturapi_invoice_url' => $facturapiInvoiceUrl,
                'warning'               => $warning,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            Logger::error('ventas', 'Error al procesar venta', ['error' => $e->getMessage()]);
            throw new RuntimeException('Error al procesar la venta: ' . $e->getMessage());
        }
    }
}
