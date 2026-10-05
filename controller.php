<?php
namespace Concrete\Package\CreditManager;

use Concrete\Core\Backup\ContentImporter;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\View\View;
use Concrete\Core\Page\Single as SinglePage;
use PermissionAccess;
use Concrete\Core\Permission\Access\Entity\GroupEntity as PermissionAccessEntity;
use Package,
    Route,
    BlockType,
    Page,
    Events,
    AssetList,
    Group;

class Controller extends Package implements ProviderAggregateInterface
{
    protected $pkgHandle = 'credit_manager';
    protected $appVersionRequired = '8.4';
    protected $pkgVersion = '1.4.36';
    protected $pkgAutoloaderRegistries = array(
        'src/PaymentMethods' => '\CreditManager\PaymentMethods',
        'src/Entity' => '\CreditManager\Entity',
        'src/Repository' => '\CreditManager\Repository',
        'src/CreditManager' => '\CreditManager',
        'src/PageControllers' => '\CreditManager\PageControllers'
    );

    public function getPackageName()
    {
        return t('Credit Manager');
    }

    public function getPackageDescription()
    {
        return t('Adds a Credit Account to every User that tracks transactions and supplyes ways to transfer
        founds to the account.');
    }

    public function getEntityManagerProvider()
    {
        $provider = new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'CreditManager\Entity'
        ]);
        return $provider;
    }

    public function on_start()
    {
        $pkg = Package::getByHandle($this->pkgHandle);

        // register routes for payment method callbacks and modal dialogs
        Route::registerMultiple(array(
            '/ccm/credit_manager/callback/payrexx' => array('\CreditManager\PaymentMethods\Payrexx::callback'),
            '/ccm/credit_manager/callback/paypal' => array('\CreditManager\PaymentMethods\Paypal::callback'),
            '/ccm/credit_manager/callback/verify' => array('\CreditManager\PaymentMethods\Paypal::verify'),
            '/ccm/credit_manager/add_record/{uId}' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddRecord::view'),
            '/ccm/credit_manager/add_record/{uId}/confirm/' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddRecord::confirm'),
            '/ccm/credit_manager/bulk_add_record' => array('\Concrete\Package\CreditManager\Controller\Dialog\BulkAddRecord::view'),
            '/ccm/credit_manager/bulk_add_record/confirm' => array('\Concrete\Package\CreditManager\Controller\Dialog\BulkAddRecord::confirm'),
            '/ccm/credit_manager/history/{uId}' => array('\Concrete\Package\CreditManager\Controller\Dialog\History::view'),
            '/ccm/credit_manager/add_product' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::view'),
            '/ccm/credit_manager/add_product/confirm' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::confirm'),
            '/ccm/credit_manager/edit_product/{pId}/confirm' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::confirm'),
            '/ccm/credit_manager/edit_product/{pId}' => array('\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::view'),
        ));

        $al = AssetList::getInstance();
        $al->register('javascript', 'datatables', 'js/datatables.js',
            array('version' => '1.10.18', 'minify' => true, 'combine' => false), $pkg
        );
        $al->register('css', 'datatables', 'css/datatables.css',
            array('version' => '1.10.18', 'minify' => true, 'combine' => false), $pkg
        );

        $al->registerGroup('datatables', array(
            array('javascript', 'datatables'),
            array('css', 'datatables')
        ));

        $al->register('javascript', 'payrexx', 'js/providers/payrexx.js',
            array('version' => '1.0', 'minify' => true, 'combine' => false), $pkg
        );
        $al->registerGroup('payrexx', array(
            array('javascript', 'payrexx')
        ));
    }

    public function install()
    {
        $pkg = \Concrete\Core\Package\Package::install();
        $this->setUp($pkg);
    }

    public function upgrade()
    {
        parent::upgrade();
        $pkg = Package::getByHandle($this->pkgHandle);
        $this->setUp($pkg);
    }

    public function uninstall()
    {
        // @todo Remove Single Pages, Permissions, Groups, Etc. maybe make sure to NOT to delete the transaction/ account data at any point when uninstalling the package
        parent::uninstall();
    }

    private function setUp($pkg)
    {
        // Install Database Entities
        $this->installEntitiesDatabase();

        $ci = new ContentImporter();
        $ci->importContentFile($pkg->getPackagePath() . '/install.xml');

        // Setup Single Pages
        // Dashboard
        $sp  = SinglePage::add('/dashboard/credit_manager/products', $pkg);
        $sp->update(array('cName' => t('Products'), 'cDescription' => ''));
        $sp  = SinglePage::add('/dashboard/credit_manager/credit_manager', $pkg);
        $sp->update(array('cName' => t('Credit Manager'), 'cDescription' => ''));
        // Frontend
        $sp  = SinglePage::add('/balance', $pkg);
        $sp->update(array('cName' => t('Kontostand'), 'cDescription' => ''));
        $sp  = SinglePage::add('/pos', $pkg);
        $sp->update(array('cName' => t('POS'), 'cDescription' => ''));
        $sp  = SinglePage::add('/self_service_pos', $pkg);
        $sp->update(array('cName' => t('Self-Service POS'), 'cDescription' => ''));
        $sp  = SinglePage::add('/order_management', $pkg);
        $sp->update(array('cName' => t('Order Management'), 'cDescription' => ''));


        // Set Groups and Permissions
        $this->setGroupsAndPermissions($pkg);
    }

    private function setGroupsAndPermissions($pkg)
    {
        $adminGroup = Group::getByID(ADMIN_GROUP_ID);
        $adminGroupEntity = PermissionAccessEntity::getOrCreate($adminGroup);
        $cmGroup = Group::getByName('Credit Manager');
        if (!$cmGroup) {
            $cmGroup = Group::add('Credit Manager', t('The default Group for Credit Manager Administration'));
        }
        $cmGroupEntity = PermissionAccessEntity::getOrCreate($cmGroup);

        // Assign Dashboard Login Permissions
        $dashboard = Page::getByPath('/dashboard');
        $dashboard->assignPermissions($cmGroupEntity, array('view_page'));

        // Assign Credit Manager Section Permissions
        $creditManager = Page::getByPath('/dashboard/credit_manager');
        $creditManager->assignPermissions($cmGroup, array('view_page'));
        $creditManager->assignPermissions($adminGroup, array('view_page'));
    }
}