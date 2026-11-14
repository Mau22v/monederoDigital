<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Nonce;

/** Lo que recibe la lógica de cada endpoint una vez abierto y validado el mensaje. */
final class Contexto
{
    /** Cuenta que se anotará en la bitácora. La lógica la fija cuando el Cert_U ya verificó. */
    public ?string $idCuenta = null;
    /** Texto breve para la bitácora cuando la operación se acepta. Nunca datos secretos. */
    public ?string $detalle = null;

    /**
     * $nonce y $timestamp son los del mensaje. Normalmente están en la raíz de los datos; en el pago
     * (/api/pedidos/{id}/pago) son los de la orden firmada, que va anidada en "orden".
     */
    public function __construct(
        public readonly array $datos,
        public readonly int $ahora,
        public readonly string $endpoint,
        public readonly ?string $nonce = null,
        public readonly ?int $timestamp = null,
    ) {
    }

    /**
     * Paso 7 del orden de verificación (contrato, sección 13): guardar el nonce.
     * Se llama DESPUÉS de verificar el certificado y las firmas. Si otra petición lo guardó antes, es una repetición.
     */
    public function registrarNonce(string $origen): void
    {
        if (!Nonce::registrar($this->nonce ?? $this->datos['nonce'], $origen, $this->endpoint, $this->ahora)) {
            throw new ApiError('NONCE_REPETIDO');
        }
    }
}
