<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

/**
 * One normalized Wikibase statement (a "claim"): the mainsnak plus its qualifiers and
 * rank. References are kept as raw arrays of snak-shaped data rather than parsed into
 * WikibaseSnak — callers that need full provenance chains can still reach it via
 * `references`, but the common cases (entity lookup, dump normalization) only need the
 * mainsnak + qualifiers, so those are the only pieces promoted to typed objects.
 */
final class WikibaseStatement
{
    /**
     * @param array<string,list<WikibaseSnak>> $qualifiers property (Pxx) => ordered snak values
     * @param list<array<string,mixed>> $references raw reference blocks, unparsed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $property,
        public readonly WikibaseSnak $mainSnak,
        public readonly string $rank,
        public readonly array $qualifiers = [],
        public readonly array $references = [],
    ) {}

    /** @param array<string,mixed> $claim */
    public static function fromArray(array $claim): self
    {
        $mainSnak = WikibaseSnak::fromArray((array) ($claim['mainsnak'] ?? []));

        $qualifiers = [];
        foreach ((array) ($claim['qualifiers'] ?? []) as $property => $snaks) {
            $qualifiers[(string) $property] = array_map(
                static fn (array $snak): WikibaseSnak => WikibaseSnak::fromArray($snak),
                (array) $snaks,
            );
        }

        return new self(
            id: (string) ($claim['id'] ?? ''),
            property: $mainSnak->property !== '' ? $mainSnak->property : (string) ($claim['id'] ?? ''),
            mainSnak: $mainSnak,
            rank: (string) ($claim['rank'] ?? 'normal'),
            qualifiers: $qualifiers,
            references: (array) ($claim['references'] ?? []),
        );
    }

    /** Convenience: the mainsnak's simplified value (null for somevalue/novalue snaks). */
    public function value(): mixed
    {
        return $this->mainSnak->value;
    }

    /** First qualifier value for a property, or null. */
    public function qualifier(string $property): mixed
    {
        return $this->qualifiers[$property][0]->value ?? null;
    }

    /**
     * ALL qualifier values for a property, in order -- for repeatable qualifiers, where a single
     * statement carries a real one-to-many relationship (e.g. an event's P29 "participant role"
     * statement with a distinct P17 "person" qualifier for each of ~100+ people who held that
     * role). qualifier() (singular) silently drops everything past the first in that case; this
     * is the version that doesn't.
     *
     * @return list<mixed>
     */
    public function qualifierValues(string $property): array
    {
        return array_map(
            static fn (WikibaseSnak $snak): mixed => $snak->value,
            $this->qualifiers[$property] ?? [],
        );
    }
}
