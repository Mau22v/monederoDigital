{{-- Etiqueta de color para un resultado o estado. Recibe $valor. --}}
@php
    $clase = match ($valor) {
        'ACEPTADO', 'ACTIVO' => 'text-bg-success',
        'RECHAZADO'          => 'text-bg-danger',
        'GASTADO'            => 'text-bg-secondary',
        'EXPIRADO'           => 'text-bg-warning',
        default              => 'text-bg-light',
    };
@endphp
<span class="badge {{ $clase }}">{{ $valor }}</span>
