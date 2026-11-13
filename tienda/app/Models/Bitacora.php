<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Bitacora extends Model
{
    protected $table = 'bitacora';
    public $timestamps = false;
    protected $guarded = [];

    public static function aceptado(string $endpoint, int $ahora, ?string $idCuenta = null, ?string $detalle = null, ?string $ip = null): self
    {
        return self::guardar($endpoint, 'ACEPTADO', null, $ahora, $idCuenta, $detalle, $ip);
    }

    public static function rechazado(string $endpoint, string $codigo, int $ahora, ?string $idCuenta = null, ?string $detalle = null, ?string $ip = null): self
    {
        return self::guardar($endpoint, 'RECHAZADO', $codigo, $ahora, $idCuenta, $detalle, $ip);
    }

    private static function guardar(string $endpoint, string $resultado, ?string $codigo, int $ahora, ?string $idCuenta, ?string $detalle, ?string $ip): self
    {
        return static::create([
            'momento'   => $ahora,
            'endpoint'  => $endpoint,
            'resultado' => $resultado,
            'codigo'    => $codigo,
            'id_cuenta' => $idCuenta,
            'detalle'   => $detalle === null ? null : mb_substr($detalle, 0, 500),
            'ip'        => $ip,
        ]);
    }

} 