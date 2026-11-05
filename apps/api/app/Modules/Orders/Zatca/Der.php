<?php

namespace App\Modules\Orders\Zatca;

use InvalidArgumentException;

/**
 * Just enough of a DER reader to take a certificate apart: the issuer's
 * name in its own order and the issuing CA's signature, which PHP's
 * openssl functions do not give out.
 */
final class Der
{
    public const SEQUENCE = 0x30;

    public const SET = 0x31;

    public const OID = 0x06;

    public const BIT_STRING = 0x03;

    /**
     * The elements one after the other in $der (the contents of a SEQUENCE or SET).
     *
     * @return list<array{tag: int, value: string}>
     */
    public static function children(string $der): array
    {
        $items = [];
        $offset = 0;

        while ($offset < strlen($der)) {
            [$items[], $offset] = self::read($der, $offset);
        }

        return $items;
    }

    /**
     * The one element $der holds, which must fill it.
     *
     * @return array{tag: int, value: string}
     */
    public static function one(string $der): array
    {
        [$item, $offset] = self::read($der, 0);

        if ($offset !== strlen($der)) {
            throw new InvalidArgumentException('Trailing bytes after the DER element.');
        }

        return $item;
    }

    /**
     * "2.5.4.3" from the contents of an OBJECT IDENTIFIER.
     */
    public static function oid(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Empty OID.');
        }

        $first = ord($value[0]);
        $parts = [intdiv($first, 40) > 2 ? 2 : intdiv($first, 40), $first >= 80 ? $first - 80 : $first % 40];
        $current = 0;

        for ($i = 1; $i < strlen($value); $i++) {
            $byte = ord($value[$i]);
            $current = ($current << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                $parts[] = $current;
                $current = 0;
            }
        }

        return implode('.', $parts);
    }

    /**
     * @return array{0: array{tag: int, value: string}, 1: int} the element and the offset after it
     */
    private static function read(string $der, int $offset): array
    {
        $size = strlen($der);

        if ($offset + 2 > $size) {
            throw new InvalidArgumentException('Truncated DER element.');
        }

        $tag = ord($der[$offset++]);
        $length = ord($der[$offset++]);

        if (($length & 0x80) !== 0) {
            $count = $length & 0x7F;

            if ($count === 0 || $count > 4 || $offset + $count > $size) {
                throw new InvalidArgumentException('Unsupported DER length.');
            }

            $length = 0;

            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }

        if ($offset + $length > $size) {
            throw new InvalidArgumentException('Truncated DER element.');
        }

        return [['tag' => $tag, 'value' => substr($der, $offset, $length)], $offset + $length];
    }
}
