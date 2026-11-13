<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoItem extends Model
{
    protected $table = 'pedido_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id_producto' => 'integer', 'precio_unitario' => 'integer', 'cantidad' => 'integer'];
}
