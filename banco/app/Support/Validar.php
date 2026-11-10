<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CryptoService;
use InvalidArgumentException;

/** Validaciones de formato. Todas lanzan FORMATO_INVALIDO si el dato no cumple. */
final class Validar
{
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
}
