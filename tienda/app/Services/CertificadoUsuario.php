<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiError;

/**
 * Autenticación de un cliente ante la tienda (contrato, sección 10): Cert_U + firma_u.
 * La tienda NO guarda cuentas de usuario: confía en el Cert_U porque lo firmó el banco, y para verificarlo
 * le basta la llave pública del banco (banco_ec_pub.pem).
 */
class CertificadoUsuario
{
    public function __construct(private readonly CryptoService $cripto, private readonly string $bancoEcPubPem)
    {
    }

    /**
     * Paso 6a: el Cert_U debe estar firmado por el banco y vigente (vence en el instante de su expiración).
     * El formato ya se validó antes (Validar::certU).
     * @throws ApiError CERTIFICADO_INVALIDO
     */
    public function verificarCert(array $cert, int $ahora): void
    {
        $cadena = Canonico::cert($cert['id_cuenta'], $cert['pub_u'], $cert['expiracion']);
        if (!$this->cripto->verificar($cadena, $cert['firma_b'], $this->bancoEcPubPem)) {
            throw new ApiError('CERTIFICADO_INVALIDO');
        }
        if ($cert['expiracion'] <= $ahora) {
            throw new ApiError('CERTIFICADO_INVALIDO');
        }
    }

    /**
     * Paso 6b: la firma del cliente sobre la cadena canónica debe verificar con la Pub_U que trae SU Cert_U.
     * Llamar solo después de verificarCert().
     * @throws ApiError FIRMA_INVALIDA
     */
    public function verificarFirmaCliente(array $cert, string $cadena, string $firmaU): void
    {
        $pubU = $this->cripto->pemDesdeBase64($cert['pub_u']);
        if (!$this->cripto->verificar($cadena, $firmaU, $pubU)) {
            throw new ApiError('FIRMA_INVALIDA');
        }
    }
}
