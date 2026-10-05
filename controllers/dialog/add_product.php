<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use CreditManager\CreditManager;
use Concrete\Core\Tree\Node\Type\Topic as TopicTreeNode;
use Core;
use Config;
use CreditManager\Entity\Product;
use URL;

class AddProduct extends Controller
{
    protected $viewPath = 'dialogs/add_product';

    public function view($pId = null)
    {
        $em = Database::connection()->getEntityManager();
        $this->requireAsset('javascript','core/file-manager');
        $this->requireAsset('javascript','core/topics');
        $this->requireAsset('select2');
        $tt = new TopicTree();
        $tree = $tt->getByID(Core::make('helper/security')->sanitizeInt(Config::get('credit_manager.product_categories_topic')));
        $this->set('categoryTree',$tree);
        $nodeIds = $tree->getRootTreeNodeObject()->getAllChildNodeIDs();
        $nodes = [];
        foreach($nodeIds as $key => $nodeId){
            $node = TopicTreeNode::getByID($nodeId);
            $nodes[$nodeId] = $node->getTreeNodeDisplayName();
        }
        $this->set('categoryTreeNodes', $nodes);

        $this->set('fm', Core::make('helper/concrete/file_manager'));

        if($pId){
            $product = $em->find('CreditManager\Entity\Product', $pId);
            $this->set('name', $product->getName());
            $this->set('price', $product->getPrice());
            $this->set('image', $product->getImageId());
            $this->set('isSelfService', $product->getIsSelfService()?true:false);
            $this->set('isOrder', $product->getIsOrder()?true:false);
            $selectedCategories = [];
            foreach($product->getCategories() as $cat){
                $selectedCategories[] = $cat->getCategoryId();
            }
            $this->set('selectedCategories', $selectedCategories);
        }
    }

    public function confirm($pId = null) {
        $e = $this->validate($this->post(), 'addProduct');
        $em = Database::connection()->getEntityManager();
        if($e === true){
            if($pId){
                $product = $em->find('CreditManager\Entity\Product', $pId);
            } else {
                $product = new Product();
            }
            $product->setName($this->post('name'));
            $product->setPrice($this->post('price'));
            $product->setImage($this->post('image'));
            $product->setIsSelfService($this->post('isSelfService')?1:0);
            $product->setIsOrder($this->post('isOrder')?1:0);
            $em = Database::connection()->getEntityManager();
            $em->persist($product);
            $em->flush();
            $product->updateCategories($this->post('selectedCategories'));
            $this->flash('success', t('Product Added'));
        } else {
            $this->flash('error', $e);
        }
        $this->redirect(URL::to('/dashboard/credit_manager/products'));
    }

    public function validate($data, $action = false)
    {
        $errors = new \Concrete\Core\Error\Error();

        // we want to use a token to validate each call in order to protect from xss and request forgery
        $token = \Core::make("token");
        if($action && !$token->validate($action)){
            $errors->add('Invalid Request, token must be valid.');
        }

        if ($errors->has()) {
            return $errors;
        }

        return true;
    }
}