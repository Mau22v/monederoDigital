<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de la tienda hacia el banco (contrato, sección 9). Cifra con la RSA pública del banco,
 * firma con la EC de la tienda y descifra la respuesta con la misma K.
 */
class BancoCliente
{
    public const TIEMPO_MAXIMO_SEG = 10;

    public function __construct(
        protected readonly CryptoService $cripto,
        protected readonly string $baseUrl,
        protected readonly string $bancoRsaPubPem,
    ) {
    }

    /**
     * POST /api/canje. $orden es la orden firmada por el cliente: nonce, timestamp y firma_u.
     * @param string[] $ids
     * @return array el recibo del banco
     * @throws BancoRechazo|BancoNoDisponible
     */
    public function canjear(string $idPedido, int $monto, array $ids, array $orden, int $ahora): array
    {
        sort($ids, SORT_STRING);
        $nonce = $this->cripto->nonce();
        $cuerpo = [
            'id_pedido' => $idPedido, 'monto' => $monto, 'ids' => $ids,
            'orden' => ['nonce' => $orden['nonce'], 'timestamp' => $orden['timestamp'], 'firma_u' => $orden['firma_u']],
            'nonce' => $nonce, 'timestamp' => $ahora,
            'firma_t' => $this->cripto->firmar(Canonico::canje($idPedido, $monto, $ids, $nonce, $ahora)),
        ];

        return $this->recibo($this->enviar('/api/canje', $cuerpo));
    }

    /**
     * POST /api/canjes/consulta: ¿el banco canjeó este pedido? Sirve para conciliar cuando se perdió una respuesta.
     * @throws BancoRechazo CANJE_NO_ENCONTRADO si no hay canje
     * @throws BancoNoDisponible
     */
    public function consultar(string $idPedido, int $ahora): array
    {
        $nonce = $this->cripto->nonce();
        $cuerpo = [
            'id_pedido' => $idPedido, 'nonce' => $nonce, 'timestamp' => $ahora,
            'firma_t' => $this->cripto->firmar(Canonico::consultaCanje($idPedido, $nonce, $ahora)),
        ];

        return $this->recibo($this->enviar('/api/canjes/consulta', $cuerpo));
    }

    private function recibo(array $respuesta): array
    {
        if (!isset($respuesta['recibo']) || !is_array($respuesta['recibo'])) {
            throw new BancoNoDisponible('La respuesta del banco no trae recibo');
        }

        return $respuesta['recibo'];
    }

    private function enviar(string $ruta, array $cuerpo): array
    {
        [$sobre, $k] = $this->cripto->crearSobre(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->bancoRsaPubPem);
        [$estado, $datos] = $this->post(rtrim($this->baseUrl, '/') . $ruta, $sobre);

        if ($estado === 200) {
            try {
                $plano = $this->cripto->abrirRespuesta($datos, $k);
            } catch (MensajeCorruptoException) {
                throw new BancoNoDisponible('La respuesta del banco no pudo descifrarse');
            }
            $r = json_decode($plano, true, 16);
            if (!is_array($r)) {
                throw new BancoNoDisponible('La respuesta del banco no es JSON');
            }

            return $r;
        }

        // Los errores del banco viajan en claro (contrato, sección 12).
        $codigo = $datos['error']['codigo'] ?? null;
        if (is_string($codigo) && in_array($codigo, ApiError::codigos(), true)) {
            throw new BancoRechazo($codigo);
        }
        throw new BancoNoDisponible("El banco respondió con estado $estado");
    }

    /**
     * Una sola petición HTTP. Aparte para poder sustituirla en las pruebas.
     * @return array{0:int,1:array} [estado, cuerpo JSON]
     */
    protected function post(string $url, array $sobre): array
    {
        try {
            $r = Http::timeout(self::TIEMPO_MAXIMO_SEG)->acceptJson()->asJson()->post($url, $sobre);
        } catch (ConnectionException $e) {
            throw new BancoNoDisponible('No hay conexión con el banco', 0, $e);
        }

        return [$r->status(), $r->json() ?? []];
    }
}
