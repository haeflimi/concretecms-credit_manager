<?php
namespace CreditManager\Entity;

use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tag of a legacy product with a topic tree node.
 *
 * @ORM\Entity()
 * @ORM\Table(name="cmProductCategory")
 */
class ProductCategory
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     */
    protected $pId;

    /**
     * Node Id of the topic tree node used as category.
     *
     * @ORM\Id
     * @ORM\Column(type="integer")
     */
    protected $nodeId;

    /**
     * @param Product $p
     * @param TopicTreeNode|int $node
     */
    public function __construct(Product $p, $node)
    {
        $this->pId = (int) $p->getId();
        $this->nodeId = $node instanceof TreeNode ? (int) $node->getTreeNodeID() : (int) $node;
    }

    public function getProductId()
    {
        return (int) $this->pId;
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
