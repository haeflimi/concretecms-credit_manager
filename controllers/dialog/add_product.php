<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Node\Node as TreeNode;
use Concrete\Core\Tree\Node\Type\Category as CategoryNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use CreditManager\Controller\DashboardDialog;
use CreditManager\Entity\Product;
use Config;
use Core;
use URL;

class AddProduct extends DashboardDialog
{
    protected $viewPath = 'dialogs/add_product';

    public function view($pId = null)
    {
        $em = Database::connection()->getEntityManager();
        $this->requireAsset('select2');

        $tree = null;
        $nodes = [];
        $treeId = (int) Config::get('credit_manager.product_categories_topic');
        if ($treeId > 0) {
            $tree = TopicTree::getByID($treeId);
        }
        if ($tree instanceof TopicTree && $tree->getRootTreeNodeObject()) {
            foreach ($tree->getRootTreeNodeObject()->getAllChildNodeIDs() as $nodeId) {
                $node = TreeNode::getByID($nodeId);
                if (is_object($node) && !($node instanceof CategoryNode)) {
                    $nodes[(int) $nodeId] = $node->getTreeNodeDisplayName();
                }
            }
        }
        $this->set('categoryTree', $tree instanceof TopicTree ? $tree : null);
        $this->set('categoryTreeNodes', $nodes);
        $this->set('fm', Core::make('helper/concrete/file_manager'));
        $this->set('pId', $pId ? (int) $pId : null);
        $this->set('name', '');
        $this->set('price', '');
        $this->set('image', null);
        $this->set('isSelfService', false);
        $this->set('isOrder', false);
        $this->set('selectedCategories', []);

        if ($pId) {
            $product = $em->find(Product::class, (int) $pId);
            if ($product) {
                $this->set('name', $product->getName());
                $this->set('price', $product->getPrice());
                $this->set('image', $product->getImageId());
                $this->set('isSelfService', (bool) $product->getIsSelfService());
                $this->set('isOrder', (bool) $product->getIsOrder());
                $selectedCategories = [];
                foreach ($product->getCategories() as $cat) {
                    $selectedCategories[] = $cat->getCategoryId();
                }
                $this->set('selectedCategories', $selectedCategories);
            }
        }
    }

    public function confirm($pId = null)
    {
        $e = $this->validate($this->post(), 'addProduct');
        if ($e === true) {
            $em = Database::connection()->getEntityManager();
            $product = $pId ? $em->find(Product::class, (int) $pId) : null;
            if (!$product) {
                $product = new Product();
            }
            $product->setName($this->post('name'));
            $product->setPrice($this->post('price'));
            $product->setImage($this->post('image'));
            $product->setIsSelfService($this->post('isSelfService') ? 1 : 0);
            $product->setIsOrder($this->post('isOrder') ? 1 : 0);
            $em->persist($product);
            $em->flush();
            $categories = $this->post('selectedCategories');
            $product->updateCategories(is_array($categories) ? $categories : []);
            $this->flash('success', t('Product Saved'));
        } else {
            $this->flash('error', $e);
        }
        $this->redirect(URL::to('/dashboard/credit_manager/products'));
    }

    /**
     * @return ErrorList|true
     */
    public function validate($data, $action = false)
    {
        $errors = new ErrorList();

        if ($action && !Core::make('token')->validate($action)) {
            $errors->add(t('Invalid Request, token must be valid.'));
        }
        if (!$this->canAccess()) {
            $errors->add(t('Access Denied'));
        }
        if ($action == 'addProduct') {
            if (empty($data['name'])) {
                $errors->add(t('The product needs a name.'));
            }
            if (!isset($data['price']) || !is_numeric($data['price'])) {
                $errors->add(t('The product price must be a number.'));
            }
        }

        if ($errors->has()) {
            return $errors;
        }

        return true;
    }
}
