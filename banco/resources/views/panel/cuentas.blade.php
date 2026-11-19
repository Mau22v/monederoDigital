@extends('panel.layout')

@section('titulo', 'Cuentas')

@section('contenido')
    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>Cuenta</th><th>Correo</th><th class="text-end">Saldo</th>
                <th class="text-end">Tickets activos</th><th class="text-end">Tickets gastados</th>
                <th class="text-end">Intentos fallidos</th><th>Bloqueada hasta</th><th>Creada</th><th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($cuentas as $c)
                @php $bloqueada = $c->bloqueada_hasta !== null && $c->bloqueada_hasta > $ahora; @endphp
                <tr>
                    <td><code>{{ $corto($c->id) }}</code></td>
                    <td>{{ $c->correo }}</td>
                    <td class="text-end">{{ $pesos($c->saldo) }}</td>
                    <td class="text-end">{{ $c->tickets_activos }}</td>
                    <td class="text-end">{{ $c->tickets_gastados }}</td>
                    <td class="text-end">{{ $c->intentos_fallidos }}</td>
                    <td class="text-nowrap">
                        @if ($bloqueada)
                            <span class="badge text-bg-danger">BLOQUEADA</span> {{ $fecha($c->bloqueada_hasta) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $fecha($c->created_at) }}</td>
                    <td class="text-nowrap small">
                        <a href="{{ route('panel.tickets', ['cuenta' => $corto($c->id)]) }}">tickets</a> ·
                        <a href="{{ route('panel.bitacora', ['cuenta' => $corto($c->id)]) }}">bitácora</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-body-secondary">Todavía no hay cuentas registradas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $cuentas])
@endsection
