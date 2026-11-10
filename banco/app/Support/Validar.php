<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Ticket;
use App\Services\CryptoService;
use InvalidArgumentException;

/** Validaciones de formato. Todas lanzan FORMATO_INVALIDO si el dato no cumple. */
final class Validar
{
    public const MAX_TICKETS_POR_SOLICITUD = 50;

    private static function falla(): never
    {
        throw new ApiError('FORMATO_INVALIDO');
    }

    /** Devuelve el correo recortado y en minúsculas. */
    public static function correo(mixed $v): string
    {
        if (!is_string($v)) {
            self::falla();
        }
        $v = mb_strtolower(trim($v));
        if ($v === '' || strlen($v) > 254 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            self::falla();
        }

        return $v;
    }

    /** Contraseña al registrarse: de 8 a 128 caracteres. */
    public static function passwordNueva(mixed $v): string
    {
        if (!is_string($v) || mb_strlen($v) < 8 || mb_strlen($v) > 128) {
            self::falla();
        }

        return $v;
    }

    /** Contraseña al iniciar sesión: de 1 a 128 caracteres. */
    public static function passwordLogin(mixed $v): string
    {
        if (!is_string($v) || mb_strlen($v) < 1 || mb_strlen($v) > 128) {
            self::falla();
        }

        return $v;
    }

    /**
     * Pub_U: Base64 estándar con relleno, exactamente en su forma canónica (porque va dentro de la
     * cadena que firma el banco), y debe ser una llave EC de la curva P-256.
     */
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

    /**
     * Denominaciones solicitadas: objeto {"500":2,"300":1}. Cada llave debe ser una denominación
     * permitida (entero), cada cantidad un entero de 1 a 50, y el total de tickets no pasar de 50.
     * No se aceptan cantidades en cero: quien firma simplemente omite esa denominación.
     * @return array<int,int>
     */
    public static function denominaciones(mixed $v): array
    {
        if (!is_array($v) || $v === []) {
            self::falla();
        }
        $totalTickets = 0;
        foreach ($v as $denominacion => $cantidad) {
            if (!is_int($denominacion) || !in_array($denominacion, Ticket::DENOMINACIONES, true)) {
                self::falla();
            }
            if (!is_int($cantidad) || $cantidad < 1 || $cantidad > self::MAX_TICKETS_POR_SOLICITUD) {
                self::falla();
            }
            $totalTickets += $cantidad;
        }
        if ($totalTickets > self::MAX_TICKETS_POR_SOLICITUD) {
            self::falla();
        }

        return $v;
    }
}
