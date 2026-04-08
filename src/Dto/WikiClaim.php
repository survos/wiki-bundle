<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

use Survos\WikiBundle\Entity\WikiProperty;

readonly class WikiClaim
{
    public function __construct(
        public string $code,
        public string $label,
        public array $values
    ) {}

    public static function fromArray(string $code, ?array $rawValues): self
    {
        $property = WikiProperty::fromCode($code);
        return new self(
            code: $code,
            label: $property?->label() ?? $code,
            values: $rawValues ?? []
        );
    }

    public function first(): ?string
    {
        return $this->values[0] ?? null;
    }

    public function has(string $value): bool
    {
        return in_array($value, $this->values, true);
    }

    public function count(): int
    {
        return count($this->values);
    }
}
