<?php
namespace CreditManager\Entity;

use Concrete\Core\Support\Facade\Database;
use Doctrine\ORM\Mapping as ORM;

/**
 * Raw log of every payment notification the package received, written before anything is booked. It is the
 * audit trail for reconciling the ledger with the payment provider: which notifications came in, what they
 * contained, and what the package did with them.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmPaymentEvent", indexes={@ORM\Index(name="cm_payment_event_ref", columns={"provider", "externalId"})})
 */
class PaymentEvent
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $Id;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $provider;

    /**
     * @ORM\Column(type="string", length=190, nullable=true)
     */
    protected $externalId;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $payload;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $receivedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $processedAt;

    /**
     * Short outcome, e.g. "booked", "duplicate", "ignored: status waiting", "error: ...".
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $result;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $recordId;

    public function __construct($provider, $externalId, $payload)
    {
        $this->provider = (string) $provider;
        $this->externalId = $externalId === null ? null : substr((string) $externalId, 0, 190);
        $this->payload = $payload === null ? null : (string) $payload;
        $this->receivedAt = new \DateTime('now');
    }

    /**
     * Stores a received notification immediately, outside of any later transaction.
     */
    public static function record($provider, $externalId, $payload)
    {
        $em = Database::connection()->getEntityManager();
        $event = new self($provider, $externalId, $payload);
        $em->persist($event);
        $em->flush();
        return $event;
    }

    /**
     * Marks the event as handled.
     *
     * @param string $result
     * @param CreditRecord|null $record
     */
    public function finish($result, CreditRecord $record = null)
    {
        $this->result = substr((string) $result, 0, 255);
        $this->processedAt = new \DateTime('now');
        $this->recordId = $record ? $record->getId() : null;
        $em = Database::connection()->getEntityManager();
        if ($em->isOpen()) {
            $em->persist($this);
            $em->flush();
        } else {
            // the entity manager was closed by a failed transaction: write the outcome with plain SQL
            Database::connection()->update('cmPaymentEvent', [
                'result' => $this->result,
                'processedAt' => $this->processedAt->format('Y-m-d H:i:s'),
                'recordId' => $this->recordId,
            ], ['Id' => $this->Id]);
        }
        return $this;
    }

    public function getId()
    {
        return $this->Id;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function getExternalId()
    {
        return $this->externalId;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getReceivedAt()
    {
        return $this->receivedAt;
    }

    public function getProcessedAt()
    {
        return $this->processedAt;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getRecordId()
    {
        return $this->recordId;
    }
}
