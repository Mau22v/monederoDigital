<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Throwable;

/**
 * Criptografía del proyecto (misma clase en Banco y Tienda).
 * Implementa lo definido en docs/contrato-integracion.md, secciones 5, 6 y 7:
 *
 *   - Hash ............ SHA-256
 *   - Firma digital ... ECDSA P-256 con SHA-256, firma en formato DER, en Base64
 *   - Sobre híbrido ... llave AES-256 (K) cifrada con RSA-2048 OAEP (SHA-256/MGF1-SHA-256)
 *                       y mensaje cifrado con AES-256-GCM (IV 12 bytes, etiqueta 16 bytes)
 *
 * Las llaves privadas se reciben por el constructor (PEM). Así la clase no depende
 * de archivos y se puede probar con llaves temporales.
 */
class CryptoService
{
    private const AES_BYTES = 32;   // AES-256
    private const IV_BYTES  = 12;   // tamaño de IV recomendado para GCM
    private const TAG_BYTES = 16;   // etiqueta de autenticación de GCM

    public function __construct(
        private readonly ?string $ecPrivPem = null,   // llave privada propia para FIRMAR
        private readonly ?string $rsaPrivPem = null,  // llave privada propia para DESCIFRAR sobres
    ) {
    }

    // ------------------------------------------------------------------
    // Hash y utilidades
    // ------------------------------------------------------------------

    /** SHA-256 en hexadecimal minúscula. */
    public function sha256Hex(string $dato): string
    {
        return hash('sha256', $dato);
    }

