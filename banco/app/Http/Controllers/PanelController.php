<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Canje;
use App\Models\Cuenta;
use App\Models\Ticket;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel web de SOLO LECTURA del banco (Fase 9, docs/Guia_Paneles_Web.md).
 *
 * Reglas de seguridad que cumple:
 * - Solo consulta: ningún método escribe en la base (las rutas son únicamente GET).
 * - Nunca carga secretos: cada consulta elige columnas explícitas, así password_hash, pub_u,
 *   cert_firma_b y firma_b no llegan ni a la vista.
 * - Los ids se muestran recortados a 8 caracteres.
 * - Los filtros solo aceptan valores conocidos; un valor desconocido se ignora (no hay error 500).
 */
class PanelController extends Controller
{
    private const POR_PAGINA = 25;

    /** La base guarda segundos Unix; se muestran en la hora local de la demo. */
    private const ZONA_HORARIA = 'America/Mexico_City';

    private const RESULTADOS = ['ACEPTADO', 'RECHAZADO'];
    private const ESTADOS_TICKET = [Ticket::ACTIVO, Ticket::GASTADO, Ticket::EXPIRADO];

    public function inicio(): View
    {
        $ahora = time();

        $tickets = [
            'activos'      => $this->conEstado(Ticket::query(), Ticket::ACTIVO, $ahora)->count(),
            'gastados'     => $this->conEstado(Ticket::query(), Ticket::GASTADO, $ahora)->count(),
            'expirados'    => $this->conEstado(Ticket::query(), Ticket::EXPIRADO, $ahora)->count(),
            'valor_activo' => (int) $this->conEstado(Ticket::query(), Ticket::ACTIVO, $ahora)->sum('denominacion'),
        ];

        return $this->vista('inicio', [
            'cuentas' => [
                'total'      => Cuenta::query()->count(),
                'bloqueadas' => Cuenta::query()->where('bloqueada_hasta', '>', $ahora)->count(),
            ],
            'tickets' => $tickets,
            'canjes' => [
                'total' => Canje::query()->count(),
                'monto' => (int) Canje::query()->sum('monto'),
            ],
            'bitacora' => [
                'aceptados'  => Bitacora::query()->where('resultado', 'ACEPTADO')->count(),
                'rechazados' => Bitacora::query()->where('resultado', 'RECHAZADO')->count(),
            ],
            'rechazosPorCodigo' => Bitacora::query()
                ->where('resultado', 'RECHAZADO')
                ->selectRaw('codigo, count(*) as total')
                ->groupBy('codigo')
                ->orderByDesc('total')
                ->get(),
            'ultimos' => Bitacora::query()
                ->select(['id', 'momento', 'endpoint', 'resultado', 'codigo', 'id_cuenta'])
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }

    public function bitacora(Request $request): View
    {
        $codigos = Bitacora::query()->whereNotNull('codigo')->distinct()->orderBy('codigo')->pluck('codigo')->all();
        $endpoints = Bitacora::query()->distinct()->orderBy('endpoint')->pluck('endpoint')->all();

        $filtros = [
            'resultado' => $this->opcion($request, 'resultado', self::RESULTADOS),
            'codigo'    => $this->opcion($request, 'codigo', $codigos),
            'endpoint'  => $this->opcion($request, 'endpoint', $endpoints),
            'cuenta'    => $this->prefijo($request, 'cuenta'),
        ];

        $filas = Bitacora::query()
            ->select(['id', 'momento', 'endpoint', 'resultado', 'codigo', 'id_cuenta', 'detalle', 'ip'])
            ->when($filtros['resultado'], fn ($q, $v) => $q->where('resultado', $v))
            ->when($filtros['codigo'], fn ($q, $v) => $q->where('codigo', $v))
            ->when($filtros['endpoint'], fn ($q, $v) => $q->where('endpoint', $v))
            ->when($filtros['cuenta'], fn ($q, $v) => $q->where('id_cuenta', 'like', $v . '%'))
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->appends(array_filter($filtros));

        return $this->vista('bitacora', compact('filas', 'filtros', 'codigos', 'endpoints') + [
            'resultados' => self::RESULTADOS,
        ]);
    }

    public function cuentas(): View
    {
        $ahora = time();

        $cuentas = Cuenta::query()
            ->select(['id', 'correo', 'saldo', 'intentos_fallidos', 'bloqueada_hasta', 'created_at'])
            ->withCount([
                'tickets as tickets_activos' => fn (Builder $q) => $this->conEstado($q, Ticket::ACTIVO, $ahora),
                'tickets as tickets_gastados' => fn (Builder $q) => $this->conEstado($q, Ticket::GASTADO, $ahora),
            ])
            ->orderByDesc('created_at')
            ->paginate(self::POR_PAGINA);

        return $this->vista('cuentas', compact('cuentas', 'ahora'));
    }

    public function tickets(Request $request): View
    {
        $ahora = time();
        $denominaciones = array_map('strval', Ticket::DENOMINACIONES);

        $filtros = [
            'cuenta'       => $this->prefijo($request, 'cuenta'),
            'pedido'       => $this->prefijo($request, 'pedido'),
            'denominacion' => $this->opcion($request, 'denominacion', $denominaciones),
            'estado'       => $this->opcion($request, 'estado', self::ESTADOS_TICKET),
        ];

        // Filtros comunes al resumen y a la lista (el estado solo se aplica a la lista).
        $base = Ticket::query()
            ->when($filtros['cuenta'], fn ($q, $v) => $q->where('id_cuenta', 'like', $v . '%'))
            ->when($filtros['pedido'], fn ($q, $v) => $q->where('id_pedido', 'like', $v . '%'))
            ->when($filtros['denominacion'], fn ($q, $v) => $q->where('denominacion', (int) $v));

        // Cantidad de tickets por denominación y estado efectivo (EXPIRADO se calcula con la fecha).
        $resumen = (clone $base)->toBase()
            ->select('denominacion')
            ->selectRaw('sum(case when estado = ? and expiracion > ? then 1 else 0 end) as activos', [Ticket::ACTIVO, $ahora])
            ->selectRaw('sum(case when estado = ? and expiracion <= ? then 1 else 0 end) as expirados', [Ticket::ACTIVO, $ahora])
            ->selectRaw('sum(case when estado = ? then 1 else 0 end) as gastados', [Ticket::GASTADO])
            ->groupBy('denominacion')
            ->orderByDesc('denominacion')
            ->get();

        $tickets = (clone $base)
            ->select(['id', 'id_cuenta', 'denominacion', 'emision', 'expiracion', 'estado', 'id_pedido', 'gastado_en'])
            ->when($filtros['estado'], fn ($q, $v) => $this->conEstado($q, $v, $ahora))
            ->orderByDesc('emision')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->appends(array_filter($filtros));

        return $this->vista('tickets', compact('tickets', 'resumen', 'filtros', 'denominaciones', 'ahora') + [
            'estados' => self::ESTADOS_TICKET,
        ]);
    }

    public function canjes(Request $request): View
    {
        $filtros = [
            'cuenta' => $this->prefijo($request, 'cuenta'),
            'pedido' => $this->prefijo($request, 'pedido'),
        ];

        $canjes = Canje::query()
            ->select(['id_pedido', 'id_cuenta', 'monto', 'momento'])
            ->with(['tickets' => fn ($q) => $q->select(['id', 'id_pedido', 'denominacion'])->orderByDesc('denominacion')])
            ->when($filtros['cuenta'], fn ($q, $v) => $q->where('id_cuenta', 'like', $v . '%'))
            ->when($filtros['pedido'], fn ($q, $v) => $q->where('id_pedido', 'like', $v . '%'))
            ->orderByDesc('momento')
            ->paginate(self::POR_PAGINA)
            ->appends(array_filter($filtros));

        return $this->vista('canjes', compact('canjes', 'filtros'));
    }

    /** Aplica el estado efectivo de un ticket como condición SQL (igual que Ticket::estadoEfectivo). */
    private function conEstado(Builder $q, string $estado, int $ahora): Builder
    {
        return match ($estado) {
            Ticket::GASTADO  => $q->where('estado', Ticket::GASTADO),
            Ticket::ACTIVO   => $q->where('estado', Ticket::ACTIVO)->where('expiracion', '>', $ahora),
            Ticket::EXPIRADO => $q->where('estado', Ticket::ACTIVO)->where('expiracion', '<=', $ahora),
        };
    }

    /** Devuelve el valor del filtro solo si está en la lista de permitidos. */
    private function opcion(Request $request, string $clave, array $permitidos): ?string
    {
        $valor = $request->query($clave);

        return is_string($valor) && in_array($valor, $permitidos, true) ? $valor : null;
    }

    /** Prefijo de un UUID (por ejemplo, los 8 caracteres que muestra el panel). Solo hexadecimal y guiones. */
    private function prefijo(Request $request, string $clave): ?string
    {
        $valor = $request->query($clave);
        if (!is_string($valor)) {
            return null;
        }
        $valor = strtolower(trim($valor));

        return preg_match('/^[0-9a-f-]{1,36}$/', $valor) === 1 ? $valor : null;
    }

    /** Agrega a cada vista los ayudantes de formato. */
    private function vista(string $nombre, array $datos): View
    {
        return view('panel.' . $nombre, $datos + [
            'fecha' => function (int|DateTimeInterface|null $t): string {
                if ($t === null) {
                    return '—';
                }
                $segundos = $t instanceof DateTimeInterface ? $t->getTimestamp() : $t;

                return Carbon::createFromTimestamp($segundos, self::ZONA_HORARIA)->format('Y-m-d H:i:s');
            },
            'corto' => fn (?string $id): string => $id === null ? '—' : substr($id, 0, 8),
            'pesos' => fn (int|string|null $n): string => '$' . number_format((int) $n),
            'zona'  => self::ZONA_HORARIA,
            'generado' => time(),
        ]);
    }
}
