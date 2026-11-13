<?php

namespace APP\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Bitacora;

/** Muestra las ultimas filas de la bitácora de la tienda hasta que exista el panel web FASE 9*/

class BitacoraVer extends Command
{
    protected $signature = 'bitacora:ver {--n=15 : Número de filas a mostrar}';
    protected $description = 'Ver el contenido de la bitácora';

    public function handle(): int
    {
         $filas = Bitacora::orderByDesc('id')->limit((int) $this->option('n'))->get()->reverse();
         $this->table(
            ['id', 'hora', 'endpoint', 'resultado', 'código', 'cuenta', 'detalle'],
            $filas->map(fn ($f) => [
                $f->id, date('H:i:s', (int) $f->momento), $f->endpoint, $f->resultado, $f->codigo ?? '-',
                $f->id_cuenta ? substr($f->id_cuenta, 0, 8) . '…' : '-', $f->detalle ?? '',
            ])->all()
        );

        return self::SUCCESS;

    }
}


