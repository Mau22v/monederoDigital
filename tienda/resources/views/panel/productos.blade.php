@extends('panel.layout')

@section('titulo', 'Productos')

@section('contenido')
    <p class="text-body-secondary small">
        El catálogo se publica firmado por la tienda en <code>GET /api/catalogo</code>. Cada precio puede pagarse
        con dinero exacto usando tickets de $500, $300, $100, $50 y $20 (no hay cambio).
        Las ventas cuentan solo pedidos PAGADOS, con el precio que tenía el producto al crear el pedido.
    </p>

    <div class="card shadow-sm table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>#</th><th>Nombre</th><th class="text-end">Precio</th><th>En catálogo</th>
                <th class="text-end">Unidades vendidas</th><th class="text-end">Ingresos</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($productos as $p)
                @php $venta = $ventas->get($p->id); @endphp
                <tr>
                    <td class="text-body-secondary">{{ $p->id }}</td>
                    <td>{{ $p->nombre }}</td>
                    <td class="text-end">{{ $pesos($p->precio) }}</td>
                    <td>
                        @if ($p->activo)
                            <span class="badge text-bg-success">SÍ</span>
                        @else
                            <span class="badge text-bg-secondary">RETIRADO</span>
                        @endif
                    </td>
                    <td class="text-end">{{ (int) ($venta->unidades ?? 0) }}</td>
                    <td class="text-end">{{ $pesos($venta->ingresos ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-body-secondary">No hay productos. Ejecuta <code>php artisan db:seed</code>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('panel._paginacion', ['p' => $productos])
@endsection
