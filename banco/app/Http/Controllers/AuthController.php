<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Services\Canonico;
use App\Services\CryptoService;
use App\Services\JwtService;
use App\Services\ProcesadorSobre;
use App\Support\ApiError;
use App\Support\Contexto;
use App\Support\Validar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** POST /api/registro y POST /api/login (contrato, sección 9). */
class AuthController extends Controller
{
    public function __construct(
        private readonly ProcesadorSobre $sobre,
        private readonly CryptoService $cripto,
        private readonly JwtService $jwt,
    ) {
    }

    public function registro(Request $request): JsonResponse
    {
        return $this->sobre->ejecutar($request, '/api/registro', [], function (Contexto $c): array {
            $correo   = Validar::correo($c->datos['correo'] ?? null);
            $password = Validar::passwordNueva($c->datos['password'] ?? null);
            $pubU     = Validar::pubU($c->datos['pub_u'] ?? null, $this->cripto);

            if (Cuenta::where('correo', $correo)->exists()) {
                throw new ApiError('CORREO_EN_USO');
            }

            // Cert_U: el banco certifica que esta Pub_U pertenece a esta cuenta.
            $id = (string) Str::uuid();
            $expiracion = $c->ahora + Cuenta::CERT_VIGENCIA_SEG;
            $firma = $this->cripto->firmar(Canonico::cert($id, $pubU, $expiracion));

            try {
                $cuenta = Cuenta::create([
                    'id' => $id,
                    'correo' => $correo,
                    'password_hash' => Hash::make($password),   // Argon2id; la contraseña no se guarda
                    'saldo' => Cuenta::SALDO_INICIAL,
                    'pub_u' => $pubU,
                    'cert_expiracion' => $expiracion,
                    'cert_firma_b' => $firma,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ApiError('CORREO_EN_USO');   // otra petición registró el mismo correo a la vez
            }

            $c->idCuenta = $cuenta->id;
            $c->detalle = 'Cuenta registrada';

            return [201, [
                'id_cuenta' => $cuenta->id,
                'saldo' => $cuenta->saldo,
                'cert_u' => $this->certU($cuenta),
            ]];
        });
    }

    public function login(Request $request): JsonResponse
    {
        return $this->sobre->ejecutar($request, '/api/login', [], function (Contexto $c): array {
            $correo   = Validar::correo($c->datos['correo'] ?? null);
            $password = Validar::passwordLogin($c->datos['password'] ?? null);

            $cuenta = Cuenta::where('correo', $correo)->first();

            if ($cuenta !== null && $cuenta->estaBloqueada($c->ahora)) {
                $c->idCuenta = $cuenta->id;
                throw new ApiError('DEMASIADOS_INTENTOS');
            }
            if ($cuenta === null) {
                Hash::make($password);   // gasta el mismo tiempo que una cuenta real: no delata si el correo existe
                throw new ApiError('CREDENCIALES_INVALIDAS');
            }
            if (!Hash::check($password, $cuenta->password_hash)) {
                $cuenta->registrarFalloLogin($c->ahora);
                $c->idCuenta = $cuenta->id;
                throw new ApiError('CREDENCIALES_INVALIDAS');
            }

            $cuenta->limpiarIntentos();
            [$token, $ttl] = $this->jwt->emitir($cuenta->id, $c->ahora);
            $c->idCuenta = $cuenta->id;
            $c->detalle = 'Inicio de sesión';

            return [200, [
                'token' => $token,
                'tipo' => 'Bearer',
                'expira_en' => $ttl,
                'id_cuenta' => $cuenta->id,
                'saldo' => $cuenta->saldo,
                'cert_u' => $this->certU($cuenta),
            ]];
        });
    }

    /** Cert_U tal como viaja (contrato, sección 8). */
    private function certU(Cuenta $cuenta): array
    {
        return [
            'id_cuenta' => $cuenta->id,
            'pub_u' => $cuenta->pub_u,
            'expiracion' => $cuenta->cert_expiracion,
            'firma_b' => $cuenta->cert_firma_b,
        ];
    }
}
