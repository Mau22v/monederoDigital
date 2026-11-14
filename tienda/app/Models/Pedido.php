<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Pedido extends Model
{
    public const CREADO = 'CREADO';
    public const PAGADO = 'PAGADO';
    public const RECHAZADO = 'RECHAZADO';      // reservado: la tienda no lo usa (un pago rechazado deja el pedido CREADO)
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
    protected $casts = [
        'monto' => 'integer', 'creado_en' => 'integer', 'expiracion' => 'integer',
        'pagado_en' => 'integer', 'recibo_ts' => 'integer',
    ];

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

    /** Ids de los tickets del intento de pago o del pago, en orden ascendente (texto). [] si no hay. */
    public function idsTickets(): array
    {
        $v = (string) $this->ids_tickets;

        return $v === '' ? [] : explode(',', $v);
    }

    /** ¿Hay un intento de pago que quizá el banco ya ejecutó? Solo un pedido CREADO puede tenerlo. */
    public function tieneIntento(): bool
    {
        return $this->estado === self::CREADO && $this->ids_tickets !== null && $this->ids_tickets !== '';
    }

    /** Anota los tickets del intento ANTES de llamar al banco (si la respuesta se pierde, se podrá conciliar). */
    public function registrarIntento(array $ids): void
    {
        static::query()->where('id', $this->id)->where('estado', self::CREADO)->update(['ids_tickets' => implode(',', $ids)]);
        $this->refresh();
    }

    /** El banco rechazó el canje de forma definitiva: no hay nada que conciliar. */
    public function limpiarIntento(): void
    {
        static::query()->where('id', $this->id)->where('estado', self::CREADO)->update(['ids_tickets' => null]);
        $this->refresh();
    }

    /**
     * CREADO -> PAGADO en una sola instrucción condicionada al estado actual: si dos peticiones pagan a la vez,
     * solo una cambia el estado (devuelve true); la otra recibe false y debe leer el resultado guardado.
     */
    public function marcarPagado(array $ids, int $pagadoEn, string $firmaComprobante, int $reciboTs, string $reciboFirmaB): bool
    {
        $filas = static::query()->where('id', $this->id)->where('estado', self::CREADO)->update([
            'estado' => self::PAGADO, 'ids_tickets' => implode(',', $ids), 'pagado_en' => $pagadoEn,
            'firma_comprobante' => $firmaComprobante, 'recibo_ts' => $reciboTs, 'recibo_firma_b' => $reciboFirmaB,
        ]);
        $this->refresh();

        return $filas === 1;
    }
}
