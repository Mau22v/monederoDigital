<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La clave primaria es el propio nonce: la base de datos impide guardar el mismo dos veces.
        Schema::create('nonces', function (Blueprint $table) {
            $table->string('nonce', 32)->primary();
            $table->string('origen');                          // id_cuenta del cliente, o "tienda"
            $table->string('endpoint');
            $table->unsignedBigInteger('registrado_en');
            $table->unsignedBigInteger('expira_en')->index();  // a partir de aquí se puede borrar
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nonces');
    }
};
