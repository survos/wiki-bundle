<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\WikiBundle\Dto\Image;

/**
 * A cached Wikidata entity: core fields in $rawData, claims normalized into WikiClaim
 * rows. The table is a custom cache, so $refreshedAt + isStale() drive re-fetching.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wiki_data')]
#[ORM\Index(columns: ['qid'], name: 'wiki_data_qid_idx')]
class WikiData
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    public private(set) string $qid;

    /** Entity core: label, description, aliases, sitelinks, wiki_url (not claims). */
    #[ORM\Column(type: Types::JSON)]
    public array $rawData = [];

    /** @var Collection<int,WikiClaim> */
    #[ORM\OneToMany(targetEntity: WikiClaim::class, mappedBy: 'wikiData', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $claims;

    /** P-codes whose claims have been fetched (so "fetched but empty" ≠ "never fetched"). */
    #[ORM\Column(type: Types::JSON)]
    public array $fetchedProps = [];

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $refreshedAt = null;

    public function __construct(string $qid)
    {
        $this->qid = $qid;
        $this->claims = new ArrayCollection();
    }

    public ?string $label {
        get => $this->rawData['label'] ?? null;
    }

    public ?string $description {
        get => $this->rawData['description'] ?? null;
    }

    public ?string $wikiUrl {
        get => $this->rawData['wiki_url'] ?? null;
    }

    /** @return WikiClaim[] */
    public function claimsFor(string $code): array
    {
        return $this->claims->filter(fn (WikiClaim $c) => $c->code === $code)->getValues();
    }

    /** @return Image[] every commonsMedia claim as a rich Image DTO (url + captions + date). */
    public function getImages(): array
    {
        return array_values(array_filter(array_map(
            fn (WikiClaim $c) => $c->toImage(),
            $this->claims->filter(fn (WikiClaim $c) => $c->isImage)->getValues(),
        )));
    }

    public function addClaim(WikiClaim $claim): void
    {
        $this->claims->add($claim);
    }

    public function removeClaimsByCode(string $code): void
    {
        foreach ($this->claimsFor($code) as $claim) {
            $this->claims->removeElement($claim);
        }
    }

    public function isStale(int $ttlSeconds, \DateTimeImmutable $now): bool
    {
        return $this->refreshedAt === null
            || ($now->getTimestamp() - $this->refreshedAt->getTimestamp()) > $ttlSeconds;
    }
}
