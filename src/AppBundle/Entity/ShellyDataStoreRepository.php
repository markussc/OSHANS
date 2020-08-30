<?php

namespace AppBundle\Entity;

/**
 * ShellyDataStoreRepository
 */
class ShellyDataStoreRepository extends DataStoreBaseRepository
{
    public function getLatest($connectorId, $status = -1, $roller = false)
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.connectorId = :connectorId')
            ->orderBy('e.timestamp', 'desc')
            ->setParameter('connectorId', $connectorId)
            ->setMaxResults(1);
        if ($status != -1) {
            if ($roller) {
                $like = 'NOT LIKE';
            } else {
                $like = 'LIKE';
            }
            $qb->andWhere('e.jsonValue '.$like.' :status')
               ->setParameter('status', '%"val":'.$status.'%');
            return $qb->getQuery()->getResult();
        }
        $latest = $qb->getQuery()->getResult();
        if (!count($latest)) {
            return 0;
        } else {
            return $latest[0];
        }
    }

    public function getActiveDuration($connectorId, $start = null, $end = null)
    {
        if ($start === null) {
            $start = new \DateTime('today');
        }
        if ($end === null) {
            $end = new \DateTime('now');
        }

        $qb = $this->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.connectorId = :connectorId')
            ->andWhere('e.timestamp >= :start')
            ->andWhere('e.timestamp <= :end')
            ->andWhere('e.jsonValue LIKE :status')
            ->setParameters([
                'connectorId' => $connectorId,
                'start' => $start,
                'end' => $end,
                'status' => '%"val":1%',
            ]);

        return $qb->getQuery()->getSingleScalarResult();
    }
}
