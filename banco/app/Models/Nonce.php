<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Nonce extends Model
{
    public const RETENCION_SEG = 900;   // 15 minutos (contrato, sección 14)

    protected $table = 'nonces';
    protected $primaryKey = 'nonce';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    /** Paso 5 del orden de verificación: ¿ya se usó? */
    public static function yaUsado(string $nonce): bool
    {
        return static::query()->where('nonce', $nonce)->exists();
    }

    /**
     * Paso 7: guarda el nonce. Devuelve false si ya existía (INSERT OR IGNORE no lanza error:
     * la base de datos decide quién llegó primero aunque dos peticiones lleguen a la vez).
     */
    public static function registrar(string $nonce, string $origen, string $endpoint, int $ahora): bool
    {
        return DB::table('nonces')->insertOrIgnore([
            'nonce'         => $nonce,
            'origen'        => $origen,
            'endpoint'      => $endpoint,
            'registrado_en' => $ahora,
            'expira_en'     => $ahora + self::RETENCION_SEG,
        ]) === 1;
    }

    /** Borra los nonces cuya retención terminó. Devuelve cuántos borró. */
    public static function purgar(int $ahora): int
    {
        return static::query()->where('expira_en', '<=', $ahora)->delete();
    }
}
