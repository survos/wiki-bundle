<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

/**
 * One snak (property + value) as it appears in raw Wikibase entity JSON — a mainsnak or
 * a qualifier/reference snak. This is the same JSON shape whether it comes from a live
 * `wbgetentities?props=claims` response or a bulk Wikibase dump row (both are the
 * standard Wikibase entity serialization), so this parses either without an HTTP call.
 *
 * `value` is deliberately lightly processed, not fully "simplified": wikibase-item values
 * collapse to their bare QID (the common case callers want), but time/quantity/
 * globe-coordinate/monolingualtext keep their raw associative shape (e.g. time keeps
 * `['time' => ..., 'precision' => ..., ...]`) so no precision/calendar/unit information is
 * silently dropped — callers that need it can read the array, callers that just want "the
 * value" for a wikibase-item or string get a plain scalar.
 */
final class WikibaseSnak
{
    public function __construct(
        public readonly string $property,
        /** value|somevalue|novalue — see snaktype in the Wikibase data model. */
        public readonly string $snakType,
        public readonly ?string $dataType,
        public readonly mixed $value,
    ) {}

    /** @param array<string,mixed> $snak */
    public static function fromArray(array $snak): self
    {
        $property = (string) ($snak['property'] ?? '');
        $snakType = (string) ($snak['snaktype'] ?? 'novalue');
        $dataType = isset($snak['datatype']) ? (string) $snak['datatype'] : null;

        return new self(
            property: $property,
            snakType: $snakType,
            dataType: $dataType,
            value: $snakType === 'value' ? self::simplify($snak['datavalue'] ?? null) : null,
        );
    }

    private static function simplify(mixed $datavalue): mixed
    {
        if (!\is_array($datavalue) || !\array_key_exists('value', $datavalue)) {
            return null;
        }
        $value = $datavalue['value'];
        $type = $datavalue['type'] ?? null;

        return match ($type) {
            'wikibase-entityid' => is_array($value) ? ($value['id'] ?? null) : $value,
            default => $value, // string, time, quantity, monolingualtext, globecoordinate, ... kept raw
        };
    }
}
