<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Usuario/contraseña incorrectos, o sin sucursal activa asociada.
 *
 * Se distingue de un RuntimeException genérico (ej. fallo de conexión a
 * la base de datos principal) para que login.php sepa cuáles resultados
 * cuentan como "intento fallido" contra el límite de fuerza bruta y
 * cuáles son errores de infraestructura que no debieran penalizar al
 * usuario ni exponer detalles internos en el mensaje.
 */
class InvalidCredentialsException extends RuntimeException
{
}
