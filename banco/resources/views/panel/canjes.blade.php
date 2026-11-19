@extends('panel.layout')

@section('titulo', 'Canjes')

@section('contenido')
    <p class="text-body-secondary small">
        Cada canje es un pedido de la tienda pagado con tickets: el banco marca los tickets como GASTADOS
        y firma un recibo. Un pedido no puede canjearse dos veces.
    </p>

    <form method="get" action="{{ route('panel.canjes') }}" class="row g-2 align-items-end mb-3">
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
            <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('panel.canjes') }}">Limpiar</a>
        </div>
    </form>

    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr><th>Pedido</th><th>Cuenta</th><th class="text-end">Monto</th><th>Fecha</th><th>Tickets usados</th></tr>
            </thead>
            <tbody>
            @forelse ($canjes as $c)
                <tr>
                    <td><code>{{ $corto($c->id_pedido) }}</code></td>
                    <td><code>{{ $corto($c->id_cuenta) }}</code></td>
                    <td class="text-end">{{ $pesos($c->monto) }}</td>
                    <td class="text-nowrap">{{ $fecha($c->momento) }}</td>
                    <td>
                        <a href="{{ route('panel.tickets', ['pedido' => $corto($c->id_pedido)]) }}">{{ $c->tickets->count() }}</a>:
                        {{ $c->tickets->map(fn ($t) => $pesos($t->denominacion))->join(' + ') }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-body-secondary">Todavía no hay canjes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $canjes])
@endsection
