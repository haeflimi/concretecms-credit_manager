<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Core\User\User;
use CreditManager\CreditManager;

class Balance extends PageController
{
    const LIMIT = 500;

    public function view()
    {
        $user = new User();
        $this->set('history', CreditManager::getUserHistory($user, self::LIMIT));
        $this->set('count', CreditManager::getRecordCount($user));
        $this->set('limit', self::LIMIT);
    }
}
