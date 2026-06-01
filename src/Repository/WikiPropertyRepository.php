<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\WikiBundle\Entity\WikiProperty;

/**
 * @extends ServiceEntityRepository<WikiProperty>
 */
final class WikiPropertyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikiProperty::class);
    }

    public function isEmpty(): bool
    {
        return 0 === (int) $this->createQueryBuilder('p')->select('COUNT(p.code)')->getQuery()->getSingleScalarResult();
    }
}
