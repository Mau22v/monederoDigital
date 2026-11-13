<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El id lo fija quien prepara el catálogo (entero, contrato sección 3); no es autoincremental.
        Schema::create('productos', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('nombre', 60);
            $table->unsignedInteger('precio');          // pesos enteros (sección 14 del contrato)
            $table->boolean('activo')->default(true);   // un producto retirado deja de aparecer en el catálogo
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
