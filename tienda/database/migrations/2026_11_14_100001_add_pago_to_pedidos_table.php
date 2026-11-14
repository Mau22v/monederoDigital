<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8: datos del pago en la tabla pedidos.
 * Todas las columnas son opcionales (NULL) hasta que el pedido se paga o se intenta pagar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->text('ids_tickets')->nullable();              // ids de los tickets del intento de pago o del pago (ordenados, separados por coma)
            $table->unsignedBigInteger('pagado_en')->nullable();  // momento del pago = timestamp del comprobante
            $table->string('firma_comprobante', 200)->nullable(); // firma de la tienda sobre "comprobante|v1|..."
            $table->unsignedBigInteger('recibo_ts')->nullable();  // timestamp del recibo de canje del banco
            $table->text('recibo_firma_b')->nullable();           // firma del banco sobre el recibo de canje
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['ids_tickets', 'pagado_en', 'firma_comprobante', 'recibo_ts', 'recibo_firma_b']);
        });
    }
};
