<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Pedido;
use App\Services\CryptoService;
use InvalidArgumentException;

/** Validaciones de formato de la Tienda. Todas lanzan FORMATO_INVALIDO si el dato no cumple. */
final class Validar
{
    private static function falla(): never
    {
        throw new ApiError('FORMATO_INVALIDO');
    }

    /** Nonce: 32 caracteres hexadecimales en minúscula. */
    public static function nonce(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^[0-9a-f]{32}$/', $v) !== 1) {
            self::falla();
        }

        return $v;
    }

    /** Timestamp: entero (no texto ni decimal). */
    public static function timestamp(mixed $v): int
    {
        if (!is_int($v)) {
            self::falla();
        }

        return $v;
    }

    /** UUID en minúsculas (cualquier versión). */
    public static function uuid(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) !== 1) {
            self::falla();
        }

        return $v;
    }

    /** Firma en Base64 canónico (una firma ECDSA P-256 en DER mide unos 96 caracteres). */
    public static function firmaBase64(mixed $v): string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 200) {
            self::falla();
        }
        $bin = base64_decode($v, true);
        if ($bin === false || base64_encode($bin) !== $v) {
            self::falla();
        }

        return $v;
    }

    /** Pub_U: Base64 canónico (va dentro de la cadena firmada por el banco) y llave EC P-256. */
    public static function pubU(mixed $v, CryptoService $cripto): string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 400) {
            self::falla();
        }
        $der = base64_decode($v, true);
        if ($der === false || base64_encode($der) !== $v) {
            self::falla();
        }
        try {
            $pem = $cripto->pemDesdeBase64($v);
        } catch (InvalidArgumentException) {
            self::falla();
        }
        if (!$cripto->esLlaveEcP256($pem)) {
            self::falla();
        }

        return $v;
    }

    /**
     * Cert_U (contrato, sección 8): exactamente los cuatro campos id_cuenta, pub_u, expiracion y firma_b.
     * Aquí solo se revisa el FORMATO; la firma del banco se verifica después (CertificadoUsuario).
     * @return array{id_cuenta:string,pub_u:string,expiracion:int,firma_b:string}
     */
    public static function certU(mixed $v, CryptoService $cripto): array
    {
        if (!is_array($v) || array_is_list($v)) {
            self::falla();
        }
        $llaves = array_keys($v);
        sort($llaves);
        if ($llaves !== ['expiracion', 'firma_b', 'id_cuenta', 'pub_u']) {
            self::falla();
        }
        self::uuid($v['id_cuenta']);
        self::pubU($v['pub_u'], $cripto);
        self::timestamp($v['expiracion']);
        self::firmaBase64($v['firma_b']);

        return $v;
    }

    /**
     * Renglones de un pedido: lista de 1 a 10 objetos {"id": entero >= 1, "cantidad": entero de 1 a 10}, sin ids repetidos.
     * Cantidad en cero, texto o decimal se rechazan (quien firma simplemente omite ese renglón).
     * @return array<int,array{id:int,cantidad:int}>
     */
    public static function items(mixed $v): array
    {
        if (!is_array($v) || $v === [] || !array_is_list($v) || count($v) > Pedido::MAX_ITEMS) {
            self::falla();
        }
        $vistos = [];
        foreach ($v as $item) {
            if (!is_array($item) || array_is_list($item)) {
                self::falla();
            }
            $llaves = array_keys($item);
            sort($llaves);
            if ($llaves !== ['cantidad', 'id']) {
                self::falla();
            }
            if (!is_int($item['id']) || $item['id'] < 1) {
                self::falla();
            }
            if (!is_int($item['cantidad']) || $item['cantidad'] < 1 || $item['cantidad'] > Pedido::MAX_CANTIDAD) {
                self::falla();
            }
            if (isset($vistos[$item['id']])) {
                self::falla();
            }
            $vistos[$item['id']] = true;
        }

        return $v;
    }
}
