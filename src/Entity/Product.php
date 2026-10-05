<?php
namespace CreditManager\Entity;

use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Doctrine\ORM\Mapping as ORM;
use CreditManager\Repository\CreditRecordList;
use Doctrine\Common\Collections\ArrayCollection;
use Concrete\Core\File\File;
use User;
use Page;

/**
 * @ORM\Entity()
 * @ORM\Table(name="cmProduct")
 *
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
     * @ORM\Column(type="float", nullable=false)
     */
    protected $price;

    /**
     * Any Credit Record can have any Topic Tag
     * @ManyToMany(targetEntity="CreditManager\Entity\ProductCategory")
     * @JoinTable(name="cmProductCategory",
     *      joinColumns={@JoinColumn(name="Id", referencedColumnName="pId")},
     *      inverseJoinColumns={@JoinColumn(name="pId", referencedColumnName="Id", unique=true, onDelete="CASCADE")}
     *      )
     */
    protected $categorie_tags;

    public function __construct() {
        $this->categorie_tags = new ArrayCollection;
    }

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
        return File::getByID($this->image);
    }

    public function setImage($imageId)
    {
        $this->image = $imageId;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function setPrice($price)
    {
        $this->price = $price;
    }

    public function getIsOrder()
    {
        return $this->isOrder;
    }

    public function setIsOrder($isOrder)
    {
        $this->isOrder = $isOrder;
    }

    public function getIsSelfService()
    {
        return $this->isSelfService;
    }

    public function setIsSelfService($isSelfService)
    {
        $this->isSelfService = $isSelfService;
    }

    public function addCategory($cat){
        if(is_numeric($cat)){
            $t = TopicTreeNode::getByID($cat);
        } else {
            $t = TopicTreeNode::getNodeByName($cat);
        }
        if(!is_object($t)){
            return $this;
        }
        $em = Database::connection()->getEntityManager();
        $category = $em->getRepository('CreditManager\Entity\ProductCategory')->findBy(['pId'=>$this->getId(),'nodeId'=>$t->getTreeNodeId()]);
        if(empty($category)){
            $pc = new ProductCategory($this, $t);
            $em->persist($pc);
            $em->flush();
        }
        return $this;
    }

    public function addCategories($categories){
        foreach($categories as $nodeId){
            $this->addCategory($nodeId);
        }
        return $this;
    }

    public function updateCategories($categories){
        $em = Database::connection()->getEntityManager();
        $currentCategories = $em->getRepository('CreditManager\Entity\ProductCategory')->findBy(['pId'=>$this->getId()]);
        foreach($currentCategories as $cC){
            $em->remove($cC);
            $em->flush();
        }
        $this->addCategories($categories);
    }

    public function getCategories(){
        $em = Database::connection()->getEntityManager();
        return $em->getRepository('CreditManager\Entity\ProductCategory')->findBy(['pId'=>$this->getId()]);
    }
}