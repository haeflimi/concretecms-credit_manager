<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage\Dashboard\CreditManager;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Support\Facade\Database;
use CreditManager\Entity\OrderPosition;
use CreditManager\Entity\Product;
use CreditManager\Repository\ProductList;
use Core;
use URL;

class Products extends DashboardPageController
{
    public function view()
    {
        $this->requireAsset('select2');

        $pl = new ProductList();
        if ($keywords = $this->get('keywords')) {
            $pl->filterByKeywords($keywords);
        }

        $this->set('pl', $pl);
        $this->set('productList', $pl->getResults());
        $this->set('errors', null);
        $this->set('deleteToken', Core::make('token')->generate('cm_delete_product'));
    }

    public function deleteProduct($pId = null)
    {
        if (!Core::make('token')->validate('cm_delete_product')) {
            $this->flash('error', t('Invalid request token.'));
            $this->redirect(URL::to('/dashboard/credit_manager/products'));
        }
        $em = Database::connection()->getEntityManager();
        $product = $pId ? $em->find(Product::class, (int) $pId) : null;
        if (!$product) {
            $this->flash('error', t('Product not found.'));
            $this->redirect(URL::to('/dashboard/credit_manager/products'));
        }
        $positions = $em->getRepository(OrderPosition::class)->count(['product' => $product]);
        if ($positions > 0) {
            $this->flash('error', t('The product is referenced by %d order positions and cannot be deleted.', $positions));
            $this->redirect(URL::to('/dashboard/credit_manager/products'));
        }
        $em->transactional(function () use ($em, $product) {
            foreach ($product->getCategories() as $category) {
                $em->remove($category);
            }
            $em->remove($product);
        });

        $this->flash('success', t('Product Removed'));
        $this->redirect(URL::to('/dashboard/credit_manager/products'));
    }
}
