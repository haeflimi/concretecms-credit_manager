<?php
namespace CreditManager\Entity;

use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Doctrine\ORM\Mapping as ORM;
use CreditManager\Repository\CreditRecordList;
use Doctrine\Common\Collections\ArrayCollection;
use User;
use Page;

/**
 * @ORM\Entity()
 * @ORM\Table(name="cmCreditRecord")
 *
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
     * @ORM\Column(type="float", nullable=false)
     */
    protected $value;

    /**
     * Any Credit Record can have any Topic Tag
     * @ManyToMany(targetEntity="CreditManager\Entity\CreditRecordCategory")
     * @JoinTable(name="cmCreditRecordCategory",
     *      joinColumns={@JoinColumn(name="Id", referencedColumnName="crId")},
     *      inverseJoinColumns={@JoinColumn(name="crId", referencedColumnName="Id", unique=true, onDelete="CASCADE")}
     *      )
     */
    protected $categorie_tags;

    public function __construct($user, $value, $comment) {
        $this->categorie_tags  = new ArrayCollection();
        $this->setUser($user);
        $this->setValue($value);
        $this->setComment($comment);
        $this->setTimestamp();
        return $this;
    }

    public function getId()
    {
        return $this->Id;
    }

    public function getUser(){
        return User::getByID($this->uId);
    }

    public function getComment(){
        return $this->comment;
    }

    public function getTimestamp(){
        return $this->timestamp;
    }

    public function getValue(){
        return $this->value;
    }

    private function setUser($user){
        if(is_object($user)){
            $this->uId = $user->getUserID();
        } elseif(is_numeric($user)){
            $this->uId = $user;
        }
        return $this;
    }

    private function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    private function setTimestamp()
    {
        $this->timestamp = new \DateTime('now');
        return $this;
    }

    private function setValue($value){
        return $this->value = $value;
    }

    public static function getById($id){
        $em = Database::connection()->getEntityManager();
        return $em->find('CreditManager\Entity\CreditRecord', $id);
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
        $crc = new CreditRecordCategory($this, $t);
        $em->persist($crc);
        $em->flush();
        return $this;
    }

    public function addCategories($categories){
        if(!empty($categories)) foreach($categories as $nodeId){
            $this->addCategory($nodeId);
        }
        return $this;
    }

    public function getCategories(){
        $em = Database::connection()->getEntityManager();
        return $em->getRepository('CreditManager\Entity\CreditRecordCategory')->findBy(['crId'=>$this->getId()]);
    }
}