<?php

namespace App\Repositories;

use App\Contracts\Repositories\CajaRepositoryInterface;
use PDO;

class CajaRepository implements CajaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private const SELECT_CON_NOMBRES = "
        SELECT c.*, u.nombre as usuario_nombre, s.nombre as sucursal_nombre
        FROM caja c
        JOIN usuarios u ON c.usuario_id = u.id
        JOIN sucursales s ON c.sucursal_id = s.id
    ";

    public function abiertaPara(int $usuarioId, int $sucursalId): ?array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_CON_NOMBRES . " WHERE c.usuario_id = ? AND c.sucursal_id = ? AND c.estado = 'abierta'"
        );
        $stmt->execute([$usuarioId, $sucursalId]);

        return $stmt->fetch() ?: null;
    }

    public function encontrarPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_CON_NOMBRES . ' WHERE c.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function crear(int $sucursalId, int $usuarioId, float $montoApertura, string $observaciones): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO caja (sucursal_id, usuario_id, monto_apertura, observaciones, estado)
             VALUES (?, ?, ?, ?, 'abierta')"
        );
        $stmt->execute([$sucursalId, $usuarioId, $montoApertura, $observaciones]);

        return (int) $this->pdo->lastInsertId();
    }

    public function cerrar(int $cajaId, array $datos): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE caja
             SET fecha_cierre = NOW(),
                 monto_cierre = ?,
                 monto_esperado = ?,
                 diferencia = ?,
                 ventas_efectivo = ?,
                 ventas_tarjeta = ?,
                 ventas_transferencia = ?,
                 total_ventas = ?,
                 otros_ingresos = ?,
                 otros_egresos = ?,
                 observaciones = ?,
                 estado = 'cerrada'
             WHERE id = ?"
        );

        return $stmt->execute([
            $datos['monto_cierre'],
            $datos['monto_esperado'],
            $datos['diferencia'],
            $datos['ventas_efectivo'],
            $datos['ventas_tarjeta'],
            $datos['ventas_transferencia'],
            $datos['total_ventas'],
            $datos['otros_ingresos'],
            $datos['otros_egresos'],
            $datos['observaciones'],
            $cajaId,
        ]);
    }

    public function historialFiltrado(int $sucursalId, array $filtros): array
    {
        $sql = self::SELECT_CON_NOMBRES . ' WHERE c.sucursal_id = ?';
        $params = [$sucursalId];

        if (!empty($filtros['fecha_desde'])) {
            $sql .= ' AND DATE(c.fecha_apertura) >= ?';
            $params[] = $filtros['fecha_desde'];
        }

        if (!empty($filtros['fecha_hasta'])) {
            $sql .= ' AND DATE(c.fecha_apertura) <= ?';
            $params[] = $filtros['fecha_hasta'];
        }

        if (!empty($filtros['usuario_id'])) {
            $sql .= ' AND c.usuario_id = ?';
            $params[] = $filtros['usuario_id'];
        }

        if (!empty($filtros['estado'])) {
            $sql .= ' AND c.estado = ?';
            $params[] = $filtros['estado'];
        }

        $sql .= ' ORDER BY c.fecha_apertura DESC LIMIT 50';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function abiertaPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_CON_NOMBRES . " WHERE c.id = ? AND c.estado = 'abierta'");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function registrarVenta(int $cajaId, float $total, string $metodoPago): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE caja SET
                total_ventas = COALESCE(total_ventas, 0) + ?,
                ventas_efectivo = COALESCE(ventas_efectivo, 0) + ?,
                ventas_tarjeta = COALESCE(ventas_tarjeta, 0) + ?,
                ventas_transferencia = COALESCE(ventas_transferencia, 0) + ?
             WHERE id = ?'
        );

        $stmt->execute([
            $total,
            $metodoPago === 'efectivo' ? $total : 0,
            $metodoPago === 'tarjeta' ? $total : 0,
            $metodoPago === 'transferencia' ? $total : 0,
            $cajaId,
        ]);
    }
}
