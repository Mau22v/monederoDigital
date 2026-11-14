<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** El banco respondió con un error del contrato (sección 12): la operación NO se ejecutó. */
class BancoRechazo extends RuntimeException
{
    public function __construct(public readonly string $codigo)
    {
        parent::__construct("El banco rechazó la operación: $codigo");
    }
}
