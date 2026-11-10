<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Tokens de sesión JWT HS256 (contrato, sección 5). Claims: sub (id_cuenta), iat, exp, jti.
 * El tiempo se recibe como parámetro para que las pruebas puedan controlarlo.
 */
class JwtService
{
    private const ALGORITMO = 'HS256';

    public function __construct(private readonly string $secreto, private readonly int $ttl)
    {
        if (strlen($secreto) < 32) {
            throw new RuntimeException('JWT_SECRET debe tener al menos 32 caracteres');
        }
        if ($ttl <= 0) {
            throw new RuntimeException('JWT_TTL debe ser positivo');
        }
    }

    /** @return array{0:string,1:int} [token, segundos de vida] */
    public function emitir(string $idCuenta, int $ahora): array
    {
        $payload = [
            'sub' => $idCuenta,
            'iat' => $ahora,
            'exp' => $ahora + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return [JWT::encode($payload, $this->secreto, self::ALGORITMO), $this->ttl];
    }

    /** Devuelve el id_cuenta (claim sub) si el token es auténtico y está vigente; null en cualquier otro caso. */
    public function validar(string $token, int $ahora): ?string
    {
        JWT::$timestamp = $ahora;
        JWT::$leeway = 0;
        try {
            $datos = JWT::decode($token, new Key($this->secreto, self::ALGORITMO));
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$timestamp = null;
        }

        return (isset($datos->sub) && is_string($datos->sub) && $datos->sub !== '') ? $datos->sub : null;
    }
}
