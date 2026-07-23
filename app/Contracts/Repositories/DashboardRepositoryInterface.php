<?php

namespace App\Contracts\Repositories;

interface DashboardRepositoryInterface
{
    /** @return array{total_productos:int, total_clientes:int, total_usuarios:int, ventas_hoy:int, ingresos_hoy:float} */
    public function estadisticasHoy(): array;
}
