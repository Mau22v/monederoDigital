<?php
declare(strict_types=1);
namespace App\Services;
use InvalidArgumentException; 

/*
 * Cadenas canónicas que se firman (docs/contrato-integracion.md, sección 7).
 * La firma NUNCA se calcula sobre el JSON, sino sobre estas cadenas: campos separados por "|",
 * primero la etiqueta del tipo y la versión "v1", enteros sin formato, listas ordenadas.
 * Los tipos exigen enteros (int) a propósito: si un valor llega como texto o decimal desde el
 * JSON, el servidor debe validarlo y convertirlo ANTES de llegar aquí.
 * Canonico sirve para construir la cadena de texto canónica sobre la que se calculan y verifican las firmas digitales de las operaciones del sistema.
 */

final class Canonico
{
    // --- Banco ---------------------------------------------------------
    //Forma canonica de un ticket
    public static function ticket(string $id, int $denominacion, string $idCuenta, int $emision, int $expiracion): string
    {
        return self::unir('ticket', $id, $denominacion, $idCuenta, $emision, $expiracion);
    }
    //Vinculación de un ticket a una cuenta y clave pública del usuario
    public static function cert(string $idCuenta, string $pubUBase64, int $expiracion): string
    {
        return self::unir('cert', $idCuenta, $pubUBase64, $expiracion);
    }

    /** @param array<int,int> $Solicitud de tikets por denominaciones  ej. [500 => 2, 300 => 1] */
    public static function solicitud(string $idCuenta, array $denominaciones, string $nonce, int $timestamp): string
    {
        $partes = [];
        krsort($denominaciones, SORT_NUMERIC);          // de mayor a menor
        foreach ($denominaciones as $valor => $cantidad) {
            if ($cantidad > 0) {
                $partes[] = $valor . ':' . $cantidad;
            }
        }

        return self::unir('solicitud', $idCuenta, implode(',', $partes), $nonce, $timestamp);
    }

    /** @param string[] $ids */
    public static function reciboAdq(string $idCuenta, int $total, array $ids, int $timestamp): string
    {
        return self::unir('recibo_adq', $idCuenta, $total, self::lista($ids), $timestamp);
    }

    /** @param string[] $ids */
    public static function reciboCanje(string $idPedido, int $monto, array $ids, int $timestamp): string
    {
        return self::unir('recibo_canje', $idPedido, $monto, self::lista($ids), $timestamp);
    }

    // --- Tienda --------------------------------------------------------

    /** @param array<int,array{id:int,nombre:string,precio:int}> $productos */
    public static function catalogo(int $timestamp, array $productos): string
    {
        usort($productos, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        $partes = [];
        foreach ($productos as $p) {
            if (preg_match('/[|;:,]/', $p['nombre']) === 1) {
                throw new InvalidArgumentException('El nombre del producto contiene un carácter no permitido');
            }
            $partes[] = $p['id'] . ':' . $p['nombre'] . ':' . $p['precio'];
        }

        return self::unir('catalogo', $timestamp, implode(';', $partes));
    }

    /** @param array<int,array{id:int,cantidad:int}> $items */
    public static function crearPedido(string $idCuenta, array $items, string $nonce, int $timestamp): string
    {
        usort($items, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        $partes = array_map(fn (array $i) => $i['id'] . ':' . $i['cantidad'], $items);

        return self::unir('crear_pedido', $idCuenta, implode(',', $partes), $nonce, $timestamp);
    }

    public static function pedido(string $idPedido, string $idCuenta, int $monto, string $nonceT, int $expiracion): string
    {
        return self::unir('pedido', $idPedido, $idCuenta, $monto, $nonceT, $expiracion);
    }

    /** @param string[] $ids */
    public static function orden(string $idPedido, int $monto, array $ids, string $nonce, int $timestamp): string
    {
        return self::unir('orden', $idPedido, $monto, self::lista($ids), $nonce, $timestamp);
    }

    /** @param string[] $ids */
    public static function canje(string $idPedido, int $monto, array $ids, string $nonce, int $timestamp): string
    {
        return self::unir('canje', $idPedido, $monto, self::lista($ids), $nonce, $timestamp);
    }

    public static function comprobante(string $idPedido, string $idCuenta, int $monto, string $estado, int $timestamp): string
    {
        return self::unir('comprobante', $idPedido, $idCuenta, $monto, $estado, $timestamp);
    }

    public static function consultaPedido(string $idPedido, string $idCuenta, string $nonce, int $timestamp): string
    {
        return self::unir('consulta_pedido', $idPedido, $idCuenta, $nonce, $timestamp);
    }

    public static function consultaCanje(string $idPedido, string $nonce, int $timestamp): string
    {
        return self::unir('consulta_canje', $idPedido, $nonce, $timestamp);
    }

    // --- Internos ------------------------------------------------------

    /** Etiqueta + "v1" + campos, separados por "|". Ningún campo puede contener "|". */
    private static function unir(string $etiqueta, string|int ...$campos): string
    {
        $partes = [$etiqueta, 'v1'];
        foreach ($campos as $campo) {
            $texto = (string) $campo;
            if (str_contains($texto, '|')) {
                throw new InvalidArgumentException('Un campo contiene el separador "|"');
            }
            $partes[] = $texto;
        }

        return implode('|', $partes);
    }

    /** Lista de ids ordenada de forma ascendente (comparación de texto) y separada por coma. */
    private static function lista(array $ids): string
    {
        foreach ($ids as $id) {
            if (!is_string($id) || str_contains($id, ',') || str_contains($id, '|')) {
                throw new InvalidArgumentException('Identificador inválido en la lista');
            }
        }
        sort($ids, SORT_STRING);

        return implode(',', $ids);
    }
}
