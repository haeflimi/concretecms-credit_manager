<?php

namespace Concrete\Package\CreditManager\Block\CreditBalance;

use Concrete\Core\Block\BlockController;
use Concrete\Core\User\User;
use CreditManager\CreditManager;
use CreditManager\PaymentMethods\Payrexx;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends BlockController
{
    protected $btCacheBlockRecord = false;
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockOutputOnPost = false;
    protected $btCacheBlockOutputForRegisteredUsers = false;
    protected $pkgHandle = 'credit_manager';

    public function getBlockTypeDescription()
    {
        return t("Display the Currently signed in User's Credit Manager Balance.");
    }

    public function getBlockTypeName()
    {
        return t("Credit Manager Balance");
    }

    public function registerViewAssets($outputContent = '')
    {
        parent::registerViewAssets($outputContent);
        $this->requireAsset('payrexx');
    }

    public function view()
    {
        $user = new User();
        $balance = $user->isRegistered() ? CreditManager::getUserBalance($user) : 0.0;
        $paymentButton = '';
        if ($user->isRegistered() && $balance < 0) {
            try {
                $paymentButton = (new Payrexx())->getPaymentButton($user, -$balance);
            } catch (\Throwable $e) {
                $this->app->make('log')->warning('Credit Manager: payment button unavailable: ' . $e->getMessage());
                $paymentButton = '<p class="alert alert-danger">' . t('Online payment is currently unavailable.') . '</p>';
            }
        }
        $this->set('balance', $balance);
        $this->set('paymentButton', $paymentButton);
    }
}
