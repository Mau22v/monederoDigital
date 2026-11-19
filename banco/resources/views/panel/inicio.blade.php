@extends('panel.layout')

@section('titulo', 'Resumen del banco')

@section('contenido')
    @php
        $tarjetas = [
            ['Cuentas', $cuentas['total'], $cuentas['bloqueadas'] . ' bloqueadas ahora', route('panel.cuentas')],
            ['Tickets activos', $tickets['activos'], $pesos($tickets['valor_activo']) . ' en circulación', route('panel.tickets', ['estado' => 'ACTIVO'])],
            ['Tickets gastados', $tickets['gastados'], $tickets['expirados'] . ' expirados sin usar', route('panel.tickets', ['estado' => 'GASTADO'])],
            ['Canjes', $canjes['total'], $pesos($canjes['monto']) . ' cobrados por la tienda', route('panel.canjes')],
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
        <div class="col-lg-4">
            <h2 class="h5">Rechazos por código</h2>
            <div class="card shadow-sm">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Código</th><th class="text-end">Cantidad</th></tr></thead>
                    <tbody>
                    @forelse ($rechazosPorCodigo as $r)
                        <tr>
                            <td>
                                <a href="{{ route('panel.bitacora', ['resultado' => 'RECHAZADO', 'codigo' => $r->codigo]) }}">
                                    <code>{{ $r->codigo }}</code>
                                </a>
                            </td>
                            <td class="text-end">{{ $r->total }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-body-secondary">Todavía no hay rechazos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-8">
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
