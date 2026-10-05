<?php
namespace CreditManager\Entity;

use Doctrine\ORM\Mapping as ORM;
use User;

/**
 * Legacy order line for the own product catalogue; superseded by Community Store orders.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmOrderPosition")
 */
class OrderPosition
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
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $quantity;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $status;

    /**
     * @ORM\ManyToOne(targetEntity="CreditManager\Entity\Product")
     * @ORM\JoinColumn(name="product_id", referencedColumnName="Id", nullable=true, onDelete="SET NULL")
     */
    protected $product;

    public function getId()
    {
        return $this->Id;
    }

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function setQuantity($qnt)
    {
        $this->quantity = (int) $qnt;
    }

    public function getProduct()
    {
        return $this->product;
    }

    public function setProduct($product)
    {
        $this->product = $product;
    }

    public function getUserId()
    {
        return $this->uId;
    }

    public function setUserId($userId)
    {
        $this->uId = (int) $userId;
    }

    public function getUser()
    {
        return User::getByUserID($this->uId);
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public static function getAllStates()
    {
        return [
            'open' => 'Offen',
            'ordered' => 'Bestellt',
            'delivered' => 'Ausgeliefert',
            'closed' => 'Abgeschlossen',
        ];
    }
}
