@extends('panel.layout')

@section('titulo', 'Resumen de la tienda')

@section('contenido')
    @php
        $tarjetas = [
            ['Productos en el catálogo', $productos['activos'], 'de ' . $productos['total'] . ' registrados', route('panel.productos')],
            ['Pedidos pagados', $pedidos['pagados'], $pesos($pedidos['ingresos']) . ' cobrados con tickets', route('panel.pedidos', ['estado' => 'PAGADO'])],
            ['Pedidos por pagar', $pedidos['creados'], 'vigentes (vencen a los 10 min)', route('panel.pedidos', ['estado' => 'CREADO'])],
            ['Pedidos expirados', $pedidos['expirados'], 'vencieron sin pagarse', route('panel.pedidos', ['estado' => 'EXPIRADO'])],
            ['Peticiones aceptadas', $bitacora['aceptados'], 'en la bitácora', route('panel.bitacora', ['resultado' => 'ACEPTADO'])],
            ['Peticiones rechazadas', $bitacora['rechazados'], 'en la bitácora', route('panel.bitacora', ['resultado' => 'RECHAZADO'])],
        ];
    @endphp

    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-4">
        @foreach ($tarjetas as [$nombre, $valor, $nota, $enlace])
            <div class="col">
                <a class="card h-100 text-decoration-none shadow-sm" href="{{ $enlace }}">
                    <div class="card-body">
                        <div class="text-body-secondary small">{{ $nombre }}</div>
                        <div class="display-6 fw-semibold text-body">{{ $valor }}</div>
                        <div class="small text-body-secondary">{{ $nota }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <h2 class="h5">Rechazos por código</h2>
            <div class="card shadow-sm">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Código</th><th>Escenario</th><th class="text-end">Cantidad</th></tr></thead>
                    <tbody>
                    @forelse ($rechazosPorCodigo as $r)
                        <tr>
                            <td>
                                <a href="{{ route('panel.bitacora', ['resultado' => 'RECHAZADO', 'codigo' => $r->codigo]) }}">
                                    <code>{{ $r->codigo }}</code>
                                </a>
                            </td>
                            <td>
                                @if ($escenario($r->codigo))
                                    <span class="badge text-bg-dark">{{ $escenario($r->codigo) }}</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $r->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-body-secondary">Todavía no hay rechazos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="small text-body-secondary mt-2 mb-0">
                Escenarios de ataque del contrato (sección 12): E1 ticket alterado, E2 doble gasto,
                E3 firma falsa, E4 mensaje cifrado modificado.
            </p>
        </div>

        <div class="col-lg-7">
            <h2 class="h5">Últimos eventos</h2>
            <div class="card shadow-sm table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Fecha</th><th>Endpoint</th><th>Resultado</th><th>Código</th><th>Cuenta</th></tr></thead>
                    <tbody>
                    @forelse ($ultimos as $e)
                        <tr>
                            <td class="text-nowrap">{{ $fecha($e->momento) }}</td>
                            <td><code>{{ $e->endpoint }}</code></td>
                            <td>@include('panel._estado', ['valor' => $e->resultado])</td>
                            <td><code>{{ $e->codigo ?? '—' }}</code></td>
                            <td><code>{{ $corto($e->id_cuenta) }}</code></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-body-secondary">La bitácora está vacía.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <a class="small" href="{{ route('panel.bitacora') }}">Ver toda la bitácora →</a>
        </div>
    </div>
@endsection
