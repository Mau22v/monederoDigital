<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Cuenta extends Model
{
    public const MAX_INTENTOS = 5; //CONTRATO DE SECCION 14
    public const BLOQUEO_SEG = 300; // 5 MINUTOS

    protected $table = 'cuentas';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = []; // modelo interno: jamás se llena directo desde una petición
    protected $hidden = ['password_hash'];
    protected $attributes = ['intentos_fallidos' => 0];
    protected $casts = ['saldo' => 'integer', 'intentos_fallidos' => 'integer'];

    protected static function booted(): void
    {
        // UUID v4 (el contrato lo exige; HasUuids de Laravel generaría otra versión).
        static::creating(function (self $cuenta) {
            if (empty($cuenta->id)) {
                $cuenta->id = (string) Str::uuid();
            }
        });
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'id_cuenta');
    }

    public function estaBloqueada(int $ahora): bool
    {
        return $this->bloqueada_hasta !== null && $this->bloqueada_hasta > $ahora;
    }

    public function registrarFalloLogin(int $ahora): void
    {
        $this->intentos_fallidos = $this->intentos_fallidos + 1;
        if ($this->intentos_fallidos >= self::MAX_INTENTOS) {
            $this->bloqueada_hasta = $ahora + self::BLOQUEO_SEG;
            $this->intentos_fallidos = 0; // resetear intentos fallidos al bloquear
        }
        $this->save();
    }

    public function limpiarIntentos(): void
    {
        $this->intentos_fallidos = 0;
        $this->bloqueada_hasta = null;
        $this->save();
    }

    /**
     * Descuenta saldo en UNA sola instrucción SQL con la condición "saldo >= monto".
     * Así dos peticiones simultáneas no pueden dejar el saldo en negativo.
     */
    public function debitar(int $monto): bool
    {
        if ($monto <= 0) {
            throw new InvalidArgumentException('El monto debe ser positivo');
        }
        $filas = static::query()
            ->where('id', $this->id)
            ->where('saldo', '>=', $monto)
            ->decrement('saldo', $monto);

        $this->refresh();

        return $filas === 1;
    }

}
