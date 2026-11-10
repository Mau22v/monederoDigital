<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cuenta;
use App\Models\Ticket;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emite tickets contra el saldo de una cuenta. Todo ocurre en UNA transacción: se descuenta el saldo
 * y se crean los tickets, o no se hace nada (si algo falla, el saldo no cambia).
 */
class EmisorTickets
{
    public function __construct(private readonly CryptoService $cripto)
    {
    }

    /**
     * @param array<int,int> $denominaciones  ya validadas, ej. [500 => 2, 300 => 1]
     * @return array{tickets: array<int,array>, saldo:int, recibo: array}
     * @throws ApiError SALDO_INSUFICIENTE
     */
    public function emitir(Cuenta $cuenta, array $denominaciones, int $ahora): array
    {
        krsort($denominaciones, SORT_NUMERIC);   // de mayor a menor
        $total = 0;
        foreach ($denominaciones as $valor => $cantidad) {
            $total += $valor * $cantidad;
        }

        return DB::transaction(function () use ($cuenta, $denominaciones, $total, $ahora): array {
            // Una sola instrucción SQL con la condición "saldo >= total" (Fase 4, decisión 21).
            if (!$cuenta->debitar($total)) {
                throw new ApiError('SALDO_INSUFICIENTE');
            }

            $tickets = [];
            $ids = [];
            $expiracion = $ahora + Ticket::VIGENCIA_SEG;
            foreach ($denominaciones as $valor => $cantidad) {
                for ($i = 0; $i < $cantidad; $i++) {
                    $id = (string) Str::uuid();
                    $firma = $this->cripto->firmar(Canonico::ticket($id, $valor, $cuenta->id, $ahora, $expiracion));
                    Ticket::create([
                        'id' => $id, 'id_cuenta' => $cuenta->id, 'denominacion' => $valor,
                        'emision' => $ahora, 'expiracion' => $expiracion, 'firma_b' => $firma,
                    ]);
                    $tickets[] = [
                        'id' => $id, 'denominacion' => $valor, 'id_cuenta' => $cuenta->id,
                        'emision' => $ahora, 'expiracion' => $expiracion, 'firma_b' => $firma,
                    ];
                    $ids[] = $id;
                }
            }
            sort($ids, SORT_STRING);   // mismo orden que usa la cadena canónica del recibo

            return [
                'tickets' => $tickets,
                'saldo' => $cuenta->saldo,
                'recibo' => [
                    'id_cuenta' => $cuenta->id,
                    'total' => $total,
                    'ids' => $ids,
                    'timestamp' => $ahora,
                    'firma_b' => $this->cripto->firmar(Canonico::reciboAdq($cuenta->id, $total, $ids, $ahora)),
                ],
            ];
        });
    }
}
