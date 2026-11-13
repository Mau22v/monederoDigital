<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;
use RuntimeException;

/**
 * Error de la API con el formato del contrato (sección 12): código, estado HTTP y mensaje breve.
 * Se lanza desde cualquier punto del procesamiento; ProcesadorSobre lo convierte en respuesta.
 */
final class ApiError extends RuntimeException
{
    /** código => [estado HTTP, mensaje para el usuario] */
    private const CATALOGO = [
        'MENSAJE_CORRUPTO'         => [400, 'El mensaje no pudo leerse'],
        'FORMATO_INVALIDO'         => [400, 'Formato o campos incorrectos'],
        'TIMESTAMP_FUERA_DE_RANGO' => [400, 'La hora del mensaje está fuera de rango'],
        'CREDENCIALES_INVALIDAS'   => [401, 'Correo o contraseña incorrectos'],
        'TOKEN_INVALIDO'           => [401, 'Sesión inválida o vencida'],
        'FIRMA_INVALIDA'           => [403, 'La firma no es válida'],
        'CERTIFICADO_INVALIDO'     => [403, 'El certificado no es válido'],
        'TICKET_INVALIDO'          => [403, 'El ticket no es válido'],
        'TICKET_GASTADO'           => [409, 'El ticket ya fue utilizado'],
        'TICKET_EXPIRADO'          => [409, 'El ticket está vencido'],
        'NONCE_REPETIDO'           => [409, 'El mensaje ya fue recibido antes'],
        'PEDIDO_DUPLICADO'         => [409, 'El pedido ya fue canjeado con otros tickets'],
        'PEDIDO_EXPIRADO'          => [409, 'El pedido está vencido'],
        'CORREO_EN_USO'            => [409, 'El correo ya está registrado'],
        'MONTO_INCORRECTO'         => [422, 'El monto no coincide con los tickets'],
        'SALDO_INSUFICIENTE'       => [422, 'Saldo insuficiente'],
        'PEDIDO_NO_ENCONTRADO'     => [404, 'No existe el pedido'],
        'CANJE_NO_ENCONTRADO'      => [404, 'No existe canje para ese pedido'],
        'DEMASIADOS_INTENTOS'      => [429, 'Demasiados intentos. Intente de nuevo más tarde'],
        'ERROR_INTERNO'            => [500, 'Error interno del servidor'],
    ];

    public function __construct(public readonly string $codigo, ?string $mensaje = null)
    {
        if (!isset(self::CATALOGO[$codigo])) {
            throw new LogicException("Código de error desconocido: $codigo");
        }
        parent::__construct($mensaje ?? self::CATALOGO[$codigo][1]);
    }

    public function http(): int
    {
        return self::CATALOGO[$this->codigo][0];
    }

    /** @return string[] */
    public static function codigos(): array
    {
        return array_keys(self::CATALOGO);
    }
}
