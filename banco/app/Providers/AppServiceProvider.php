<?php

namespace App\Providers;

use App\Services\CryptoService;
use App\Services\FirmaTienda;
use App\Services\JwtService;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        // Para verificar las firmas de la tienda (/api/canje) el banco necesita su llave pública EC (carpeta claves-publicas/).
        $this->app->singleton(FirmaTienda::class, function ($app) {
            $ruta = config('seguridad.tienda_ec_pub');
            if (!is_file($ruta)) {
                throw new RuntimeException('Falta la llave pública de la tienda: ' . $ruta);
            }

            return new FirmaTienda($app->make(CryptoService::class), file_get_contents($ruta));
        });
    }

    public function boot(): void
    {
    }
}
