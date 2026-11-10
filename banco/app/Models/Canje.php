<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Canje extends Model
{
    protected $table = 'canjes';
    public $primaryKey = 'id_pedido';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'id_cuenta');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'id_pedido', 'id_pedido');
    }

    /** Ids de los tickets del canje en orden ascendente (comparación de texto), como en el recibo. */
    public function idsOrdenados(): array
    {
        $ids = $this->tickets()->pluck('id')->all();
        sort($ids, SORT_STRING);

        return $ids;
    }
}