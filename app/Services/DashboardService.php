<?php

namespace App\Services;

use App\Contracts\Repositories\CajaRepositoryInterface;
use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Repositories\EmpresaRepositoryInterface;
use App\Contracts\Repositories\SistemaConfigRepositoryInterface;
use App\Support\LogoResolver;

/**
 * Reúne todo lo que antes se armaba inline en dashboard.php: config
 * visual de la empresa + logo, estadísticas del día, plan/timbres, y
 * estado de caja actual.
 */
class DashboardService
{
    public function __construct(
        private readonly SistemaConfigRepositoryInterface $sistemaConfig,
        private readonly DashboardRepositoryInterface $dashboard,
        private readonly CajaRepositoryInterface $caja,
        private readonly EmpresaRepositoryInterface $empresas,
    ) {
    }

    /**
     * @return array{
     *   empresa_info: array|null,
     *   logo_empresa: ?string,
     *   logo_src_base64: ?string,
     *   estadisticas: array,
     *   empresa_plan: string,
     *   timbres_totales: int,
     *   timbres_disponibles: int,
     *   caja_actual: array|null,
     * }
     */
    public function resumenPara(int $empresaId, int $usuarioId, int $sucursalId): array
    {
        $empresaInfo = $this->sistemaConfig->actual();
        $logo = LogoResolver::resolver($empresaInfo['logo'] ?? null);

        $planInfo = $this->empresas->findPlanInfo($empresaId);

        return [
            'empresa_info'        => $empresaInfo,
            'logo_empresa'        => $logo['path'],
            'logo_src_base64'     => $logo['base64'],
            'estadisticas'        => $this->dashboard->estadisticasHoy(),
            'empresa_plan'        => $planInfo['plan'] ?? 'prueba',
            'timbres_totales'     => (int) ($planInfo['timbres_totales'] ?? 0),
            'timbres_disponibles' => (int) ($planInfo['timbres_disponibles'] ?? 0),
            'caja_actual'         => $this->caja->abiertaPara($usuarioId, $sucursalId),
        ];
    }
}