    /** Nonce de un solo uso: 16 bytes aleatorios en hexadecimal (32 caracteres). */
    public function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Convierte una llave pública en Base64 (X.509 SubjectPublicKeyInfo en DER, que es lo que
     * entrega `publicKey.encoded` en Android) a formato PEM, que es lo que lee OpenSSL.
     */
    public function pemDesdeBase64(string $base64): string
    {
        $der = base64_decode($base64, true);   // true = estricto: rechaza caracteres inválidos
        if ($der === false || $der === '') {
            throw new InvalidArgumentException('Llave pública en Base64 inválida');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** ¿Es una llave pública EC de la curva P-256 (prime256v1)? Se usa al registrar a un cliente. */
    public function esLlaveEcP256(string $pubPem): bool
    {
        $llave = @openssl_pkey_get_public($pubPem);
        if ($llave === false) {
            return false;
        }
        $d = openssl_pkey_get_details($llave);

        return $d !== false
            && ($d['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && ($d['ec']['curve_name'] ?? null) === 'prime256v1';
    }

    // ------------------------------------------------------------------
    // Firma digital (ECDSA P-256 + SHA-256)
    // ------------------------------------------------------------------

    /** Firma una cadena canónica con la llave EC privada propia. Devuelve la firma DER en Base64. */
    public function firmar(string $mensaje): string
    {
        if ($this->ecPrivPem === null) {
            throw new RuntimeException('Este servidor no tiene llave EC privada configurada');
        }
        $llave = openssl_pkey_get_private($this->ecPrivPem);
        if ($llave === false) {
            throw new RuntimeException('Llave EC privada ilegible');
        }
        if (!openssl_sign($mensaje, $firma, $llave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar');
        }

        return base64_encode($firma);
    }

    /**
     * Verifica una firma con la llave pública (PEM) del firmante.
     * Devuelve false ante CUALQUIER problema: firma mal formada, llave inválida, llave que no
     * es EC P-256 o firma que no corresponde al mensaje.
     */
    public function verificar(string $mensaje, string $firmaBase64, string $pubPem): bool
    {
        $firma = base64_decode($firmaBase64, true);
        if ($firma === false || $firma === '') {
            return false;
        }
        if (!$this->esLlaveEcP256($pubPem)) {
            return false;
        }
        $llave = openssl_pkey_get_public($pubPem);
        if ($llave === false) {
            return false;
        }

        // openssl_verify: 1 = válida, 0 = inválida, -1 = error. Solo 1 es aceptable.
        return openssl_verify($mensaje, $firma, $llave, OPENSSL_ALGO_SHA256) === 1;
    }

    // ------------------------------------------------------------------
    // Sobre híbrido: quien envía (la app, o la tienda hacia el banco)
    // ------------------------------------------------------------------

    /**
     * Crea un sobre dirigido al dueño de $rsaPubPem.
     * @return array{0: array{k:string,iv:string,ct:string}, 1: string}  [sobre, K]
     *         K se conserva para poder abrir la respuesta, que viene cifrada con la misma K.
     */
    public function crearSobre(string $textoPlano, string $rsaPubPem): array
    {
        $k  = random_bytes(self::AES_BYTES);
        $iv = random_bytes(self::IV_BYTES);

        $sobre = [
            'k'  => base64_encode($this->rsaCifrar($k, $rsaPubPem)),
            'iv' => base64_encode($iv),
            'ct' => base64_encode($this->aesGcmCifrar($textoPlano, $k, $iv)),
        ];

        return [$sobre, $k];
    }

    /** Abre la respuesta del servidor (sobre de respuesta: solo iv y ct) con la K de la petición. */
    public function abrirRespuesta(array $respuesta, string $k): string
    {
        [$iv, $ct] = $this->camposBase64($respuesta, ['iv', 'ct']);

        return $this->aesGcmDescifrar($ct, $k, $iv);
    }

    // ------------------------------------------------------------------
    // Sobre híbrido: quien recibe (el servidor)
    // ------------------------------------------------------------------

    /**
     * Abre un sobre recibido.
     * @return array{0: string, 1: string}  [texto plano, K]
     * @throws MensajeCorruptoException si cualquier paso falla
     */
    public function abrirSobre(array $sobre): array
    {
        [$kCifrada, $iv, $ct] = $this->camposBase64($sobre, ['k', 'iv', 'ct']);

        $k = $this->rsaDescifrar($kCifrada);
        if (strlen($k) !== self::AES_BYTES) {
            throw new MensajeCorruptoException();
        }

        return [$this->aesGcmDescifrar($ct, $k, $iv), $k];
    }

    /**
     * Cifra la respuesta con la MISMA K de la petición y un IV nuevo.
     * @return array{iv:string,ct:string}
     */
    public function cerrarRespuesta(string $textoPlano, string $k): array
    {
        $iv = random_bytes(self::IV_BYTES);

        return [
            'iv' => base64_encode($iv),
            'ct' => base64_encode($this->aesGcmCifrar($textoPlano, $k, $iv)),
        ];
    }

    // ------------------------------------------------------------------
    // Piezas internas
    // ------------------------------------------------------------------

    /** AES-256-GCM. Devuelve cifrado || etiqueta(16 bytes), igual que Java/Kotlin. */
    private function aesGcmCifrar(string $textoPlano, string $k, string $iv): string
    {
        $etiqueta = '';
        $cifrado = openssl_encrypt($textoPlano, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $etiqueta, '', self::TAG_BYTES);
        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar');
        }

        return $cifrado . $etiqueta;
    }

    /** Separa la etiqueta (últimos 16 bytes) y descifra. Si la etiqueta no cuadra, el mensaje fue alterado. */
    private function aesGcmDescifrar(string $ct, string $k, string $iv): string
    {
        if (strlen($ct) < self::TAG_BYTES || strlen($iv) !== self::IV_BYTES || strlen($k) !== self::AES_BYTES) {
            throw new MensajeCorruptoException();
        }
        $etiqueta = substr($ct, -self::TAG_BYTES);
        $cifrado  = substr($ct, 0, -self::TAG_BYTES);

        $plano = openssl_decrypt($cifrado, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $etiqueta);
        if ($plano === false) {
            throw new MensajeCorruptoException();
        }

        return $plano;
    }

    /** RSA-2048 OAEP con SHA-256 y MGF1-SHA-256 (los parámetros que pide el contrato). */
    private function rsaCifrar(string $datos, string $rsaPubPem): string
    {
        try {
            $llave = PublicKeyLoader::load($rsaPubPem);
            return $llave
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->encrypt($datos);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo cifrar con la llave RSA: ' . $e->getMessage());
        }
    }

    private function rsaDescifrar(string $datos): string
    {
        if ($this->rsaPrivPem === null) {
            throw new RuntimeException('Este servidor no tiene llave RSA privada configurada');
        }
        try {
            $llave = PublicKeyLoader::load($this->rsaPrivPem);
            $plano = $llave
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->decrypt($datos);
        } catch (Throwable) {
            throw new MensajeCorruptoException();
        }
        if (!is_string($plano) || $plano === '') {
            throw new MensajeCorruptoException();
        }

        return $plano;
    }

    /**
     * Lee campos Base64 obligatorios de un arreglo. Cualquier campo ausente, que no sea texto
     * o que no sea Base64 válido es un mensaje corrupto.
     * @param string[] $nombres
     * @return string[]
     */
    private function camposBase64(array $datos, array $nombres): array
    {
        $salida = [];
        foreach ($nombres as $nombre) {
            if (!isset($datos[$nombre]) || !is_string($datos[$nombre])) {
                throw new MensajeCorruptoException();
            }
            $bin = base64_decode($datos[$nombre], true);
            if ($bin === false) {
                throw new MensajeCorruptoException();
            }
            $salida[] = $bin;
        }

        return $salida;
    }
}
