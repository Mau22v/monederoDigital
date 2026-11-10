<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un canje = un pedido de la tienda pagado con tickets. La clave primaria es el id_pedido,
        // así el mismo pedido no puede canjearse dos veces (idempotencia, PEDIDO_DUPLICADO).
        Schema::create('canjes', function (Blueprint $table) {
            $table->uuid('id_pedido')->primary();
            $table->foreignUuid('id_cuenta')->constrained('cuentas');   // dueña de los tickets
            $table->unsignedBigInteger('monto');
            $table->unsignedBigInteger('momento');                      // timestamp del recibo_canje
            $table->text('firma_b');                                    // firma del banco sobre el recibo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canjes');
    }
};
