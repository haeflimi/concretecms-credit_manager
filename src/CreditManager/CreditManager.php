<?php
namespace CreditManager;

use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Database;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use CreditManager\Entity\CreditRecord;
use CreditManager\Entity\CreditRecordCategory;
use CreditManager\Service\CategoryResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The ledger API: balances, histories and the one place that writes credit records.
 */
class CreditManager
{
    const SOURCE_MANUAL = 'manual';
    const SOURCE_BULK = 'bulk';
    const SOURCE_POS = 'pos';
    const SOURCE_STORE_ORDER = 'cs_order';
    const SOURCE_PAYREXX = 'payrexx';
    const SOURCE_PAYPAL = 'paypal';

    /**
     * @param \Concrete\Core\User\User|\Concrete\Core\User\UserInfo|int $user
     * @return int 0 when no user can be determined
     */
    public static function userId($user)
    {
        if (is_object($user) && method_exists($user, 'getUserID')) {
            return (int) $user->getUserID();
        }
        return is_numeric($user) ? (int) $user : 0;
    }

    /**
     * Exact sum of all records of the user, computed by the database.
     *
     * @return float
     */
    public static function getUserBalance($user)
    {
        $uId = self::userId($user);
        if (!$uId) {
            return 0.0;
        }
        $sum = Database::connection()->fetchColumn('SELECT COALESCE(SUM(value), 0) FROM cmCreditRecord WHERE uId = ?', [$uId]);
        return round((float) $sum, 2);
    }

    /**
     * @return CreditRecord[] newest first
     */
    public static function getUserHistory($user, $limit = 100, $offset = 0)
    {
        $uId = self::userId($user);
        if (!$uId) {
            return [];
        }
        return self::em()->getRepository(CreditRecord::class)
            ->findBy(['uId' => $uId], ['timestamp' => 'desc', 'Id' => 'desc'], $limit ? (int) $limit : null, (int) $offset);
    }

