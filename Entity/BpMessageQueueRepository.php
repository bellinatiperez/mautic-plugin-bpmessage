<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<BpMessageQueue>
 */
class BpMessageQueueRepository extends CommonRepository
{
    /**
     * @param int[] $idList
     */
    public function deleteQueuesById(array $idList): void
    {
        if (!count($idList)) {
            return;
        }

        $qb = $this->_em->getConnection()->createQueryBuilder();
        $qb->delete(MAUTIC_TABLE_PREFIX.BpMessageQueue::TABLE_NAME)
            ->where(
                $qb->expr()->in('id', $idList)
            )
            ->executeStatement();
    }

    public function existsByHash(string $hash): bool
    {
        $qb = $this->_em->getConnection()->createQueryBuilder();
        $result = $qb->select($this->getTableAlias().'.id')
            ->from(MAUTIC_TABLE_PREFIX.BpMessageQueue::TABLE_NAME, $this->getTableAlias())
            ->where($this->getTableAlias().'.config_hash = :hash')
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (bool) $result;
    }
}