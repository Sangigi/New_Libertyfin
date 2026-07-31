<?php

namespace App\Services;

use App\Contracts\Repositories\ClienteRepositoryInterface;
use App\Contracts\Repositories\ProductoRepositoryInterface;
use App\Contracts\Repositories\VentaRepositoryInterface;
use App\Core\Logger;
use Facturapi\Facturapi;
use Throwable;

/**
 * Genera el recibo de Facturapi de una venta ya cobrada. Antes vivía
 * dentro de procesar_pago (~110 líneas). Un fallo aquí NO cancela la
 * venta — el original ya seguía adelante mostrando una advertencia, y
 * esta clase conserva ese comportamiento devolviendo success=false en
 * vez de lanzar una excepción.
 */
class FacturapiReceiptService
{
    private const PAYMENT_FORM_MAP = [
        'efectivo'      => '01',
        'tarjeta'       => '04',
        'transferencia' => '03',
    ];

    public function __construct(
        private readonly ProductoRepositoryInterface $productos,
        private readonly ClienteRepositoryInterface $clientes,
        private readonly VentaRepositoryInterface $ventas,
    ) {
    }

    /**
     * @param array $carrito líneas del carrito ya cobrado
     * @return array{success:bool, receipt_id:?string, invoice_url:?string, error:?string}
     */
    public function generarParaVenta(
        int $ventaId,
        string $apiKey,
        array $carrito,
        string $codigoVenta,
        string $metodoPago,
        ?int $clienteId,
    ): array {
        try {
            $facturapi = new Facturapi($apiKey);

            $items = [];
            foreach ($carrito as $item) {
                $facturapiProductoId = $this->productos->facturapiProductoId((int) $item['id']);
                if (!empty($facturapiProductoId)) {
                    $items[] = [
                        'quantity' => (float) $item['cantidad'],
                        'product'  => $facturapiProductoId,
                    ];
                } else {
                    Logger::warning('facturapi', 'Producto sin facturapi_producto_id', ['producto_id' => $item['id']]);
                }
            }

            if ($items === []) {
                throw new \RuntimeException('No se encontraron productos válidos para Facturapi. Verifica que los productos tengan facturapi_producto_id.');
            }

            $folio = preg_replace('/[^0-9]/', '', $codigoVenta);
            if (empty($folio)) {
                $folio = (string) time();
            }

            $receiptData = [
                'folio_number'  => (int) $folio,
                'payment_form'  => self::PAYMENT_FORM_MAP[$metodoPago] ?? '01',
                'items'         => $items,
            ];

            if ($clienteId) {
                $cliente = $this->clientes->datosParaFacturacion($clienteId);
                if ($cliente) {
                    $receiptData['customer'] = [
                        'legal_name' => $cliente['nombre'],
                        'tax_id'     => $cliente['rfc'] ?? '',
                        'email'      => $cliente['email'] ?? '',
                        'phone'      => $cliente['telefono'] ?? '',
                    ];
                }
            }

            $receipt = $facturapi->Receipts->create($receiptData);
            $receiptId = $receipt->id;
            $invoiceUrl = $receipt->self_invoice_url ?? $receipt->url ?? null;

            $this->ventas->actualizarFacturapiReceipt($ventaId, $receiptId, $invoiceUrl);

            Logger::info('facturapi', 'Recibo creado', ['venta_id' => $ventaId, 'receipt_id' => $receiptId]);

            return ['success' => true, 'receipt_id' => $receiptId, 'invoice_url' => $invoiceUrl, 'error' => null];
        } catch (Throwable $e) {
            Logger::error('facturapi', 'Error al crear recibo', ['venta_id' => $ventaId, 'error' => $e->getMessage()]);

            return ['success' => false, 'receipt_id' => null, 'invoice_url' => null, 'error' => $e->getMessage()];
        }
    }
}
