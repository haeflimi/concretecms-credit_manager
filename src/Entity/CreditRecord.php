<?php
namespace CreditManager\Entity;

use Concrete\Core\Support\Facade\Database;
use CreditManager\Service\CategoryResolver;
use Doctrine\ORM\Mapping as ORM;
use User;

/**
 * One line of the credit ledger. Rows are append-only: a correction is a new row with the opposite value.
 *
 * `source` + `externalRef` identify the business event that produced the row (a Payrexx transaction, a store
 * order delivery, a POS checkout, a bulk booking). The unique index on the pair is what makes every booking
 * idempotent: the same event can never be booked twice.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmCreditRecord",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="cm_record_ref", columns={"source", "externalRef"})},
 *     indexes={@ORM\Index(name="cm_record_user", columns={"uId"}), @ORM\Index(name="cm_record_time", columns={"timestamp"})}
 * )
 */
class CreditRecord
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $Id;

    /**
     * @ORM\Column(type="integer")
     */
    protected $uId;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $comment;

    /**
     * @ORM\Column(type="datetime", name="timestamp", nullable=false)
     */
    protected $timestamp;

    /**
     * Amount in the account currency, positive = credit, negative = charge.
     * Stored as DECIMAL so sums are exact; Doctrine hands it back as a string.
     *
     * @ORM\Column(type="decimal", precision=12, scale=2, nullable=false)
     */
    protected $value;

    /**
     * Which system produced the row (payrexx, paypal, pos, cs_order, bulk, manual).
     *
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $source;

    /**
     * Identifier of the business event within `source`, unique per source.
     *
     * @ORM\Column(type="string", length=190, nullable=true)
     */
    protected $externalRef;

    /**
     * @param \Concrete\Core\User\User|\Concrete\Core\User\UserInfo|int $user
     * @param string|float|int $value
     * @param string|null $comment
     * @param string|null $source
     * @param string|null $externalRef
     */
    public function __construct($user, $value, $comment, $source = null, $externalRef = null)
    {
        $this->setUser($user);
        $this->setValue($value);
        $this->setComment($comment);
        $this->setTimestamp();
        $this->source = $source !== null ? (string) $source : null;
        $this->externalRef = $externalRef !== null ? (string) $externalRef : null;
    }

    public function getId()
    {
        return $this->Id;
    }

    public function getUserId()
    {
        return (int) $this->uId;
    }

    public function getUser()
    {
        return User::getByUserID($this->uId);
    }

    public function getComment()
    {
        return $this->comment;
    }

    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * @return float
     */
    public function getValue()
    {
        return (float) $this->value;
    }

    /**
     * @return string the amount with two decimals, e.g. "-12.50"
     */
    public function getValueString()
    {
        return self::normalizeAmount($this->value);
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getExternalRef()
    {
        return $this->externalRef;
    }

    private function setUser($user)
    {
        if (is_object($user) && method_exists($user, 'getUserID')) {
            $this->uId = (int) $user->getUserID();
        } elseif (is_numeric($user)) {
            $this->uId = (int) $user;
        }
        if (!$this->uId) {
            throw new \InvalidArgumentException('A credit record needs a user.');
        }
        return $this;
    }

    private function setComment($comment)
    {
        $this->comment = $comment === null ? null : (string) $comment;
        return $this;
    }

    private function setTimestamp()
    {
        $this->timestamp = new \DateTime('now');
        return $this;
    }

    private function setValue($value)
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('A credit record value must be numeric.');
        }
        $this->value = self::normalizeAmount($value);
        return $this;
    }

    /**
     * Rounds to two decimals and formats without thousands separators, the form the DECIMAL column expects.
     *
     * @param string|float|int $amount
     * @return string
     */
    public static function normalizeAmount($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * @param int $id
     * @return CreditRecord|null
     */
    public static function getById($id)
    {
        $em = Database::connection()->getEntityManager();
        return $em->find(self::class, (int) $id);
    }

    /**
     * Tags the record with a category (topic node id or name). Silently skipped when the record is not persisted
     * yet; unknown names are logged by the resolver.
     *
     * @param int|string $cat
     * @return $this
     */
    public function addCategory($cat)
    {
        $nodeId = CategoryResolver::resolve($cat);
        if (!$nodeId || !$this->getId()) {
            return $this;
        }
        $em = Database::connection()->getEntityManager();
        $existing = $em->getRepository(CreditRecordCategory::class)->findOneBy(['record' => $this->getId(), 'nodeId' => $nodeId]);
        if (!$existing) {
            $em->persist(new CreditRecordCategory($this, $nodeId));
            $em->flush();
        }
        return $this;
    }

    public function addCategories($categories)
    {
        foreach ((array) $categories as $cat) {
            $this->addCategory($cat);
        }
        return $this;
    }

    /**
     * @return CreditRecordCategory[]
     */
    public function getCategories()
    {
        if (!$this->getId()) {
            return [];
        }
        $em = Database::connection()->getEntityManager();
        return $em->getRepository(CreditRecordCategory::class)->findBy(['record' => $this->getId()]);
    }
}
