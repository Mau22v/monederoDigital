<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Cuenta;
use App\Models\Nonce;

/** Lo que recibe la lógica de cada endpoint una vez abierto y validado el mensaje. */
final class Contexto
{
    /** Cuenta que se anotará en la bitácora (la lógica puede fijarla). */
    public ?string $idCuenta = null;
    /** Texto breve para la bitácora cuando la operación se acepta. Nunca datos secretos. */
    public ?string $detalle = null;

    public function __construct(
        public readonly array $datos,
        public readonly ?Cuenta $cuenta,
        public readonly int $ahora,
        public readonly string $endpoint,
    ) {
        $this->idCuenta = $cuenta?->id;
    }

    /**
     * Paso 7 del orden de verificación (contrato, sección 13): guardar el nonce.
     * Se llama DESPUÉS de verificar las firmas. Si otra petición lo guardó antes, es una repetición.
     */
    public function registrarNonce(string $origen): void
    {
        if (!Nonce::registrar($this->datos['nonce'], $origen, $this->endpoint, $this->ahora)) {
            throw new ApiError('NONCE_REPETIDO');
        }
    }
}
