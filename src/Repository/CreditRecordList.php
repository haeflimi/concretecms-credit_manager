<?php
namespace CreditManager\Repository;

use Concrete\Core\Search\ItemList\Database\ItemList as DatabaseItemList;
use Concrete\Core\Support\Facade\Database;
use CreditManager\Entity\CreditRecord;

/**
 * Filterable list of credit records.
 */
class CreditRecordList extends DatabaseItemList
{
    /**
     * Columns in this array can be sorted via the request.
     * @var array
     */
    protected $autoSortColumns = [
        'cr.timestamp',
    ];

    public function createQuery()
    {
        return $this->query->select('cr.timestamp, cr.Id, cr.uId, cr.comment');
    }

    public function finalizeQuery(\Doctrine\DBAL\Query\QueryBuilder $query)
    {
        $query->from('cmCreditRecord', 'cr');
        return $query;
    }

    /**
     * @param array $queryRow
     * @return CreditRecord|null
     */
    public function getResult($queryRow)
    {
        return Database::connection()->getEntityManager()->find(CreditRecord::class, (int) $queryRow['Id']);
    }

    public function getTotalResults()
    {
        $query = $this->deliverQueryObject();
        return (int) $query->resetQueryParts(['groupBy', 'orderBy'])->select('count(cr.Id)')->setMaxResults(1)->execute()->fetchColumn();
    }

    public function filterByUser($user)
    {
        $uId = is_object($user) ? (int) $user->getUserID() : (int) $user;
        $this->query->andWhere('cr.uId = :uId')->setParameter('uId', $uId);
    }

    /**
     * Filters keyword fields by keywords
     * @param $keywords
     */
    public function filterByKeywords($keywords)
    {
        $expr = $this->query->expr();
        $this->query->andWhere($expr->orX(
            $expr->like('cr.timestamp', ':keywords'),
            $expr->like('cr.comment', ':keywords')
        ));
        $this->query->setParameter('keywords', '%' . $keywords . '%');
    }

    public function filterByDate($start, $end)
    {
        $this->query->andWhere('cr.timestamp >= :start')
            ->andWhere('cr.timestamp <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('cr.timestamp', 'DESC');
    }
}
