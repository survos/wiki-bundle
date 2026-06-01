<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\WikiBundle\Dto\Image;
use Survos\WikiBundle\Repository\WikiClaimRepository;

/**
 * One normalized Wikidata statement: a single (entity, property, value) row carrying
 * its full shape — the main value, its datatype, and qualifiers (captions, dates, …).
 * A multi-valued property (e.g. P18 with two images) becomes several rows ordered by
 * position, so "all P18 claims in the cache" is one indexed query (WikiClaimRepository).
 */
#[ORM\Entity(repositoryClass: WikiClaimRepository::class)]
#[ORM\Table(name: 'wiki_claim')]
#[ORM\Index(columns: ['code'], name: 'wiki_claim_code_idx')]
class WikiClaim
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'claims')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) WikiData $wikiData,

        #[ORM\Column(length: 12)]
        public private(set) string $code,

        /** Main value: a Commons filename, a QID, or a literal. */
        #[ORM\Column(type: Types::TEXT)]
        public private(set) string $value,

        /** mainsnak datatype: commonsMedia, wikibase-item, time, … */
        #[ORM\Column(length: 32, nullable: true)]
        public private(set) ?string $datatype = null,

        /** Simplified qualifiers, keyed by P-code: {"P2096":[{"language":"en","text":"…"}], "P585":[…]}. */
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $qualifiers = [],

        #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
        public private(set) int $position = 0,
    ) {}

    public bool $isImage {
        get => $this->datatype === 'commonsMedia';
    }

    /** Resolve a commonsMedia value to a fetchable https URL. */
    public ?string $url {
        get => $this->isImage
            ? 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($this->value)
            : null;
    }

    /** Build the rich image DTO (url + captions + date) for a commonsMedia claim. */
    public function toImage(): ?Image
    {
        return $this->isImage ? Image::fromClaim($this) : null;
    }
}
