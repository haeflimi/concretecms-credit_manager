<?php

namespace Concrete\Package\CreditManager\Block\CreditBalance;

use \Concrete\Core\Block\BlockController;
use \Concrete\Core\Package\Package;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\User;
use CreditManager\CreditManager;
use CreditManager\PaymentMethods\Paypal;
use CreditManager\PaymentMethods\Payrexx;

defined('C5_EXECUTE') or die('Access Denied.');

class Controller extends BlockController
{
    protected $btCacheBlockRecord = false;
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockOutputOnPost = false;
    protected $btCacheBlockOutputForRegisteredUsers = false;
    protected $pkgHandle = 'credit_manager';

    public function __construct($obj = null)
    {

    }

    public function getBlockTypeDescription()
    {
        return t("Display the Currently singned in User's Credit Manager Balance.");
    }

    public function getBlockTypeName()
    {
        return t("Credit Manager Balance");
    }

    public function add()
    {

    }

    public function edit()
    {

    }

    public function registerViewAssets($outputContent = '')
    {
        parent::registerViewAssets($outputContent);
        $this->requireAsset('payrexx');
    }

    public function view()
    {
        $user = new User();
        $balance = CreditManager::getUserBalance($user);
        $this->set('balance', $balance);
        if($balance < 0){
            $paymentMethod = new Payrexx();
            $paymentButton = $paymentMethod->getPaymentButton($user, -$balance);
        }
        $this->set('paymentButton', $paymentButton);
    }
}