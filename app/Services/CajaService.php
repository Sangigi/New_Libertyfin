<?php

namespace App\Services;

use App\Contracts\Repositories\CajaRepositoryInterface;
use App\Contracts\Repositories\MovimientoCajaRepositoryInterface;
use App\Contracts\Repositories\VentaRepositoryInterface;
use App\Core\Logger;
use App\Services\Exceptions\CajaNoAbiertaException;
use App\Services\Exceptions\CajaYaAbiertaException;
use RuntimeException;

class CajaService
{
    public function __construct(
        private readonly CajaRepositoryInterface $caja,
        private readonly VentaRepositoryInterface $ventas,
        private readonly MovimientoCajaRepositoryInterface $movimientos,
    ) {
    }

    /**
     * Antes: caja_apertura.php validaba e insertaba directo. Misma
     * regla — monto >= 0 y que no haya ya una caja abierta.
     *
     * @return array{id: int}
     * @throws CajaYaAbiertaException|RuntimeException
     */
    public function abrir(int $sucursalId, int $usuarioId, float $montoApertura, string $observaciones): array
    {
        if ($montoApertura < 0) {
            throw new RuntimeException('El monto de apertura debe ser mayor o igual a 0');
        }

        if ($this->caja->abiertaPara($usuarioId, $sucursalId) !== null) {
            throw new CajaYaAbiertaException('Caja ya está abierta');
        }

        $id = $this->caja->crear($sucursalId, $usuarioId, $montoApertura, $observaciones);

        Logger::info('caja', 'Caja abierta', [
            'caja_id'   => $id,
            'usuario'   => $usuarioId,
            'sucursal'  => $sucursalId,
            'monto'     => $montoApertura,
        ]);

        return ['id' => $id];
    }

    /**
     * Antes: el bloque de cálculos al inicio de caja_cierre.php (ventas
     * por método de pago + otros movimientos + fórmula de monto
     * esperado). Se usa tanto para mostrar el formulario (GET) como
     * base para procesar el cierre (POST).
     *
     * @return array{
     *   caja: array,
     *   ventas_efectivo: float, ventas_tarjeta: float, ventas_transferencia: float,
     *   total_ventas: float, total_cantidad: int,
     *   otros_ingresos: float, otros_egresos: float,
     *   monto_esperado: float,
     * }
     * @throws CajaNoAbiertaException
     */
    public function calcularResumenCierre(int $usuarioId, int $sucursalId): array
    {
        $caja = $this->caja->abiertaPara($usuarioId, $sucursalId);

        if ($caja === null) {
            throw new CajaNoAbiertaException('No hay caja abierta para cerrar. Primero debe abrir una caja.');
        }

        $cajaId = (int) $caja['id'];

        $porMetodo = $this->ventas->totalesPorMetodoPago($cajaId);
        $ventasEfectivo = $porMetodo['efectivo']['total'] ?? 0.0;
        $ventasTarjeta = $porMetodo['tarjeta']['total'] ?? 0.0;
        $ventasTransferencia = $porMetodo['transferencia']['total'] ?? 0.0;
        $totalVentas = array_sum(array_column($porMetodo, 'total'));
        $totalCantidad = array_sum(array_column($porMetodo, 'cantidad'));

        $otros = $this->movimientos->totalesPorTipoExcluyendoVentas($cajaId);
        $otrosIngresos = $otros['ingreso'];
        $otrosEgresos = $otros['egreso'];

        // Monto esperado = (Apertura + Ventas en Efectivo + Otros Ingresos) - Otros Egresos
        $montoEsperado = (float) $caja['monto_apertura'] + ($ventasEfectivo + $otrosIngresos) - $otrosEgresos;

        Logger::info('caja', 'Cálculo de cierre', [
            'caja_id'         => $cajaId,
            'monto_apertura'  => $caja['monto_apertura'],
            'ventas_efectivo' => $ventasEfectivo,
            'otros_ingresos'  => $otrosIngresos,
            'otros_egresos'   => $otrosEgresos,
            'monto_esperado'  => $montoEsperado,
        ]);

        return [
            'caja'                 => $caja,
            'ventas_efectivo'      => $ventasEfectivo,
            'ventas_tarjeta'       => $ventasTarjeta,
            'ventas_transferencia' => $ventasTransferencia,
            'total_ventas'         => $totalVentas,
            'total_cantidad'       => $totalCantidad,
            'otros_ingresos'       => $otrosIngresos,
            'otros_egresos'        => $otrosEgresos,
            'monto_esperado'       => $montoEsperado,
        ];
    }

