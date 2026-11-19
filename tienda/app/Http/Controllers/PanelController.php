<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel web de SOLO LECTURA de la tienda (Fase 9, docs/Guia_Paneles_Web.md).
 *
 * Reglas de seguridad que cumple:
 * - Solo consulta: ningún método escribe en la base (las rutas son únicamente GET).
 * - Nunca carga secretos: cada consulta elige columnas explícitas, así firma_t, nonce_t,
 *   firma_comprobante y recibo_firma_b no llegan ni a la vista (solo se indica si existen).
 * - Los ids se muestran recortados a 8 caracteres.
 * - Los filtros solo aceptan valores conocidos; un valor desconocido se ignora (no hay error 500).
 */
class PanelController extends Controller
{
    private const POR_PAGINA = 25;

    /** La base guarda segundos Unix; se muestran en la hora local de la demo. */
    private const ZONA_HORARIA = 'America/Mexico_City';

    private const RESULTADOS = ['ACEPTADO', 'RECHAZADO'];
    private const ESTADOS_PEDIDO = [Pedido::CREADO, Pedido::PAGADO, Pedido::EXPIRADO, Pedido::RECHAZADO];

    /** Escenario de prueba de cada código de error (contrato, sección 12). */
    private const ESCENARIOS = [
        'TICKET_INVALIDO'        => 'E1',
        'TICKET_GASTADO'         => 'E2',
        'FIRMA_INVALIDA'         => 'E3',
        'MENSAJE_CORRUPTO'       => 'E4',
        'CREDENCIALES_INVALIDAS' => 'E5',
        'DEMASIADOS_INTENTOS'    => 'E5',
    ];

    /** Columnas de pedidos que el panel puede leer: nunca firmas ni nonce_t. */
    private const COLUMNAS_PEDIDO = ['id', 'id_cuenta', 'monto', 'creado_en', 'expiracion', 'estado', 'ids_tickets', 'pagado_en', 'recibo_ts'];

    public function inicio(): View
    {
        $ahora = time();
        $pagados = $this->conEstado(Pedido::query(), Pedido::PAGADO, $ahora);

        return $this->vista('inicio', [
            'productos' => [
                'activos' => Producto::query()->where('activo', true)->count(),
                'total'   => Producto::query()->count(),
            ],
            'pedidos' => [
                'total'     => Pedido::query()->count(),
                'creados'   => $this->conEstado(Pedido::query(), Pedido::CREADO, $ahora)->count(),
                'pagados'   => (clone $pagados)->count(),
                'expirados' => $this->conEstado(Pedido::query(), Pedido::EXPIRADO, $ahora)->count(),
                'ingresos'  => (int) (clone $pagados)->sum('monto'),
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

    public function productos(): View
    {
        // Unidades e ingresos de pedidos PAGADOS (con el precio copiado en el renglón al crear el pedido).
        $ventas = PedidoItem::query()
            ->join('pedidos', 'pedidos.id', '=', 'pedido_items.id_pedido')
            ->where('pedidos.estado', Pedido::PAGADO)
            ->groupBy('pedido_items.id_producto')
            ->selectRaw('pedido_items.id_producto, sum(pedido_items.cantidad) as unidades, sum(pedido_items.cantidad * pedido_items.precio_unitario) as ingresos')
            ->get()
            ->keyBy('id_producto');

        $productos = Producto::query()
            ->select(['id', 'nombre', 'precio', 'activo'])
            ->orderBy('id')
            ->paginate(self::POR_PAGINA);

        return $this->vista('productos', compact('productos', 'ventas'));
    }

    public function pedidos(Request $request): View
    {
        $ahora = time();

        $filtros = [
            'estado' => $this->opcion($request, 'estado', self::ESTADOS_PEDIDO),
            'cuenta' => $this->prefijo($request, 'cuenta'),
            'pedido' => $this->prefijo($request, 'pedido'),
        ];

        $pedidos = Pedido::query()
            ->select(self::COLUMNAS_PEDIDO)
            ->with(['items' => fn ($q) => $q->select(['id', 'id_pedido', 'nombre', 'cantidad'])->orderBy('id')])
            ->when($filtros['estado'], fn ($q, $v) => $this->conEstado($q, $v, $ahora))
            ->when($filtros['cuenta'], fn ($q, $v) => $q->where('id_cuenta', 'like', $v . '%'))
            ->when($filtros['pedido'], fn ($q, $v) => $q->where('id', 'like', $v . '%'))
            ->orderByDesc('creado_en')
            ->paginate(self::POR_PAGINA)
            ->appends(array_filter($filtros));

        return $this->vista('pedidos', compact('pedidos', 'filtros', 'ahora') + [
            'estados' => self::ESTADOS_PEDIDO,
        ]);
    }

    public function pedido(string $prefijo): View|RedirectResponse
    {
        $coincidencias = Pedido::query()
            ->select(self::COLUMNAS_PEDIDO)
            // Solo se pregunta si las firmas existen; su contenido nunca sale de la base.
            ->selectRaw('firma_t is not null as tiene_firma_t')
            ->selectRaw('firma_comprobante is not null as tiene_comprobante')
            ->selectRaw('recibo_firma_b is not null as tiene_recibo')
            ->where('id', 'like', $prefijo . '%')
            ->limit(2)
            ->get();

        abort_if($coincidencias->isEmpty(), 404);
        if ($coincidencias->count() > 1) {
            // Dos pedidos con el mismo inicio de id (muy raro): se muestran en la lista filtrada.
            return redirect()->route('panel.pedidos', ['pedido' => $prefijo]);
        }

        $pedido = $coincidencias->first();
        $items = $pedido->items()
            ->select(['id', 'id_producto', 'nombre', 'precio_unitario', 'cantidad'])
            ->orderBy('id')
            ->get();

        return $this->vista('pedido', [
            'pedido' => $pedido,
            'items' => $items,
            'ahora' => time(),
            'tickets' => $pedido->idsTickets(),
            'urlBanco' => rtrim((string) config('seguridad.banco_url'), '/'),
        ]);
    }

    /** Aplica el estado efectivo de un pedido como condición SQL (igual que Pedido::estadoEfectivo). */
    private function conEstado(Builder $q, string $estado, int $ahora): Builder
    {
        return match ($estado) {
            Pedido::CREADO    => $q->where('estado', Pedido::CREADO)->where('expiracion', '>', $ahora),
            Pedido::EXPIRADO  => $q->where('estado', Pedido::CREADO)->where('expiracion', '<=', $ahora),
            Pedido::PAGADO    => $q->where('estado', Pedido::PAGADO),
            Pedido::RECHAZADO => $q->where('estado', Pedido::RECHAZADO),
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
            'escenario' => fn (?string $codigo): ?string => self::ESCENARIOS[$codigo ?? ''] ?? null,
            'zona'  => self::ZONA_HORARIA,
            'generado' => time(),
        ]);
    }
}
