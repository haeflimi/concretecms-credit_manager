<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage\Dashboard\CreditManager;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Concrete\Core\User\UserList;
use Concrete\Core\User\EditResponse as UserEditResponse;
use CreditManager\Repository\CmUserList;
use CreditManager\Repository\ProductList;
use Exception;
use PermissionKey;
use Permissions;
use User;
use UserAttributeKey;
use UserInfo;
use Group;
use Page;
use Site;
use Config;
use Core;
use URL;

class Products extends DashboardPageController
{
    public function __construct(Page $c)
    {
        parent::__construct($c);

    }

    public function view()
    {
        $this->requireAsset('core/topics');
        $this->requireAsset('select2');
        $this->requireAsset('core/file-manager');

        $pl = new ProductList();
        // only apply default filtering when not looking for someone specific
        if($keywords = $this->get('keywords')){
            $pl->filterByKeywords($keywords);
        }

        $this->set('pl', $pl);
        $this->set('productList', $pl->getResults());
    }

    public function deleteProduct($pId)
    {
        $em = Database::connection()->getEntityManager();
        $product = $em->find('CreditManager\Entity\Product', $pId);
        $res = $em->remove($product);
        $em->flush($product);
        
        $this->flash('success', t('Product Removed'));
        $this->redirect(URL::to('/dashboard/credit_manager/products'));
    }
}
