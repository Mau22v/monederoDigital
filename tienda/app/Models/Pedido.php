<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Pedido extends Model
{
    public const CREADO = 'CREADO';
    public const PAGADO = 'PAGADO';
    public const RECHAZADO = 'RECHAZADO';
    public const EXPIRADO = 'EXPIRADO';        // solo se calcula, no se guarda

    public const VIGENCIA_SEG = 600;           // contrato, sección 14
    public const MONTO_MAXIMO = 10000;         // un pedido más caro no podría pagarse con el saldo inicial
    public const MAX_ITEMS = 10;               // renglones distintos por pedido
    public const MAX_CANTIDAD = 10;            // unidades por renglón

    protected $table = 'pedidos';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $attributes = ['estado' => self::CREADO];
    protected $casts = ['monto' => 'integer', 'creado_en' => 'integer', 'expiracion' => 'integer'];

    protected static function booted(): void
    {
        // UUID v4 (el contrato lo exige; HasUuids de Laravel generaría otra versión).
        static::creating(function (self $p) {
            if (empty($p->id)) {
                $p->id = (string) Str::uuid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'id_pedido');
    }

    /** CREADO, PAGADO, RECHAZADO o EXPIRADO. Un pedido vence en el instante de su expiración. */
    public function estadoEfectivo(int $ahora): string
    {
        if ($this->estado !== self::CREADO) {
            return $this->estado;
        }

        return $ahora >= $this->expiracion ? self::EXPIRADO : self::CREADO;
    }

    /** Busca un pedido SOLO si es de esa cuenta: quien no es dueño no puede ni saber que existe. */
    public static function delCliente(string $id, string $idCuenta): ?self
    {
        return static::query()->where('id', $id)->where('id_cuenta', $idCuenta)->first();
    }
}
