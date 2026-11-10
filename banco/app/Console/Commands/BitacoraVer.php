<?php

namespace App\Console\Commands;

use App\Models\Bitacora;
use Illuminate\Console\Command;

/** Muestra las últimas filas de la bitácora del banco (hasta que exista el panel web de la Fase 9). */
class BitacoraVer extends Command
{
    protected $signature = 'bitacora:ver {--n=15 : Cuántas filas mostrar}';
    protected $description = 'Muestra las últimas filas de la bitácora del banco';

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
