<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

use Survos\WikiBundle\Entity\WikiClaim;

/**
 * Rich view of a commonsMedia (e.g. P18) claim: the resolved URL plus the shape the
 * statement carries — per-language captions (P2096) and an optional date (P585).
 * Built from a WikiClaim so callers get an object, not a bare URL string.
 */
final class Image
{
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        /** @var array<string,string> language => caption */
        public readonly array $captions = [],
        public readonly ?string $date = null,
    ) {}

    public static function fromClaim(WikiClaim $claim): self
    {
        $captions = [];
        foreach ($claim->qualifiers['P2096'] ?? [] as $q) {
            if (isset($q['language'], $q['text'])) {
                $captions[$q['language']] = $q['text'];
            }
        }

        return new self(
            url: $claim->url ?? '',
            filename: $claim->value,
            captions: $captions,
            date: $claim->qualifiers['P585'][0]['time'] ?? null,
        );
    }

    public function caption(string $lang = 'en'): ?string
    {
        return $this->captions[$lang] ?? (array_values($this->captions)[0] ?? null);
    }
}
