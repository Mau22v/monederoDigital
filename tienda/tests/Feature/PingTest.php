<?php

namespace Tests\Feature;

use Tests\TestCase;

class PingTest extends TestCase
{
    public function test_ping_responde_ok(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson([
                'servidor' => 'tienda',
                'estado'   => 'ok',
                'hash'     => hash('sha256', 'ping'),
            ]);
    }

    public function test_driver_de_hash_de_contrasenas_es_argon2id(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));
    }
}