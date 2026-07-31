<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** Se intentó abrir una caja que ya está abierta para ese usuario/sucursal. */
class CajaYaAbiertaException extends RuntimeException
{
}
