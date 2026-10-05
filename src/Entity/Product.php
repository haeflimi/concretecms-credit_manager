<?php
namespace CreditManager\Entity;

use Concrete\Core\File\File;
use Concrete\Core\Support\Facade\Database;
use CreditManager\Service\CategoryResolver;
use Doctrine\ORM\Mapping as ORM;

/**
 * Legacy own product catalogue. The POS pages sell Community Store products now; this entity remains for
 * installations that still have rows in it.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmProduct")
 */
class Product
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $Id;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $name;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $image;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $isOrder;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected $isSelfService;

    /**
     * @ORM\Column(type="decimal", precision=12, scale=2, nullable=false)
     */
    protected $price;

    public function getId()
    {
        return $this->Id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getImageId()
    {
        return $this->image;
    }

    public function getImage()
    {
        return $this->image ? File::getByID($this->image) : null;
    }

    public function setImage($imageId)
    {
        $this->image = $imageId ? (int) $imageId : null;
    }

    /**
     * @return float
     */
    public function getPrice()
    {
        return (float) $this->price;
    }

    public function setPrice($price)
    {
        if (!is_numeric($price)) {
            throw new \InvalidArgumentException('The product price must be numeric.');
        }
        $this->price = CreditRecord::normalizeAmount($price);
    }

    public function getIsOrder()
    {
        return $this->isOrder;
    }

    public function setIsOrder($isOrder)
    {
        $this->isOrder = (bool) $isOrder;
    }

    public function getIsSelfService()
    {
        return $this->isSelfService;
    }

    public function setIsSelfService($isSelfService)
    {
        $this->isSelfService = (bool) $isSelfService;
    }

    public function addCategory($cat)
    {
        $nodeId = CategoryResolver::resolve($cat);
        if (!$nodeId || !$this->getId()) {
            return $this;
        }
        $em = Database::connection()->getEntityManager();
        $existing = $em->getRepository(ProductCategory::class)->findOneBy(['pId' => $this->getId(), 'nodeId' => $nodeId]);
        if (!$existing) {
            $em->persist(new ProductCategory($this, $nodeId));
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

    public function updateCategories($categories)
    {
        $em = Database::connection()->getEntityManager();
        foreach ($this->getCategories() as $current) {
            $em->remove($current);
        }
        $em->flush();
        $this->addCategories($categories);
    }

    /**
     * @return ProductCategory[]
     */
    public function getCategories()
    {
        if (!$this->getId()) {
            return [];
        }
        $em = Database::connection()->getEntityManager();
        return $em->getRepository(ProductCategory::class)->findBy(['pId' => $this->getId()]);
    }
}
