<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

/**
 * A full Wikibase entity (item or property), parsed from its raw JSON — the standard
 * Wikibase entity serialization shared by `wbgetentities` responses and bulk Wikibase
 * dumps (Wikidata's own, or any other Wikibase install: Enslaved.org's lod.enslaved.org,
 * Commons, wikibase.cloud instances, ...). No HTTP, no instance-specific assumptions —
 * this is the piece WikidataService was missing: it only ever derived claims via SPARQL
 * against query.wikidata.org, so entity JSON that already carries its own claims (a dump
 * row, or a `props=claims` API response) had nowhere to go but manual array-poking.
 */
final class WikibaseEntity
{
    /**
     * @param array<string,string> $labels language => label
     * @param array<string,string> $descriptions language => description
     * @param array<string,list<string>> $aliases language => alias list
     * @param array<string,list<WikibaseStatement>> $statements property (Pxx) => ordered statements
     * @param array<string,mixed> $sitelinks raw sitelinks block, unparsed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $labels = [],
        public readonly array $descriptions = [],
        public readonly array $aliases = [],
        public readonly array $statements = [],
        public readonly array $sitelinks = [],
        public readonly ?int $lastRevId = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $labels = [];
        foreach ((array) ($raw['labels'] ?? []) as $lang => $entry) {
            $labels[(string) $lang] = (string) ($entry['value'] ?? '');
        }

        $descriptions = [];
        foreach ((array) ($raw['descriptions'] ?? []) as $lang => $entry) {
            $descriptions[(string) $lang] = (string) ($entry['value'] ?? '');
        }

        $aliases = [];
        foreach ((array) ($raw['aliases'] ?? []) as $lang => $entries) {
            $aliases[(string) $lang] = array_map(
                static fn (array $entry): string => (string) ($entry['value'] ?? ''),
                (array) $entries,
            );
        }

        $statements = [];
        foreach ((array) ($raw['claims'] ?? []) as $property => $claims) {
            $statements[(string) $property] = array_map(
                static fn (array $claim): WikibaseStatement => WikibaseStatement::fromArray($claim),
                (array) $claims,
            );
        }

        return new self(
            id: (string) ($raw['id'] ?? ''),
            type: (string) ($raw['type'] ?? 'item'),
            labels: $labels,
            descriptions: $descriptions,
            aliases: $aliases,
            statements: $statements,
            sitelinks: (array) ($raw['sitelinks'] ?? []),
            lastRevId: isset($raw['lastrevid']) ? (int) $raw['lastrevid'] : null,
        );
    }

    /** Label in $lang, falling back to English, then the first label present. */
    public function label(string $lang = 'en'): ?string
    {
        return $this->labels[$lang] ?? $this->labels['en'] ?? array_values($this->labels)[0] ?? null;
    }

    public function description(string $lang = 'en'): ?string
    {
        return $this->descriptions[$lang] ?? $this->descriptions['en'] ?? array_values($this->descriptions)[0] ?? null;
    }

    /** @return list<WikibaseStatement> */
    public function statementsFor(string $property): array
    {
        return $this->statements[$property] ?? [];
    }

    /** First statement's value for a property, or null (the common "does this entity have a P31 of X" case). */
    public function firstValue(string $property): mixed
    {
        // NOT $this->statements[$property][0]->value() ?? null -- the trailing method call
        // breaks ??'s isset-style suppression of the array access, so a property this entity
        // simply doesn't have (common; not every entity carries every claim) throws an
        // "Undefined array key" instead of returning null.
        return ($this->statementsFor($property)[0] ?? null)?->value();
    }

    /** @return list<mixed> every value for a (possibly multi-valued) property. */
    public function values(string $property): array
    {
        return array_map(static fn (WikibaseStatement $s): mixed => $s->value(), $this->statementsFor($property));
    }

    /** True if any statement on $property has $value among its (simplified) values — e.g. instance-of checks. */
    public function hasValue(string $property, mixed $value): bool
    {
        return in_array($value, $this->values($property), true);
    }
}
