<?php

namespace App\Repositories;

use App\Contracts\Repositories\VentaRepositoryInterface;
use PDO;

class VentaRepository implements VentaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function totalesPorMetodoPago(int $cajaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT metodo_pago, COUNT(*) as cantidad, SUM(total) as total
             FROM ventas
             WHERE caja_id = ? AND estado = 'completada'
             GROUP BY metodo_pago"
        );
        $stmt->execute([$cajaId]);

        $totales = [];
        foreach ($stmt->fetchAll() as $fila) {
            $totales[$fila['metodo_pago']] = [
                'cantidad' => (int) $fila['cantidad'],
                'total'    => (float) $fila['total'],
            ];
        }

        return $totales;
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ventas
                (codigo_venta, cliente_id, usuario_id, sucursal_id, caja_id, subtotal, descuento, iva, total, metodo_pago, estado, efectivo_recibido, cambio, descripcion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada', ?, ?, ?)"
        );

        $stmt->execute([
            $datos['codigo_venta'],
            $datos['cliente_id'],
            $datos['usuario_id'],
            $datos['sucursal_id'],
            $datos['caja_id'],
            $datos['subtotal'],
            $datos['descuento'],
            $datos['iva'],
            $datos['total'],
            $datos['metodo_pago'],
            $datos['efectivo_recibido'],
            $datos['cambio'],
            $datos['descripcion'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function agregarDetalle(int $ventaId, array $detalle): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio_unitario, subtotal, descuento, total, unidad_medida)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $ventaId,
            $detalle['producto_id'],
            $detalle['cantidad'],
            $detalle['precio_unitario'],
            $detalle['subtotal'],
            $detalle['descuento'],
            $detalle['total'],
            $detalle['unidad_medida'],
        ]);
    }

    public function actualizarFacturapiReceipt(int $ventaId, ?string $receiptId, ?string $url): void
    {
        $stmt = $this->pdo->prepare('UPDATE ventas SET facturapi_receipt_id = ?, urlfacturacion = ? WHERE id = ?');
        $stmt->execute([$receiptId, $url, $ventaId]);
    }
}
