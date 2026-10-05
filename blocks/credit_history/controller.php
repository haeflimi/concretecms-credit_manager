<?php

namespace Concrete\Package\CreditManager\Block\CreditHistory;

use Concrete\Core\Block\BlockController;
use Concrete\Core\User\User;
use CreditManager\CreditManager;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends BlockController
{
    protected $btCacheBlockRecord = false;
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockOutputOnPost = false;
    protected $btCacheBlockOutputForRegisteredUsers = false;
    protected $pkgHandle = 'credit_manager';

    protected $limit = 10;
    protected $completeLimit = 1000;

    public function getBlockTypeDescription()
    {
        return t("Display the Currently signed in User's Transaction History.");
    }

    public function getBlockTypeName()
    {
        return t("Credit Manager User History");
    }

    public function view()
    {
        $user = new User();
        $this->set('history', $user->isRegistered() ? CreditManager::getUserHistory($user, $this->limit) : []);
        $this->set('count', $user->isRegistered() ? CreditManager::getRecordCount($user) : 0);
        $this->set('limit', $this->limit);
    }

    public function action_history()
    {
        $user = new User();
        $this->set('history', $user->isRegistered() ? CreditManager::getUserHistory($user, $this->completeLimit) : []);
        $this->set('uId', $user->getUserID());
        $this->render('complete');
    }
}
