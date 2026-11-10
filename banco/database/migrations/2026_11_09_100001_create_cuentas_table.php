<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->uuid('id')->primary();                       // id_cuenta (UUID v4)
            $table->string('correo')->unique();                  // se guarda en minúsculas (lo hace el servicio)
            $table->string('password_hash');                     // Argon2id; nunca la contraseña
            $table->unsignedBigInteger('saldo')->default(0);     // pesos enteros
            $table->text('pub_u');                               // llave pública del cliente (Base64 X.509)
            $table->unsignedBigInteger('cert_expiracion');       // Cert_U: expiración (segundos Unix)
            $table->text('cert_firma_b');                        // Cert_U: firma del banco
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->unsignedBigInteger('bloqueada_hasta')->nullable();   // segundos Unix
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
