<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** El banco no respondió o su respuesta no sirve (sin conexión, tiempo agotado, error 5xx, respuesta ilegible). No se sabe si ejecutó la operación. */
class BancoNoDisponible extends RuntimeException
{
}
