<?php
namespace CreditManager\Service;

use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\Category as CategoryNode;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;

/**
 * Turns category names or node ids into topic tree node ids.
 *
 * Names are looked up inside the package's own topic tree (config `credit_manager.categories_topic`) first, so a
 * topic with the same name in another tree can never be picked by accident. A name that cannot be resolved is
 * logged instead of being dropped silently.
 */
class CategoryResolver
{
    /** @var array<string,int|null> */
    private static $cache = [];

    /**
     * @param int|string|TreeNode $category
     * @return int|null the tree node id
     */
    public static function resolve($category)
    {
        if ($category instanceof TreeNode) {
            return (int) $category->getTreeNodeID();
        }
        if ($category === null || $category === '' || $category === false) {
            return null;
        }
        if (is_numeric($category)) {
            $node = TopicTreeNode::getByID((int) $category);
            return is_object($node) && !($node instanceof CategoryNode) ? (int) $node->getTreeNodeID() : null;
        }
        $name = trim((string) $category);
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }
        $nodeId = self::findByName($name);
        if ($nodeId === null) {
            Application::getFacadeApplication()->make('log')
                ->warning(sprintf('Credit Manager: category "%s" does not exist in the categories topic tree, the record stays untagged.', $name));
        }
        self::$cache[$name] = $nodeId;
        return $nodeId;
    }

    /**
     * @param array $categories
     * @return int[] unique node ids, unresolvable entries left out
     */
    public static function resolveMany($categories)
    {
        $ids = [];
        foreach ((array) $categories as $category) {
            $id = self::resolve($category);
            if ($id) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @return TopicTree|null the configured categories tree
     */
    public static function getTree()
    {
        $treeId = (int) Config::get('credit_manager.categories_topic');
        if ($treeId <= 0) {
            return null;
        }
        $tree = TopicTree::getByID($treeId);
        return $tree instanceof TopicTree ? $tree : null;
    }

    /**
     * Selectable topics of the configured tree, keyed by node id, for the dashboard dialogs.
     *
     * @return array<int,string>
     */
    public static function getSelectableTopics()
    {
        $nodes = [];
        $tree = self::getTree();
        if (!$tree) {
            return $nodes;
        }
        $root = $tree->getRootTreeNodeObject();
        if (!$root) {
            return $nodes;
        }
        foreach ($root->getAllChildNodeIDs() as $nodeId) {
            $node = TreeNode::getByID($nodeId);
            if (!is_object($node) || $node instanceof CategoryNode) {
                continue;
            }
            $nodes[(int) $nodeId] = $node->getTreeNodeDisplayName();
        }
        return $nodes;
    }

    private static function findByName($name)
    {
        $tree = self::getTree();
        if ($tree) {
            $root = $tree->getRootTreeNodeObject();
            if ($root) {
                foreach ($root->getAllChildNodeIDs() as $nodeId) {
                    $node = TreeNode::getByID($nodeId);
                    if (is_object($node) && !($node instanceof CategoryNode) && $node->getTreeNodeName() === $name) {
                        return (int) $node->getTreeNodeID();
                    }
                }
            }
            return null;
        }
        // no tree configured yet: fall back to the global lookup of older versions
        $node = TopicTreeNode::getNodeByName($name);
        return is_object($node) ? (int) $node->getTreeNodeID() : null;
    }
}
