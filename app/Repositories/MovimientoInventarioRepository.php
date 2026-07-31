<?php

namespace App\Repositories;

use App\Contracts\Repositories\MovimientoInventarioRepositoryInterface;
use PDO;

class MovimientoInventarioRepository implements MovimientoInventarioRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(array $datos): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO movimientos_inventario
                (producto_id, sucursal_id, tipo, cantidad, cantidad_anterior, cantidad_nueva, referencia_tipo, observaciones, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $datos['producto_id'],
            $datos['sucursal_id'],
            $datos['tipo'],
            $datos['cantidad'],
            $datos['cantidad_anterior'],
            $datos['cantidad_nueva'],
            $datos['referencia_tipo'],
            $datos['observaciones'],
            $datos['usuario_id'],
        ]);
    }

    public function filtrados(string $fechaInicio, string $fechaFin, ?int $sucursalId, ?int $productoId): array
    {
        $sql = "SELECT
                DATE_FORMAT(mi.fecha, '%d/%m/%Y %H:%i') as fecha_hora,
                p.codigo as producto_codigo,
                p.nombre as producto_nombre,
                s.nombre as sucursal_nombre,
                CASE mi.tipo
                    WHEN 'entrada' THEN 'ENTRADA'
                    WHEN 'salida' THEN 'SALIDA'
                    WHEN 'ajuste' THEN 'AJUSTE'
                    WHEN 'transferencia_entrada' THEN 'TRANSFERENCIA ENTRADA'
                    WHEN 'transferencia_salida' THEN 'TRANSFERENCIA SALIDA'
                    ELSE UPPER(mi.tipo)
                END as tipo_movimiento,
                mi.cantidad, mi.cantidad_anterior, mi.cantidad_nueva,
                u.nombre as usuario_nombre, mi.observaciones
             FROM movimientos_inventario mi
             LEFT JOIN productos p ON mi.producto_id = p.id
             LEFT JOIN sucursales s ON mi.sucursal_id = s.id
             LEFT JOIN usuarios u ON mi.usuario_id = u.id
             WHERE DATE(mi.fecha) BETWEEN ? AND ?";

        $params = [$fechaInicio, $fechaFin];

        if ($sucursalId) {
            $sql .= ' AND mi.sucursal_id = ?';
            $params[] = $sucursalId;
        }

        if ($productoId) {
            $sql .= ' AND mi.producto_id = ?';
            $params[] = $productoId;
        }

        $sql .= ' ORDER BY mi.fecha DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
