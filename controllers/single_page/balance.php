<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use CreditManager\CreditManager;
use Concrete\Core\User\User;
use Package;
use Core;

class Balance extends PageController
{
    public function view()
    {
        $user = new User();
        $ui = $user->getUserInfoObject();
        $history = CreditManager::getUserHistory($user, 99999999);
        $this->set('history', $history);
    }
}
