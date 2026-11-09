<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Se lanza cuando un sobre cifrado no se puede abrir: formato incorrecto,
 * llave RSA que no corresponde, o etiqueta de autenticación de AES-GCM inválida
 * (el mensaje fue modificado). Escenario de prueba E4.
 *
 * El mensaje es siempre el mismo a propósito: no debe revelar en qué paso falló.
 */
class MensajeCorruptoException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mensaje cifrado inválido');
    }
}
