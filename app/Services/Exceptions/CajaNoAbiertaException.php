<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** Se intentó cerrar o consultar una caja, pero no hay ninguna abierta. */
class CajaNoAbiertaException extends RuntimeException
{
}
