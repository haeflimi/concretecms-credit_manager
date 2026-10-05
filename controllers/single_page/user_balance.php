<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use \CreditManager\CreditManager;
use Package;
use Page;
use User;

class UserBalance extends PageController
{
    public function __construct(Page $c)
    {
        parent::__construct($c);

    }

    public function view()
    {
        $pkg = Package::getByHandle('credit_manager');

        $u = new User();
        $ui = $u->getUserInfoObject();

        // Get Balance Data
        if(CreditManager::getUserBalance($u) >= 0){
            $this->set('balanceWord', t('Credit'));
        } else {
            $this->set('balanceWord', t('Debt'));
        }
        $this->set('userBalance', CreditManager::getUserBalance($u));
        $this->set('curencySymbol', $pkg->getFileConfig()->get('credit_manager.currency_symbol'));
    }
}
