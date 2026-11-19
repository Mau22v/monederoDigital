@extends('panel.layout')

@section('titulo', 'Pedidos')

@section('contenido')
    <form method="get" action="{{ route('panel.pedidos') }}" class="row g-2 align-items-end mb-3">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="estado">Estado</label>
            <select class="form-select form-select-sm" id="estado" name="estado">
                <option value="">Todos</option>
                @foreach ($estados as $e)
                    <option value="{{ $e }}" @selected($filtros['estado'] === $e)>{{ $e }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="pedido">Pedido (inicio del id)</label>
            <input class="form-control form-control-sm" id="pedido" name="pedido" maxlength="36"
                   value="{{ $filtros['pedido'] }}">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="cuenta">Cuenta (inicio del id)</label>
            <input class="form-control form-control-sm" id="cuenta" name="cuenta" maxlength="36"
                   value="{{ $filtros['cuenta'] }}">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-success" type="submit">Filtrar</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('panel.pedidos') }}">Limpiar</a>
        </div>
    </form>

    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>Pedido</th><th>Cuenta</th><th class="text-end">Monto</th><th>Estado</th><th>Productos</th>
                <th>Creado</th><th>Vence</th><th>Pagado</th><th class="text-end">Tickets</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($pedidos as $p)
                <tr>
                    <td><a href="{{ route('panel.pedido', ['prefijo' => $corto($p->id)]) }}"><code>{{ $corto($p->id) }}</code></a></td>
                    <td><code>{{ $corto($p->id_cuenta) }}</code></td>
                    <td class="text-end">{{ $pesos($p->monto) }}</td>
                    <td class="text-nowrap">
                        @include('panel._estado', ['valor' => $p->estadoEfectivo($ahora)])
                        @if ($p->tieneIntento())
                            <span class="badge text-bg-info">pago en curso</span>
                        @endif
                    </td>
                    <td>{{ $p->items->map(fn ($i) => $i->cantidad . '× ' . $i->nombre)->join(', ') }}</td>
                    <td class="text-nowrap">{{ $fecha($p->creado_en) }}</td>
                    <td class="text-nowrap">{{ $fecha($p->expiracion) }}</td>
                    <td class="text-nowrap">{{ $fecha($p->pagado_en) }}</td>
                    <td class="text-end">{{ count($p->idsTickets()) ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-body-secondary">No hay pedidos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $pedidos])
@endsection
