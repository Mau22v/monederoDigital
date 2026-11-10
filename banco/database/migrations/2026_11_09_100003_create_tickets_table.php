<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_cuenta')->constrained('cuentas');
            $table->unsignedSmallInteger('denominacion');                 // 500, 300, 100, 50 o 20 (lo valida el modelo)
            $table->unsignedBigInteger('emision');
            $table->unsignedBigInteger('expiracion');
            $table->text('firma_b');                                      // firma del banco sobre la cadena del ticket
            $table->enum('estado', ['ACTIVO', 'GASTADO'])->default('ACTIVO');   // EXPIRADO se calcula con la fecha
            $table->foreignUuid('id_pedido')->nullable()->constrained('canjes', 'id_pedido');
            $table->unsignedBigInteger('gastado_en')->nullable();
            $table->timestamps();

            $table->index(['id_cuenta', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
