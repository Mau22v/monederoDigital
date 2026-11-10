<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Ticket extends Model
{
    public const DENOMINACIONES = [500, 300, 100, 50, 20];
    public const VIGENCIA_SEG   = 2592000;   // 30 días
    public const ACTIVO  = 'ACTIVO';
    public const GASTADO = 'GASTADO';
    public const EXPIRADO = 'EXPIRADO';      // solo se calcula, no se guarda

    protected $table = 'tickets';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $attributes = ['estado' => self::ACTIVO];
    protected $casts = ['denominacion' => 'integer', 'emision' => 'integer', 'expiracion' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            if (!in_array((int) $t->denominacion, self::DENOMINACIONES, true)) {
                throw new InvalidArgumentException('Denominación no permitida');
            }
        });
        static::creating(function (self $t) {
            if (empty($t->id)) {
                $t->id = (string) Str::uuid();
            }
        });
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'id_cuenta');
    }

    public function canje(): BelongsTo
    {
        return $this->belongsTo(Canje::class, 'id_pedido', 'id_pedido');
    }

    /** ACTIVO, GASTADO o EXPIRADO. Un ticket vence en el instante de su expiración. */
    public function estadoEfectivo(int $ahora): string
    {
        if ($this->estado === self::GASTADO) {
            return self::GASTADO;
        }

        return $ahora >= $this->expiracion ? self::EXPIRADO : self::ACTIVO;
    }

    /**
     * Gasta TODOS los tickets o NINGUNO. Es la defensa contra el doble gasto (E2):
     * una sola instrucción UPDATE que solo toca tickets ACTIVOS, vigentes y de esa cuenta.
     * Si alguno no cumple, se deshace todo y devuelve false.
     * El canje ($idPedido) debe existir ya en la tabla canjes.
     *
     * @param string[] $ids
     */
    public static function gastar(array $ids, string $idPedido, string $idCuenta, int $ahora): bool
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return false;
        }

        DB::beginTransaction();
        try {
            $filas = static::query()
                ->whereIn('id', $ids)
                ->where('id_cuenta', $idCuenta)
                ->where('estado', self::ACTIVO)
                ->where('expiracion', '>', $ahora)
                ->update(['estado' => self::GASTADO, 'id_pedido' => $idPedido, 'gastado_en' => $ahora]);

            if ($filas !== count($ids)) {
                DB::rollBack();

                return false;
            }
            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
