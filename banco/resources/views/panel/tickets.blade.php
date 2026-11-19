@extends('panel.layout')

@section('titulo', 'Tickets')

@section('contenido')
    <form method="get" action="{{ route('panel.tickets') }}" class="row g-2 align-items-end mb-3">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="cuenta">Cuenta (inicio del id)</label>
            <input class="form-control form-control-sm" id="cuenta" name="cuenta" maxlength="36"
                   value="{{ $filtros['cuenta'] }}" placeholder="p. ej. 388072f4">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="pedido">Pedido (inicio del id)</label>
            <input class="form-control form-control-sm" id="pedido" name="pedido" maxlength="36"
                   value="{{ $filtros['pedido'] }}">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="denominacion">Denominación</label>
            <select class="form-select form-select-sm" id="denominacion" name="denominacion">
                <option value="">Todas</option>
                @foreach ($denominaciones as $d)
                    <option value="{{ $d }}" @selected($filtros['denominacion'] === $d)>{{ $pesos($d) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="estado">Estado</label>
            <select class="form-select form-select-sm" id="estado" name="estado">
                <option value="">Todos</option>
                @foreach ($estados as $e)
                    <option value="{{ $e }}" @selected($filtros['estado'] === $e)>{{ $e }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('panel.tickets') }}">Limpiar</a>
        </div>
    </form>

    <h2 class="h6 text-body-secondary">Por denominación</h2>
    <div class="card shadow-sm table-responsive mb-4">
        <table class="table table-sm mb-0">
            <thead>
            <tr><th>Denominación</th><th class="text-end">Activos</th><th class="text-end">Gastados</th><th class="text-end">Expirados</th></tr>
            </thead>
            <tbody>
            @forelse ($resumen as $r)
                <tr>
                    <td>{{ $pesos($r->denominacion) }}</td>
                    <td class="text-end">{{ (int) $r->activos }}</td>
                    <td class="text-end">{{ (int) $r->gastados }}</td>
                    <td class="text-end">{{ (int) $r->expirados }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-body-secondary">Sin tickets.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="h6 text-body-secondary">Detalle</h2>
    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>Ticket</th><th>Cuenta</th><th class="text-end">Valor</th><th>Estado</th>
                <th>Emitido</th><th>Vence</th><th>Pedido que lo gastó</th><th>Gastado</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($tickets as $t)
                <tr>
                    <td><code>{{ $corto($t->id) }}</code></td>
                    <td><code>{{ $corto($t->id_cuenta) }}</code></td>
                    <td class="text-end">{{ $pesos($t->denominacion) }}</td>
                    <td>@include('panel._estado', ['valor' => $t->estadoEfectivo($ahora)])</td>
                    <td class="text-nowrap">{{ $fecha($t->emision) }}</td>
                    <td class="text-nowrap">{{ $fecha($t->expiracion) }}</td>
                    <td>
                        @if ($t->id_pedido)
                            <a href="{{ route('panel.canjes', ['pedido' => $corto($t->id_pedido)]) }}"><code>{{ $corto($t->id_pedido) }}</code></a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $fecha($t->gastado_en) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-body-secondary">No hay tickets con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $tickets])
@endsection
