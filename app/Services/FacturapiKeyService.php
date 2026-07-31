<?php

namespace App\Services;

use App\Config\Config;
use App\Contracts\Repositories\SistemaConfigRepositoryInterface;
use App\Core\Logger;
use Facturapi\Facturapi;
use Throwable;

/**
 * Obtiene la API key de PRUEBA de Facturapi para la organización de la
 * empresa (solo aplica a plan premium). Antes esto vivía inline en
 * caja.php (~100 líneas). Misma cadena de respaldo que tenía: pedirla
 * a Facturapi -> caché en sistema_config -> key fija de último recurso.
 *
 * La key MAESTRA (con permisos sobre organizaciones de Facturapi, no
 * solo esta empresa) ya NO está hardcodeada — viene de
 * FACTURAPI_MASTER_API_KEY en .env. Esa key estuvo expuesta en el
 * repo — debe rotarse en el panel de Facturapi independientemente de
 * cuándo se termine de desplegar esta migración.
 */
class FacturapiKeyService
{
    public function __construct(private readonly SistemaConfigRepositoryInterface $sistemaConfig)
    {
    }

    public function obtenerParaOrganizacion(?string $organizationId, string $empresaPlan): ?string
    {
        if ($empresaPlan !== 'premium') {
            Logger::info('facturapi', "Plan {$empresaPlan} - no requiere API key de prueba");
            return null;
        }

        $facturapiConfig = Config::getInstance()->getFacturapiConfig();
        $masterKey = $facturapiConfig['master_api_key'] ?? null;

        if (empty($masterKey) || empty($organizationId)) {
            Logger::warning('facturapi', 'No se puede obtener API key de prueba', [
                'organization_id' => $organizationId,
                'master_key_configurada' => !empty($masterKey),
            ]);
            return $this->respaldo($facturapiConfig);
        }

        try {
            $facturapiMaster = new Facturapi($masterKey);
            $testApiKey = $facturapiMaster->Organizations->getTestApiKey($organizationId);

            Logger::info('facturapi', 'API key de prueba obtenida de Facturapi', ['organization_id' => $organizationId]);

            try {
                $this->sistemaConfig->guardarFacturapiTestApiKeyCache($testApiKey);
            } catch (Throwable $e) {
                Logger::warning('facturapi', 'No se pudo guardar la API key en caché', ['error' => $e->getMessage()]);
            }

            return $testApiKey;
        } catch (Throwable $e) {
            Logger::error('facturapi', 'Error al obtener API key de prueba de Facturapi', ['error' => $e->getMessage()]);

            $enCache = $this->sistemaConfig->facturapiTestApiKeyCache();
            if (!empty($enCache)) {
                Logger::info('facturapi', 'Usando API key de prueba desde caché (respaldo)');
                return $enCache;
            }

            return $this->respaldo($facturapiConfig);
        }
    }

    private function respaldo(array $facturapiConfig): ?string
    {
        $fallback = $facturapiConfig['test_api_key_fallback'] ?? null;

        if (!empty($fallback)) {
            Logger::warning('facturapi', 'Usando API key de prueba de respaldo fija (.env)');
        }

        return $fallback ?: null;
    }
}
