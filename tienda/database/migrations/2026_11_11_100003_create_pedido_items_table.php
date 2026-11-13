<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('id_pedido');
            $table->foreign('id_pedido')->references('id')->on('pedidos')->cascadeOnDelete();
            // Sin llave foránea hacia productos: se guarda una COPIA del nombre y del precio del momento de la compra,
            // para que un cambio posterior de precio no altere pedidos ya firmados.
            $table->unsignedInteger('id_producto');
            $table->string('nombre', 60);
            $table->unsignedInteger('precio_unitario');
            $table->unsignedSmallInteger('cantidad');
            $table->unique(['id_pedido', 'id_producto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_items');
    }
};
