<?php

namespace App\Providers;

use App\Services\CryptoService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una sola instancia de CryptoService, con las llaves privadas propias leídas de storage/app/keys.
        $this->app->singleton(CryptoService::class, function () {
            $leer = fn (?string $ruta) => ($ruta !== null && is_file($ruta)) ? file_get_contents($ruta) : null;

            return new CryptoService(
                $leer(config('seguridad.ec_priv')),
                $leer(config('seguridad.rsa_priv')),
            );
        });
    }

    public function boot(): void
    {
    }
}
