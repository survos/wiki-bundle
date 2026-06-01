<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\WikiBundle\Repository\WikiPropertyRepository;

/**
 * A cached Wikidata property definition (P-code → label/datatype). Replaces the old
 * hardcoded enum: the set the app cares about is declared in config (alias => code)
 * and seeded into this table on first use; labels/datatypes are fetched from Wikidata.
 */
#[ORM\Entity(repositoryClass: WikiPropertyRepository::class)]
#[ORM\Table(name: 'wiki_property')]
class WikiProperty
{
    /** Natural key, e.g. "P18". */
    #[ORM\Id, ORM\Column(length: 12)]
    public private(set) string $code;

    /** App-facing name from config, e.g. "image". */
    #[ORM\Column(nullable: true)]
    public ?string $alias = null;

    /** Wikidata label, e.g. "image". */
    #[ORM\Column(nullable: true)]
    public ?string $label = null;

    /** Wikidata datatype: commonsMedia, wikibase-item, time, monolingualtext, url, string, … */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $datatype = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $refreshedAt = null;

    public function __construct(string $code, ?string $alias = null)
    {
        $this->code = $code;
        $this->alias = $alias;
    }

    public bool $isImage {
        get => $this->datatype === 'commonsMedia';
    }
}
