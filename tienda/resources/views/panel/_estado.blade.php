{{-- Etiqueta de color para un resultado o estado. Recibe $valor. --}}
@php
    $clase = match ($valor) {
        'ACEPTADO', 'PAGADO' => 'text-bg-success',
        'RECHAZADO'          => 'text-bg-danger',
        'CREADO'             => 'text-bg-primary',
        'EXPIRADO'           => 'text-bg-warning',
        default              => 'text-bg-light',
    };
@endphp
<span class="badge {{ $clase }}">{{ $valor }}</span>
