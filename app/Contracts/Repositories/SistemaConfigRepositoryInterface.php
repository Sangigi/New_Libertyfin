<?php

namespace App\Contracts\Repositories;

/**
 * `sistema_config` vive en la base de datos de CADA empresa (no en la
 * principal) — nombre visible, RFC, contacto, colores de marca y logo.
 */
interface SistemaConfigRepositoryInterface
{
    /** @return array{nombre_empresa:?string, rfc:?string, telefono:?string, email:?string, direccion:?string, color_primario:?string, color_secundario:?string, logo:?string, iva:?float, moneda:?string, stock_minimo_global:?int}|null */
    public function actual(): ?array;

    /** Api key de prueba de Facturapi cacheada en una entrega anterior (columna puede no existir aún). */
    public function facturapiTestApiKeyCache(): ?string;

    /** Guarda la api key de prueba de Facturapi en caché, creando la columna si hace falta. */
    public function guardarFacturapiTestApiKeyCache(string $key): void;
}
