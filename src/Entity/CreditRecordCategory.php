<?php
namespace CreditManager\Entity;

use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tag of a credit record with a topic tree node. The record side is a real foreign key (cascade on delete), the
 * node side stays a plain id because topic nodes live in the core tree tables.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmCreditRecordCategory", indexes={@ORM\Index(name="cm_record_cat_node", columns={"nodeId"})})
 */
class CreditRecordCategory
{
    /**
     * @ORM\Id
     * @ORM\ManyToOne(targetEntity="CreditManager\Entity\CreditRecord")
     * @ORM\JoinColumn(name="crId", referencedColumnName="Id", nullable=false, onDelete="CASCADE")
     */
    protected $record;

    /**
     * Node Id of the topic tree node used as category.
     *
     * @ORM\Id
     * @ORM\Column(type="integer")
     */
    protected $nodeId;

    /**
     * @param CreditRecord $cr
     * @param TopicTreeNode|int $node
     */
    public function __construct(CreditRecord $cr, $node)
    {
        $this->record = $cr;
        $this->nodeId = $node instanceof TreeNode ? (int) $node->getTreeNodeID() : (int) $node;
    }

    public function getCreditRecordId()
    {
        return $this->record ? $this->record->getId() : null;
    }

    public function getCreditRecord()
    {
        return $this->record;
    }

    public function getCategoryId()
    {
        return (int) $this->nodeId;
    }

    public function getCategoryName()
    {
        $t = TopicTreeNode::getByID($this->nodeId);
        if (is_object($t)) {
            return $t->getTreeNodeDisplayName();
        }
        return '';
    }
}
