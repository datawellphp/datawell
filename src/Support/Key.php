<?php

declare(strict_types=1);

namespace Datawell\Support;

use Illuminate\Support\Str;

/**
 * Key conventions and the label derivation rule (D30, D32).
 */
final class Key
{
    /** Source keys: lowercase kebab-case, e.g. `document-signatures`. */
    public const string SOURCE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Item keys (fields, filters, sorts, actions, parameters): lowercase snake_case. */
    public const string ITEM_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public static function isValidSourceKey(string $key): bool
    {
        return preg_match(self::SOURCE_PATTERN, $key) === 1;
    }

    public static function isValidItemKey(string $key): bool
    {
        return preg_match(self::ITEM_PATTERN, $key) === 1;
    }

    /**
     * Derive a human label from a key: `signed_at` → "Signed At",
     * `document-signatures` → "Document Signatures".
     */
    public static function label(string $key): string
    {
        return Str::headline(str_replace('-', ' ', $key));
    }

    /**
     * Derive the source key the generator stamps: `DocumentSignaturesSource` → `document-signatures`.
     */
    public static function fromClassName(string $class): string
    {
        $basename = (string) Str::of($class)->classBasename()->replaceMatches('/(DataSource|Source)$/', '');

        return Str::kebab($basename);
    }
}
