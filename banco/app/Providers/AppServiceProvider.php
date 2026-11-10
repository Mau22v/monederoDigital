<?php

namespace App\Providers;

use App\Services\CryptoService;
use App\Services\JwtService;
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

        // Tokens de sesión (JWT). Exige JWT_SECRET de al menos 32 caracteres en el .env.
        $this->app->singleton(JwtService::class, fn () => new JwtService(
            (string) config('seguridad.jwt_secret'),
            (int) config('seguridad.jwt_ttl'),
        ));
    }

    public function boot(): void
    {
    }
}
