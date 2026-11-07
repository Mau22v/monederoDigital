<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PingTest extends TestCase
{
    public function test_la_api_responde(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson(['servidor' => 'tienda', 'estado' => 'ok', 'hash' => 'argon2id']);
    }

    public function test_las_contrasenas_usan_argon2id(): void
    {
        $this->assertStringStartsWith('$argon2id$', Hash::make('prueba123'));
    }
}