    /**
     * Records of the user tagged with any of the given categories (names or node ids), newest first.
     *
     * @return CreditRecord[]
     */
    public static function getUserHistoryByCategory($user, $categoryTags = [])
    {
        $uId = self::userId($user);
        $nodeIds = CategoryResolver::resolveMany($categoryTags);
        if (!$uId || empty($nodeIds)) {
            return [];
        }
        $ids = Database::connection()->executeQuery(
            'SELECT DISTINCT cr.Id, cr.timestamp FROM cmCreditRecord cr INNER JOIN cmCreditRecordCategory crc ON crc.crId = cr.Id'
            . ' WHERE cr.uId = ? AND crc.nodeId IN (?) ORDER BY cr.timestamp DESC, cr.Id DESC',
            [$uId, $nodeIds],
            [\PDO::PARAM_INT, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        )->fetchAll(\PDO::FETCH_COLUMN);
        return self::loadRecords($ids);
    }

    /**
     * @param \DateTime|string $startDate
     * @param \DateTime|string $endDate
     * @return CreditRecord[] newest first
     */
    public static function getUserHistoryByDate($user, $startDate, $endDate)
    {
        $uId = self::userId($user);
        if (!$uId) {
            return [];
        }
        $qb = self::em()->createQueryBuilder();
        $qb->select('cr')
            ->from(CreditRecord::class, 'cr')
            ->where('cr.uId = :uId')
            ->andWhere('cr.timestamp >= :start')
            ->andWhere('cr.timestamp <= :end')
            ->setParameter('uId', $uId)
            ->setParameter('start', self::toDateTime($startDate))
            ->setParameter('end', self::toDateTime($endDate))
            ->orderBy('cr.timestamp', 'DESC')
            ->addOrderBy('cr.Id', 'DESC');
        return $qb->getQuery()->getResult();
    }

    /**
     * @return int
     */
    public static function getRecordCount($user)
    {
        $uId = self::userId($user);
        if (!$uId) {
            return 0;
        }
        return (int) Database::connection()->fetchColumn('SELECT COUNT(*) FROM cmCreditRecord WHERE uId = ?', [$uId]);
    }

    /**
     * @return CreditRecord|null the record already booked for this business event
     */
    public static function findByReference($source, $externalRef)
    {
        if ($source === null || $externalRef === null || $externalRef === '') {
            return null;
        }
        return self::em()->getRepository(CreditRecord::class)->findOneBy(['source' => (string) $source, 'externalRef' => (string) $externalRef]);
    }

    /**
     * Books a record together with its categories in one transaction.
     *
     * When $source and $externalRef are given the booking is idempotent: a record that already exists for that
     * reference is returned instead of a new one, and a concurrent double booking is refused by the database.
     *
     * @param \Concrete\Core\User\User|\Concrete\Core\User\UserInfo|int $user
     * @param string|float|int $value positive = credit, negative = charge
     * @param string $comment
     * @param array $categories topic names or node ids
     * @param string|null $source one of the SOURCE_* constants
     * @param string|null $externalRef id of the business event within $source
     * @return CreditRecord
     * @throws \InvalidArgumentException for a missing user or a non-numeric value
     * @throws UniqueConstraintViolationException when the same reference was booked concurrently
     */
    public static function addRecord($user, $value, $comment, $categories = [], $source = null, $externalRef = null)
    {
        $uId = self::userId($user);
        if (!$uId) {
            throw new \InvalidArgumentException('Credit Manager: cannot book a record without a user.');
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Credit Manager: the record value must be numeric.');
        }
        if ($source !== null && $externalRef !== null) {
            $existing = self::findByReference($source, $externalRef);
            if ($existing) {
                return $existing;
            }
        }
        $nodeIds = CategoryResolver::resolveMany($categories);
        $em = self::em();
        try {
            return $em->transactional(function () use ($em, $uId, $value, $comment, $source, $externalRef, $nodeIds) {
                $cr = new CreditRecord($uId, $value, $comment, $source, $externalRef);
                $em->persist($cr);
                $em->flush();
                foreach ($nodeIds as $nodeId) {
                    $em->persist(new CreditRecordCategory($cr, $nodeId));
                }
                if ($nodeIds) {
                    $em->flush();
                }
                return $cr;
            });
        } catch (UniqueConstraintViolationException $e) {
            Application::getFacadeApplication()->make('log')->warning(sprintf(
                'Credit Manager: refused duplicate booking %s:%s for user %d.', $source, $externalRef, $uId
            ));
            throw $e;
        }
    }

    /**
     * Sum of all records tagged with any of the categories in the period.
     *
     * @return float
     */
    public static function getRevenueByCategory($startDate, $endDate, $categories = [])
    {
        $nodeIds = CategoryResolver::resolveMany($categories);
        if (empty($nodeIds)) {
            return 0.0;
        }
        $sum = Database::connection()->executeQuery(
            'SELECT COALESCE(SUM(cr.value), 0) FROM cmCreditRecord cr WHERE cr.timestamp >= ? AND cr.timestamp <= ?'
            . ' AND EXISTS (SELECT 1 FROM cmCreditRecordCategory crc WHERE crc.crId = cr.Id AND crc.nodeId IN (?))',
            [self::toDateTime($startDate)->format('Y-m-d H:i:s'), self::toDateTime($endDate)->format('Y-m-d H:i:s'), $nodeIds],
            [\PDO::PARAM_STR, \PDO::PARAM_STR, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        )->fetchColumn();
        return round((float) $sum, 2);
    }

    /**
     * Creates the Community Store order that collects a user's POS purchases of one event.
     *
     * @return Order
     */
    public static function createCommunityStoreStandingOrder($user, $eventPageId)
    {
        $uID = self::userId($user);
        $order = new Order();
        if ($uID) {
            $order->setCustomerID($uID);
        }
        $order->setDate(new \DateTime());
        $order->setShippingMethodName('Self-Service');
        $order->updateStatus('delivered');
        $order->setTotal(0);
        $order->save();

        $order->setAttribute('event_id', $eventPageId);
        $order->setAttribute('standing', true);

        return $order;
    }

    /**
     * The user's standing Community Store order of the event, if there is one.
     *
     * @return Order|null
     */
    public static function getCommunityStoreStandingOrder($user, $eventPageId)
    {
        $uID = self::userId($user);
        if (!$uID) {
            return null;
        }
        $orderList = new OrderList();
        $orderList->setCustomerID($uID);
        foreach ($orderList->getResults() as $order) {
            if (!is_object($order) || !$order->getAttribute('standing')) {
                continue;
            }
            if ((string) self::attributeValue($order->getAttribute('event_id')) === (string) $eventPageId) {
                return $order;
            }
        }
        return null;
    }

    /**
     * Normalises a page/number attribute value that may come back as an object.
     */
    public static function attributeValue($value)
    {
        if (is_object($value) && method_exists($value, 'getCollectionID')) {
            return $value->getCollectionID();
        }
        if (is_object($value) && method_exists($value, 'getValue')) {
            return $value->getValue();
        }
        return $value;
    }

    /**
     * @return \Doctrine\ORM\EntityManagerInterface
     */
    public static function em()
    {
        return Database::connection()->getEntityManager();
    }

    /**
     * @param int[] $ids
     * @return CreditRecord[] in the order of $ids
     */
    private static function loadRecords(array $ids)
    {
        if (empty($ids)) {
            return [];
        }
        $byId = [];
        foreach (self::em()->getRepository(CreditRecord::class)->findBy(['Id' => $ids]) as $record) {
            $byId[$record->getId()] = $record;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    private static function toDateTime($value)
    {
        return $value instanceof \DateTimeInterface ? $value : new \DateTime((string) $value);
    }
}
