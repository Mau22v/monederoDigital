<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiError;

/**
 * Verifica las firmas de la TIENDA con su llave pública (tienda_ec_pub.pem). El banco solo atiende
 * /api/canje y /api/canjes/consulta si el mensaje trae una firma válida de la tienda.
 */
class FirmaTienda
{
    public function __construct(private readonly CryptoService $cripto, private readonly string $tiendaEcPubPem)
    {
    }

    /** @throws ApiError FIRMA_INVALIDA */
    public function verificar(string $cadena, string $firmaBase64): void
    {
        if (!$this->cripto->verificar($cadena, $firmaBase64, $this->tiendaEcPubPem)) {
            throw new ApiError('FIRMA_INVALIDA');
        }
    }
}
