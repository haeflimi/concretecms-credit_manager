<?php
namespace CreditManager;

use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Concrete\Core\User\User;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use CreditManager\Entity\CreditRecord;
use CreditManager\Repository\CreditRecordList;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * This Class wraps the core Functionality of the Credit Manager
 *
 * Class CreditManager
 * @package CreditManager
 */
class CreditManager
{
    protected $qb; // Doctrine Query Builder
    protected $em; // Doctrine Entity Manager

    public function __construct($obj = null)
    {
        $this->db = Database::connection();
        $this->qb = $this->db->createQueryBuilder();
        $this->em = $this->db->getEntityManager();
    }

    public static function getUserBalance($user){
        if(is_object($user)) {
            $uId = $user->getUserID();
        } else {
            $uId = $user;
        }
        $db = Database::connection();
        $em = $db->getEntityManager();
        $userRecords = $em->getRepository('CreditManager\Entity\CreditRecord')
                ->findBy(array('uId' => $uId));
        $sum = 0;
        foreach($userRecords as $record){
            $sum += $record->getValue();
        }

        return round($sum,2);
    }

    public static function getUserHistory($user, $limit = 100){
        if(is_object($user)) {
            $uId = $user->getUserID();
        } else {
            $uId = $user;
        }
        $db = Database::connection();
        $em = $db->getEntityManager();
        $userRecords = $em->getRepository('CreditManager\Entity\CreditRecord')
            ->findBy(array('uId' => $uId), ['timestamp'=>'desc'], $limit);
        return $userRecords;
    }

    public static function getUserHistorybyCategory($user, $categoryTags = []){
        if(is_object($user)) {
            $uId = $user->getUserID();
        } else {
            $uId = $user;
        }

        if(!empty($categoryTags)){
            foreach($categoryTags as $c){
                $node = TopicTreeNode::getNodeByName($c);
                if(is_object($node)){
                    $nodeIds[] = $node->getTreeNodeID();
                }
            }
            $db = Database::connection();
            $em = $db->getEntityManager();
            $qb = $em->createQueryBuilder();
            $qb->select('crc')->from('CreditManager\Entity\CreditRecordCategory', 'crc')
                ->join('CreditManager\Entity\CreditRecord', 'cr','crc.crId = cr.Id')
                ->where('cr.uId = :uId')
                ->andWhere('crc.nodeId IN (:nodeids)')
                ->orderBy('cr.timestamp', 'DESC')
                ->setParameter('uId', $uId)
                ->setParameter('nodeids', $nodeIds);
        }
        $nodeIds = [];
        $query = $qb->getQuery();
        return $query->getResult();
    }

    public static function getUserHistoryByDate($user, $startDate, $endDate){
        if(is_object($user)) {
            $uId = $user->getUserID();
        } else {
            $uId = $user;
        }
        $db = Database::connection();
        $em = $db->getEntityManager();
        $qb = $em->createQueryBuilder();
        $qb->select('cr.uId, cr.comment, cr.timestamp, cr.value')
            ->from('CreditManager\Entity\CreditRecord', 'cr')
            ->where('cr.uId = ?1')
            ->andWhere('cr.timestamp >= :start')
            ->andWhere('cr.timestamp <= :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter(1, $uId)
            ->orderBy('cr.timestamp', 'DESC');
        $query = $qb->getQuery();
        return $query->getResult();
    }

    public static function getRecordCount($user){
        if(is_object($user)) {
            $uId = $user->getUserID();
        } else {
            $uId = $user;
        }
        $db = Database::connection();
        $em = $db->getEntityManager();
        $qb = $em->createQueryBuilder();

        $qb->select($qb->expr()->count('cr'))
            ->from('CreditManager\Entity\CreditRecord', 'cr')
            ->where('cr.uId = ?1')
            ->setParameter(1, $uId);

        $query = $qb->getQuery();

        return $query->getSingleScalarResult();
    }

    public static function addRecord($user, $value, $comment, $categories = []){
        $em = Database::connection()->getEntityManager();
        $cr = new CreditRecord($user, $value, $comment);
        $em->persist($cr);
        $em->flush();
        $cr->addCategories($categories);
        return $cr;
    }

    public static function getRevenueByCategory($startDate, $endDate, $categories = []){
        foreach($categories as $c){
            $node = TopicTreeNode::getNodeByName($c);
            if(is_object($node)){
                $nodeIds[] = $node->getTreeNodeID();
            }
        }

        $db = Database::connection();
        $em = $db->getEntityManager();
        $qb = $em->createQueryBuilder();

        $qb->select('SUM(cr.value)')
            ->from('CreditManager\Entity\CreditRecordCategory', 'crc')
            ->join('CreditManager\Entity\CreditRecord', 'cr')
            ->where('crc.crId = cr.Id')
            ->andWhere('cr.timestamp >= :start')
            ->andWhere('cr.timestamp <= :end')
            ->andWhere('crc.nodeId IN (:nodeids)')
            ->orderBy('cr.timestamp', 'DESC')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('nodeids', $nodeIds);
        $query = $qb->getQuery();
        $nodeIds = [];
        return $query->getSingleScalarResult();
    }

    public static function createCommunityStoreStandingOrder($user, $lanPageId)
    {
        $uID = null;
        if (is_numeric($user)) {
            $uID = (int) $user;
        } elseif (is_object($user) && method_exists($user, 'getUserID')) {
            $uID = (int) $user->getUserID();
        }

        $order = new Order();
        if ($uID) {
            $order->setCustomerID($uID);
        }
        $order->setDate(new \DateTime());
        $order->setShippingMethodName('Self-Service');
        $order->updateStatus('delivered');
        $order->setTotal(0);
        $order->save();

        $order->setAttribute('event_id', $lanPageId);
        $order->setAttribute('standing', true);

        return $order;
    }

    /**
     * Fetch all Community Store orders of the user with order attribute event_id equal to $lanPageId
     *
     * @param \Concrete\Core\User\UserInfo|\Concrete\Core\User\User|int $user
     * @param int|string $lanPageId
     * @return Order[]|null
     */
    public static function getCommunityStoreStandingOrder($user, $lanPageId)
    {
        $uID = null;
        if (is_numeric($user)) {
            $uID = (int) $user;
        } elseif (is_object($user) && method_exists($user, 'getUserID')) {
            $uID = (int) $user->getUserID();
        }

        if (!$uID) {
            return [];
        }

        $orderList = new OrderList();
        $orderList->setCustomerID($uID);
        $orders = $orderList->getResults();
        foreach ($orders as $order) {
            $eventId = $order->getAttribute('event_id');
            $standing = $order->getAttribute('standing');
            if($eventId == $lanPageId && $standing == true){
                return $order;
            }
        }
        return null;
    }
}