<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use CreditManager\Controller\DashboardDialog;
use CreditManager\CreditManager;

class History extends DashboardDialog
{
    protected $viewPath = 'dialogs/history';

    public function view($uId)
    {
        $history = CreditManager::getUserHistory((int) $uId, 500);
        $this->set('history', $history);
        $this->set('uId', (int) $uId);
        $this->set('balance', CreditManager::getUserBalance((int) $uId));
        $this->set('count', CreditManager::getRecordCount((int) $uId));
    }
}
