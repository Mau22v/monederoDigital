<?php

namespace App\Providers;

use App\Services\CryptoService;
use App\Services\CertificadoUsuario;
use Illuminate\Support\ServiceProvider;
use RuntimeExeption;


class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una sola instancia de CryptoService solo una vez, con las llaves privadas propias leídas de storage/app/keys.
        $this->app->singleton(CryptoService::class, function () {
            $leer = fn (?string $ruta) => ($ruta !== null && is_file($ruta)) ? file_get_contents($ruta) : null;

            return new CryptoService(
                $leer(config('seguridad.ec_priv')),
                $leer(config('seguridad.rsa_priv')),
            );
        });

        //Para verificar Cert_U la tienda necesita la llave publica EC del banco(Carpeta claves ppublicas)
        $this->app->singleton(CertificadoUsuario::class, function ($app){
            $ruta = config('seguridad.banco_ec_pub');
            if(!is_file($ruta)){
                throw new RuntimeExeption('Falta la llave del banco: ' .$ruta);
            }

            return new CertificadoUsuario($app->make(CryptoService::class), file_get_contents($ruta));
        });
    }

    public function boot(): void
    {
    }
}
