<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JwtService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pruebas del JWT de sesión (Fase 5). No usan base de datos ni archivos. */
class JwtServiceTest extends TestCase
{
    private const SECRETO = 'secreto-de-prueba-largo-secreto-de-prueba-largo-secreto-de-prueba-largo-123';   // 72 caracteres: HS512 exige 64 o más
    private const T0 = 1762500000;
    private const CUENTA = '7a1d9e22-0c3b-4f8a-8b11-aa22bb33cc44';

    private function servicio(): JwtService
    {
        return new JwtService(self::SECRETO, 900);
    }

    private function carga(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_el_token_tiene_tres_partes_y_dura_900_segundos(): void
    {
        [$token, $ttl] = $this->servicio()->emitir(self::CUENTA, self::T0);

        $this->assertSame(3, count(explode('.', $token)));
        $this->assertSame(900, $ttl);
    }

    public function test_los_claims_son_sub_iat_exp_y_jti(): void
    {
        [$token] = $this->servicio()->emitir(self::CUENTA, self::T0);
        $c = $this->carga($token);

        $this->assertSame(self::CUENTA, $c['sub']);
        $this->assertSame(self::T0, $c['iat']);
        $this->assertSame(self::T0 + 900, $c['exp']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $c['jti']));
    }

    public function test_dos_tokens_tienen_jti_distinto(): void
    {
        [$a] = $this->servicio()->emitir(self::CUENTA, self::T0);
        [$b] = $this->servicio()->emitir(self::CUENTA, self::T0);

        $this->assertNotSame($this->carga($a)['jti'], $this->carga($b)['jti']);
    }

    public function test_un_token_vigente_devuelve_el_id_de_la_cuenta(): void
    {
        [$token] = $this->servicio()->emitir(self::CUENTA, self::T0);

        $this->assertSame(self::CUENTA, $this->servicio()->validar($token, self::T0 + 10));
        $this->assertSame(self::CUENTA, $this->servicio()->validar($token, self::T0 + 899));
    }

    public function test_un_token_vencido_se_rechaza(): void
    {
        [$token] = $this->servicio()->emitir(self::CUENTA, self::T0);

        $this->assertSame(null, $this->servicio()->validar($token, self::T0 + 900));
        $this->assertSame(null, $this->servicio()->validar($token, self::T0 + 5000));
    }

    public function test_un_token_alterado_se_rechaza(): void
    {
        [$token] = $this->servicio()->emitir(self::CUENTA, self::T0);
        [$cabecera, $carga, $firma] = explode('.', $token);
        $otra = json_decode(base64_decode(strtr($carga, '-_', '+/')), true);
        $otra['sub'] = '00000000-0000-4000-8000-000000000000';   // intento de usar otra cuenta
        $carga2 = rtrim(strtr(base64_encode(json_encode($otra)), '+/', '-_'), '=');

        $this->assertSame(null, $this->servicio()->validar("$cabecera.$carga2.$firma", self::T0 + 10));
    }

    public function test_un_token_firmado_con_otro_secreto_se_rechaza(): void
    {
        $falso = JWT::encode(['sub' => self::CUENTA, 'iat' => self::T0, 'exp' => self::T0 + 900], str_repeat('x', 40), 'HS256');

        $this->assertSame(null, $this->servicio()->validar($falso, self::T0 + 10));
    }

    public function test_un_token_con_otro_algoritmo_se_rechaza(): void
    {
        $hs512 = JWT::encode(['sub' => self::CUENTA, 'iat' => self::T0, 'exp' => self::T0 + 900], self::SECRETO, 'HS512');

        $this->assertSame(null, $this->servicio()->validar($hs512, self::T0 + 10));
    }

    public function test_texto_que_no_es_un_token_se_rechaza(): void
    {
        $this->assertSame(null, $this->servicio()->validar('', self::T0));
        $this->assertSame(null, $this->servicio()->validar('abc', self::T0));
        $this->assertSame(null, $this->servicio()->validar('a.b.c', self::T0));
        $this->assertSame(null, $this->servicio()->validar('eyJhbGciOiJub25lIn0.eyJzdWIiOiJ4In0.', self::T0));   // alg "none"
    }

    public function test_un_secreto_corto_no_se_acepta(): void
    {
        $this->expectException(RuntimeException::class);
        new JwtService('corto', 900);
    }
}
