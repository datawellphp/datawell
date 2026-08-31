<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Datawell\Validation\ValidationException;

/**
 * An opaque keyset cursor: the sort values plus the primary key of the last row seen (D39).
 */
final class Cursor
{
    /**
     * @param  list<mixed>  $values  one value per applied sort, then the primary key
     */
    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($values, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return list<mixed>
     *
     * @throws ValidationException
     */
    public static function decode(string $cursor, int $expected): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($values) || ! array_is_list($values) || count($values) !== $expected) {
            throw ValidationException::withErrors(['page.after' => ['The cursor is invalid for this request.']]);
        }

        return $values;
    }
}
