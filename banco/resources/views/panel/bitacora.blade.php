@extends('panel.layout')

@section('titulo', 'Bitácora')

@section('contenido')
    <form method="get" action="{{ route('panel.bitacora') }}" class="row g-2 align-items-end mb-3">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="resultado">Resultado</label>
            <select class="form-select form-select-sm" id="resultado" name="resultado">
                <option value="">Todos</option>
                @foreach ($resultados as $r)
                    <option value="{{ $r }}" @selected($filtros['resultado'] === $r)>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="codigo">Código</label>
            <select class="form-select form-select-sm" id="codigo" name="codigo">
                <option value="">Todos</option>
                @foreach ($codigos as $c)
                    <option value="{{ $c }}" @selected($filtros['codigo'] === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="endpoint">Endpoint</label>
            <select class="form-select form-select-sm" id="endpoint" name="endpoint">
                <option value="">Todos</option>
                @foreach ($endpoints as $e)
                    <option value="{{ $e }}" @selected($filtros['endpoint'] === $e)>{{ $e }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="cuenta">Cuenta (inicio del id)</label>
            <input class="form-control form-control-sm" id="cuenta" name="cuenta" maxlength="36"
                   value="{{ $filtros['cuenta'] }}" placeholder="p. ej. 388072f4">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('panel.bitacora') }}">Limpiar</a>
        </div>
    </form>

    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>#</th><th>Fecha</th><th>Endpoint</th><th>Resultado</th><th>Código</th>
                <th>Cuenta</th><th>Detalle</th><th>IP</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($filas as $f)
                <tr>
                    <td class="text-body-secondary">{{ $f->id }}</td>
                    <td class="text-nowrap">{{ $fecha($f->momento) }}</td>
                    <td><code>{{ $f->endpoint }}</code></td>
                    <td>@include('panel._estado', ['valor' => $f->resultado])</td>
                    <td><code>{{ $f->codigo ?? '—' }}</code></td>
                    <td><code>{{ $corto($f->id_cuenta) }}</code></td>
                    {{-- El detalle puede traer texto que vino de la red: siempre escapado con {{ }}. --}}
                    <td class="text-break">{{ $f->detalle ?? '' }}</td>
                    <td class="text-nowrap small">{{ $f->ip ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-body-secondary">No hay eventos con esos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $filas])
@endsection
