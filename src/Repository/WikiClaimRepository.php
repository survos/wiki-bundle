<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\WikiBundle\Entity\WikiClaim;

/**
 * @extends ServiceEntityRepository<WikiClaim>
 */
final class WikiClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiClaim::class);
    }

    /**
     * Every cached claim for a property code, e.g. findByCode('P18') — the whole point
     * of normalizing: "all P18 claims in the cache" without loading every WikiData.
     *
     * @return WikiClaim[]
     */
    public function findByCode(string $code): array
    {
        return $this->findBy(['code' => $code], ['position' => 'ASC']);
    }
}