    /**
     * @param array $resumen resultado de calcularResumenCierre()
     * @return array{diferencia: float}
     */
    public function cerrar(int $cajaId, float $montoCierre, string $observaciones, array $resumen): array
    {
        $diferencia = $montoCierre - $resumen['monto_esperado'];

        $ok = $this->caja->cerrar($cajaId, [
            'monto_cierre'         => $montoCierre,
            'monto_esperado'       => $resumen['monto_esperado'],
            'diferencia'           => $diferencia,
            'ventas_efectivo'      => $resumen['ventas_efectivo'],
            'ventas_tarjeta'       => $resumen['ventas_tarjeta'],
            'ventas_transferencia' => $resumen['ventas_transferencia'],
            'total_ventas'         => $resumen['total_ventas'],
            'otros_ingresos'       => $resumen['otros_ingresos'],
            'otros_egresos'        => $resumen['otros_egresos'],
            'observaciones'        => $observaciones,
        ]);

        if (!$ok) {
            Logger::error('caja', 'Error al cerrar caja', ['caja_id' => $cajaId]);
            throw new RuntimeException('Error al cerrar la caja.');
        }

        Logger::info('caja', 'Caja cerrada', ['caja_id' => $cajaId, 'diferencia' => $diferencia]);

        return ['diferencia' => $diferencia];
    }

    /** @return array{caja: array, movimientos: array}|null */
    public function detalleConMovimientos(int $cajaId): ?array
    {
        $caja = $this->caja->encontrarPorId($cajaId);

        if ($caja === null) {
            return null;
        }

        return [
            'caja'        => $caja,
            'movimientos' => $this->movimientos->porCaja($cajaId),
        ];
    }

    /**
     * Antes: "verificación de caja abierta" en caja.php — primero
     * intenta el ID cacheado en sesión (evita una consulta si ya se
     * sabe cuál es); si no sirve, busca por usuario/sucursal. Null si
     * no hay ninguna — el controlador decide qué hacer (antes:
     * redirigir a caja_apertura.php).
     */
    public function resolverActivaParaSesion(?int $cajaIdSesion, int $usuarioId, int $sucursalId): ?array
    {
        if ($cajaIdSesion) {
            $caja = $this->caja->abiertaPorId($cajaIdSesion);
            if ($caja !== null) {
                return $caja;
            }
        }

        return $this->caja->abiertaPara($usuarioId, $sucursalId);
    }

    /**
     * Antes: el bucle al final de la sección de conexión en
     * caja_historial.php, contando cajas abiertas sobre el resultado ya
     * traído. Misma lógica.
     *
     * @param array{fecha_desde?:string, fecha_hasta?:string, usuario_id?:int, estado?:string} $filtros
     * @return array{cajas: array, cajas_abiertas: int, mi_caja_abierta: bool}
     */
    public function historial(int $sucursalId, int $usuarioIdActual, array $filtros): array
    {
        $cajas = $this->caja->historialFiltrado($sucursalId, $filtros);

        $cajasAbiertas = 0;
        $miCajaAbierta = false;

        foreach ($cajas as $caja) {
            if ($caja['estado'] === 'abierta') {
                $cajasAbiertas++;
                if ((int) $caja['usuario_id'] === $usuarioIdActual) {
                    $miCajaAbierta = true;
                }
            }
        }

        return [
            'cajas'           => $cajas,
            'cajas_abiertas'  => $cajasAbiertas,
            'mi_caja_abierta' => $miCajaAbierta,
        ];
    }
}
