<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->uuid('id')->primary();                    // id_pedido (UUID v4)
            $table->string('id_cuenta', 36)->index();         // dueño: viene del Cert_U verificado (sin llave foránea: la cuenta vive en el banco)
            $table->unsignedInteger('monto');                 // total en pesos
            $table->string('nonce_t', 32);                    // nonce propio de la tienda, va dentro de la firma
            $table->unsignedBigInteger('creado_en');
            $table->unsignedBigInteger('expiracion')->index();
            // EXPIRADO no se guarda: se calcula al consultar (ahora >= expiracion), igual que en los tickets.
            $table->enum('estado', ['CREADO', 'PAGADO', 'RECHAZADO'])->default('CREADO');
            $table->string('firma_t', 200);                   // firma de la tienda sobre la cadena "pedido|v1|..."
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
