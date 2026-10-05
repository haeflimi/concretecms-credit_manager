<?php
namespace CreditManager\Repository;

use Concrete\Core\Search\ItemList\Database\AttributedItemList as DatabaseItemList;
use Concrete\Core\Search\Pagination\Pagination;
use Concrete\Core\Support\Facade\Database;
use Package;
use Pagerfanta\Adapter\DoctrineDbalAdapter;

/**
 *
 * An object that allows a filtered list of pages to be returned.
 *
 */
class CreditRecordList extends DatabaseItemList
{

    /** @var  \Closure | integer | null */
    protected $permissionsChecker;
    /**
     * Columns in this array can be sorted via the request.
     * @var array
     */
    protected $autoSortColumns = array(
        'cr.timestamp'
    );

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
     * @param $queryRow
     * @return \Concrete\Core\File\File
     */
    public function getResult($queryRow)
    {
        $em = Database::connection()->getEntityManager();
        return $em->getRepository('CreditManager\Entity\CreditRecord')->getById( $queryRow['Id'] );
    }

    public function getTotalResults()
    {
        $em = Database::connection()->getEntityManager();
        return $em->getRepository('CreditManager\Entity\CreditRecord')->count();
    }

    /**
     * Filters keyword fields by keywords
     * @param $keywords
     */
    public function filterByKeywords($keywords)
    {
        $expressions = array(
            $this->query->expr()->like('cr.timestamp', ':keywords'),
            $this->query->expr()->like('cr.comment', ':keywords')
        );
        $expr = $this->query->expr();
        $this->query->andWhere(call_user_func_array(array($expr, 'orX'), $expressions));
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

    protected function getAttributeKeyClassName()
    {
        return '\\Concrete\\Core\\Attribute\\Key\\CollectionKey';
    }
}
