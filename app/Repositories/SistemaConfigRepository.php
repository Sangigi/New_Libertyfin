<?php

namespace App\Repositories;

use App\Contracts\Repositories\SistemaConfigRepositoryInterface;
use PDO;

class SistemaConfigRepository implements SistemaConfigRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * caja.php hacía esta misma consulta DOS veces por carga de página,
     * con distintos subconjuntos de columnas cada vez (una para
     * iva/moneda/colores, otra para nombre/dirección/teléfono/rfc/logo).
     * Es la misma fila (LIMIT 1) — una sola consulta con todas las
     * columnas cubre ambos usos.
     */
    public function actual(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT nombre_empresa, rfc, telefono, email, direccion,
                    color_primario, color_secundario, logo, iva, moneda,
                    stock_minimo_global
             FROM sistema_config
             LIMIT 1'
        );

        return $stmt->fetch() ?: null;
    }

    public function facturapiTestApiKeyCache(): ?string
    {
        try {
            $stmt = $this->pdo->query('SELECT facturapi_test_api_key FROM sistema_config WHERE id = 1');
            $row = $stmt->fetch();

            return $row['facturapi_test_api_key'] ?? null;
        } catch (\PDOException) {
            // Columna todavía no existe en esta instalación.
            return null;
        }
    }

    public function guardarFacturapiTestApiKeyCache(string $key): void
    {
        // La columna se creaba dinámicamente en el código original la
        // primera vez que se necesitaba. Se conserva ese comportamiento
        // para no romper instalaciones donde aún no exista — lo ideal a
        // futuro es una migración de esquema real, no código de request.
        $stmt = $this->pdo->query("SHOW COLUMNS FROM sistema_config LIKE 'facturapi_test_api_key'");
        if ($stmt->rowCount() === 0) {
            $this->pdo->exec('ALTER TABLE sistema_config ADD COLUMN facturapi_test_api_key VARCHAR(255) DEFAULT NULL');
        }

        $stmt = $this->pdo->prepare('UPDATE sistema_config SET facturapi_test_api_key = ? WHERE id = 1');
        $stmt->execute([$key]);
    }
}
