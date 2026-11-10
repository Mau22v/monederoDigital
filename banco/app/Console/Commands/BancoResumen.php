<?php

namespace App\Console\Commands;

use App\Models\Cuenta;
use App\Models\Ticket;
use Illuminate\Console\Command;

/** Muestra cuentas (con saldo) y tickets agrupados, para ver el efecto de las operaciones en la base de datos. */
class BancoResumen extends Command
{
    protected $signature = 'banco:resumen';
    protected $description = 'Muestra las cuentas y los tickets del banco';

    public function handle(): int
    {
        $this->info('CUENTAS');
        $this->table(
            ['id', 'correo', 'saldo', 'intentos', 'bloqueada hasta'],
            Cuenta::orderBy('created_at')->get()->map(fn ($c) => [
                substr($c->id, 0, 8) . '…', $c->correo, '$' . $c->saldo, $c->intentos_fallidos,
                $c->bloqueada_hasta ? date('H:i:s', $c->bloqueada_hasta) : '-',
            ])->all()
        );

        $this->info('TICKETS (agrupados)');
        $this->table(
            ['cuenta', 'denominación', 'estado', 'cantidad'],
            Ticket::query()->selectRaw('id_cuenta, denominacion, estado, count(*) as n')
                ->groupBy('id_cuenta', 'denominacion', 'estado')->orderBy('id_cuenta')->orderByDesc('denominacion')->get()
                ->map(fn ($t) => [substr($t->id_cuenta, 0, 8) . '…', '$' . $t->denominacion, $t->estado, $t->n])->all()
        );

        return self::SUCCESS;
    }
}
