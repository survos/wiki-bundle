<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\WikiBundle\Dto\WikiClaim;

#[ORM\Entity]
#[ORM\Table(name: 'wiki_data')]
#[ORM\Index(columns: ['qid'], name: 'wiki_data_qid_idx')]
class WikiData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, unique: true)]
    private string $qid;

    #[ORM\Column(type: Types::JSON)]
    private array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $qid)
    {
        $this->qid = $qid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQid(): string
    {
        return $this->qid;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }

    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLabel(?string $lang = 'en'): ?string
    {
        return $this->rawData['label'] ?? null;
    }

    public function getDescription(?string $lang = 'en'): ?string
    {
        return $this->rawData['description'] ?? null;
    }

    public function getWikiUrl(?string $lang = 'en'): ?string
    {
        return $this->rawData['wiki_url'] ?? null;
    }

    public function getAliases(): array
    {
        return $this->rawData['aliases'] ?? [];
    }

    public function getClaims(): array
    {
        return $this->rawData['claims'] ?? [];
    }

    public function getClaim(string $code): ?WikiClaim
    {
        if (!str_starts_with($code, 'P')) {
            $code = WikiProperty::tryFrom($code)?->value;
        }
        if (!$code) {
            return null;
        }
        $claims = $this->getClaims();
        $values = $claims[$code] ?? null;
        return $values ? WikiClaim::fromArray($code, $values) : null;
    }

    public function image(): ?string
    {
        return $this->getClaim('P18')?->first();
    }

    public function getProperty(WikiProperty $property): ?WikiClaim
    {
        return $this->getClaim($property->value) ?? null;
    }

    public function description(): ?string
    {
        return $this->rawData['description'] ?? null;
    }

    public function extendedDescription(): ?string
    {
        return $this->rawData['claims']['P2094'][0] ?? null;
    }

    public function bestDescription(): ?string
    {
        return $this->description() ?? $this->extendedDescription();
    }
}
