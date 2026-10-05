<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Concrete\Core\Support\Facade\Database;
use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductType\ProductType;
use CreditManager\PageControllers\PosPageController;

class Pos extends PosPageController
{
    protected $productType = 3;

    public function getCmCategory(){
        return 'Catering POS';
    }

    public function getVisibleProducts(){
        $productList = new ProductList();
        $productList->setProductType(ProductType::getByID($this->productType));
        $productObjects = $productList->getResults();
        $products = array();
        foreach($productObjects as $p){
            $product['id'] = $p->getID();
            $product['name'] = $p->getName();
            $product['price'] = $p->getPrice();
            $product['image'] = $p->getImageObj();
            $products[] = $product;
        }
        return $products;
    }
}
