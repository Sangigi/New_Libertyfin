<?php

namespace App\Contracts\Repositories;

/**
 * `sistema_config` vive en la base de datos de CADA empresa (no en la
 * principal) — nombre visible, RFC, contacto, colores de marca y logo.
 */
interface SistemaConfigRepositoryInterface
{
    /** @return array{nombre_empresa:?string, rfc:?string, telefono:?string, email:?string, color_primario:?string, color_secundario:?string, logo:?string}|null */
    public function actual(): ?array;
}
