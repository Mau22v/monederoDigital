<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Defensa adicional contra XSS: la página no ejecuta ningún script y solo carga el CSS de Bootstrap. --}}
    <meta http-equiv="Content-Security-Policy"
          content="default-src 'none'; style-src https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; base-uri 'none'">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titulo') · Panel de la Tienda</title>
    {{-- integrity (SRI): si el archivo del CDN cambia, el navegador no lo aplica. --}}
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
</head>
<body class="bg-body-tertiary">
@php
    $secciones = [
        'panel.inicio'    => 'Inicio',
        'panel.bitacora'  => 'Bitácora',
        'panel.productos' => 'Productos',
        'panel.pedidos'   => 'Pedidos',
    ];
@endphp
<nav class="navbar navbar-expand bg-success" data-bs-theme="dark">
    <div class="container-fluid flex-wrap">
        <a class="navbar-brand fw-semibold" href="{{ route('panel.inicio') }}">Tienda · Panel</a>
        <ul class="navbar-nav flex-wrap">
            @foreach ($secciones as $ruta => $nombre)
                @php $activa = request()->routeIs($ruta) || ($ruta === 'panel.pedidos' && request()->routeIs('panel.pedido')); @endphp
                <li class="nav-item">
                    <a class="nav-link {{ $activa ? 'active fw-semibold' : '' }}" href="{{ route($ruta) }}">{{ $nombre }}</a>
                </li>
            @endforeach
        </ul>
        <span class="navbar-text small ms-auto">Solo lectura</span>
    </div>
</nav>

<main class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">@yield('titulo')</h1>
        <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrl() }}">Actualizar</a>
    </div>

    @yield('contenido')
</main>

<footer class="container-fluid pb-4 small text-body-secondary">
    Datos al {{ $fecha($generado) }} (hora {{ $zona }}). Los ids se muestran recortados a 8 caracteres;
    el panel nunca muestra llaves, nonces ni firmas.
</footer>
</body>
</html>
