<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alimenta el panel web. Nunca se guardan contraseñas, llaves privadas ni la K de un sobre.
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('momento')->index();
            $table->string('endpoint');
            $table->enum('resultado', ['ACEPTADO', 'RECHAZADO']);
            $table->string('codigo')->nullable();              // código de error del contrato (sección 12)
            $table->string('id_cuenta')->nullable();           // sin llave foránea: también se registran intentos contra cuentas inexistentes
            $table->string('detalle', 500)->nullable();
            $table->string('ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora');
    }
};
