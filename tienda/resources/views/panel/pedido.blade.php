@extends('panel.layout')

@section('titulo', 'Pedido ' . $corto($pedido->id))

@section('contenido')
    @php
        $estado = $pedido->estadoEfectivo($ahora);
        $total = $items->sum(fn ($i) => $i->precio_unitario * $i->cantidad);
        $si = fn ($v) => $v ? 'sí' : 'no';
    @endphp

    <p><a class="small" href="{{ route('panel.pedidos') }}">← Volver a pedidos</a></p>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Estado</dt>
                        <dd class="col-7">
                            @include('panel._estado', ['valor' => $estado])
                            @if ($pedido->tieneIntento())
                                <span class="badge text-bg-info">pago en curso</span>
                            @endif
                        </dd>
                        <dt class="col-5">Cuenta</dt>
                        <dd class="col-7"><code>{{ $corto($pedido->id_cuenta) }}</code></dd>
                        <dt class="col-5">Monto</dt>
                        <dd class="col-7">{{ $pesos($pedido->monto) }}</dd>
                        <dt class="col-5">Creado</dt>
                        <dd class="col-7">{{ $fecha($pedido->creado_en) }}</dd>
                        <dt class="col-5">Vence</dt>
                        <dd class="col-7">{{ $fecha($pedido->expiracion) }}</dd>
                        <dt class="col-5">Pagado</dt>
                        <dd class="col-7">{{ $fecha($pedido->pagado_en) }}</dd>
                        <dt class="col-5">Recibo del banco</dt>
                        <dd class="col-7">{{ $fecha($pedido->recibo_ts) }}</dd>
                    </dl>
                </div>
            </div>

            <h2 class="h6 text-body-secondary mt-4">Firmas guardadas</h2>
            <div class="card shadow-sm">
                <table class="table table-sm mb-0">
                    <tbody>
                    <tr><td>Firma de la tienda sobre el pedido</td><td class="text-end">{{ $si($pedido->tiene_firma_t) }}</td></tr>
                    <tr><td>Comprobante de pago firmado por la tienda</td><td class="text-end">{{ $si($pedido->tiene_comprobante) }}</td></tr>
                    <tr><td>Recibo de canje firmado por el banco</td><td class="text-end">{{ $si($pedido->tiene_recibo) }}</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-body-secondary mt-2">El panel solo indica si cada firma existe; nunca muestra su contenido.</p>
        </div>

        <div class="col-lg-7">
            <h2 class="h6 text-body-secondary">Renglones</h2>
            <div class="card shadow-sm table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                    <tr><th>#</th><th>Producto</th><th class="text-end">Precio</th><th class="text-end">Cantidad</th><th class="text-end">Subtotal</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($items as $i)
                        <tr>
                            <td class="text-body-secondary">{{ $i->id_producto }}</td>
                            <td>{{ $i->nombre }}</td>
                            <td class="text-end">{{ $pesos($i->precio_unitario) }}</td>
                            <td class="text-end">{{ $i->cantidad }}</td>
                            <td class="text-end">{{ $pesos($i->precio_unitario * $i->cantidad) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="fw-semibold"><td colspan="4" class="text-end">Total</td><td class="text-end">{{ $pesos($total) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-body-secondary mt-2">Nombre y precio son una copia del momento en que se creó el pedido.</p>

            <h2 class="h6 text-body-secondary mt-4">
                Tickets {{ $estado === 'PAGADO' ? 'usados en el pago' : 'del intento de pago' }}
            </h2>
            @if ($tickets === [])
                <p class="text-body-secondary">Ninguno.</p>
            @else
                <p class="mb-1">
                    @foreach ($tickets as $t)
                        <code class="me-2">{{ $corto($t) }}</code>
                    @endforeach
                </p>
                @if ($estado === 'PAGADO' && $urlBanco !== '')
                    <a class="small" href="{{ $urlBanco . '/panel/canjes?pedido=' . $corto($pedido->id) }}">Ver el canje en el panel del banco →</a>
                @endif
            @endif
        </div>
    </div>
@endsection
