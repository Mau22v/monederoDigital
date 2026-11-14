<?php

namespace App\Providers;

use App\Services\BancoCliente;
use App\Services\CertificadoUsuario;
use App\Services\CryptoService;
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

        // Para verificar Cert_U la tienda necesita la llave pública EC del banco (carpeta claves-publicas/ del repositorio).
        $this->app->singleton(CertificadoUsuario::class, function ($app) {
            $ruta = config('seguridad.banco_ec_pub');
            if (!is_file($ruta)) {
                throw new RuntimeException('Falta la llave pública del banco: ' . $ruta);
            }

            return new CertificadoUsuario($app->make(CryptoService::class), file_get_contents($ruta));
        });

        // Cliente HTTP hacia el banco: necesita su URL (BANCO_URL en .env) y su llave pública RSA para cifrar.
        $this->app->singleton(BancoCliente::class, function ($app) {
            $ruta = config('seguridad.banco_rsa_pub');
            if (!is_file($ruta)) {
                throw new RuntimeException('Falta la llave pública RSA del banco: ' . $ruta);
            }

            return new BancoCliente($app->make(CryptoService::class), (string) config('seguridad.banco_url'), file_get_contents($ruta));
        });
    }

    public function boot(): void
    {
    }
}
