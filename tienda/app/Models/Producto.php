<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Producto extends Model
{
    public const PRECIO_MAXIMO = 10000;   // = saldo inicial de una cuenta del banco

    protected $table = 'productos';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'precio' => 'integer', 'activo' => 'boolean'];

    protected static function booted(): void
    {
        // Ningún producto inválido llega a la base de datos: rompería la firma del catálogo o dejaría un precio imposible de pagar.
        static::saving(function (self $p) {
            if (!self::nombreValido((string) $p->nombre)) {
                throw new InvalidArgumentException('Nombre de producto inválido');
            }
            if (!self::precioValido((int) $p->precio)) {
                throw new InvalidArgumentException('Precio inválido: debe poder pagarse con dinero exacto');
            }
        });
    }

    /**
     * Los tickets son de 500, 300, 100, 50 y 20 y no hay cambio. Con 20 y 50 se forma cualquier múltiplo
     * de 10 desde 20, salvo el 30 (y el 10, que es menor que 20). Por eso el precio debe ser múltiplo de 10,
     * al menos 20, distinto de 30 y no mayor que PRECIO_MAXIMO.
     */
    public static function precioValido(int $precio): bool
    {
        return $precio >= 20 && $precio <= self::PRECIO_MAXIMO && $precio % 10 === 0 && $precio !== 30;
    }

    /** Contrato, sección 7: el nombre no puede contener | ; : , (separan campos de la cadena firmada). Sin saltos ni espacios sobrantes. */
    public static function nombreValido(string $nombre): bool
    {
        return $nombre === trim($nombre)
            && mb_strlen($nombre) >= 1
            && mb_strlen($nombre) <= 60
            && preg_match('/[|;:,\p{C}]/u', $nombre) !== 1;
    }

    /** Productos activos, ordenados por id, listos para firmar y publicar. @return array<int,array{id:int,nombre:string,precio:int}> */
    public static function catalogo(): array
    {
        return static::query()->where('activo', true)->orderBy('id')->get()
            ->map(fn (self $p) => ['id' => (int) $p->id, 'nombre' => $p->nombre, 'precio' => (int) $p->precio])
            ->values()->all();
    }

    /** Productos activos con esos ids, indexados por id. @param int[] $ids @return array<int,self> */
    public static function activosPorId(array $ids): array
    {
        return static::query()->whereIn('id', $ids)->where('activo', true)->get()->keyBy('id')->all();
    }
}
