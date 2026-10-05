<?php
namespace Concrete\Package\CreditManager;

use Concrete\Core\Asset\AssetList;
use Concrete\Core\Backup\ContentImporter;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Permission\Access\Entity\GroupEntity as PermissionAccessEntity;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\Support\Facade\Route;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Concrete\Core\User\Group\Group;

class Controller extends Package implements ProviderAggregateInterface
{
    const CATEGORY_TREE_NAME = 'Credit Manager Kategorien';

    protected $pkgHandle = 'credit_manager';
    protected $appVersionRequired = '8.4';
    protected $pkgVersion = '1.5.0';
    protected $pkgAutoloaderRegistries = [
        'src/PaymentMethods' => '\CreditManager\PaymentMethods',
        'src/Entity' => '\CreditManager\Entity',
        'src/Repository' => '\CreditManager\Repository',
        'src/CreditManager' => '\CreditManager',
        'src/PageControllers' => '\CreditManager\PageControllers',
        'src/Service' => '\CreditManager\Service',
        'src/Controller' => '\CreditManager\Controller',
    ];

    public function getPackageName()
    {
        return t('Credit Manager');
    }

    public function getPackageDescription()
    {
        return t('Adds a Credit Account to every User that tracks transactions and supplies ways to transfer funds to the account.');
    }

    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'CreditManager\Entity',
        ]);
    }

    public function on_start()
    {
        $pkg = Package::getByHandle($this->pkgHandle);

        // payment provider webhooks and the dashboard dialogs (the dialogs check the dashboard permission themselves)
        Route::registerMultiple([
            '/ccm/credit_manager/callback/payrexx' => ['\CreditManager\PaymentMethods\Payrexx::callback'],
            '/ccm/credit_manager/callback/paypal' => ['\CreditManager\PaymentMethods\Paypal::callback'],
            '/ccm/credit_manager/add_record/{uId}' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddRecord::view'],
            '/ccm/credit_manager/add_record/{uId}/confirm/' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddRecord::confirm'],
            '/ccm/credit_manager/bulk_add_record' => ['\Concrete\Package\CreditManager\Controller\Dialog\BulkAddRecord::view'],
            '/ccm/credit_manager/bulk_add_record/count' => ['\Concrete\Package\CreditManager\Controller\Dialog\BulkAddRecord::count'],
            '/ccm/credit_manager/bulk_add_record/confirm' => ['\Concrete\Package\CreditManager\Controller\Dialog\BulkAddRecord::confirm'],
            '/ccm/credit_manager/history/{uId}' => ['\Concrete\Package\CreditManager\Controller\Dialog\History::view'],
            '/ccm/credit_manager/add_product' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::view'],
            '/ccm/credit_manager/add_product/confirm' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::confirm'],
            '/ccm/credit_manager/edit_product/{pId}/confirm' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::confirm'],
            '/ccm/credit_manager/edit_product/{pId}' => ['\Concrete\Package\CreditManager\Controller\Dialog\AddProduct::view'],
        ]);

        $al = AssetList::getInstance();
        $al->register('javascript', 'datatables', 'js/datatables.js', ['version' => '1.10.18', 'minify' => true, 'combine' => false], $pkg);
        $al->register('css', 'datatables', 'css/datatables.css', ['version' => '1.10.18', 'minify' => true, 'combine' => false], $pkg);
        $al->registerGroup('datatables', [
            ['javascript', 'datatables'],
            ['css', 'datatables'],
        ]);

        $al->register('javascript', 'payrexx', 'js/providers/payrexx.js', ['version' => '1.0', 'minify' => true, 'combine' => false], $pkg);
        $al->registerGroup('payrexx', [
            ['javascript', 'payrexx'],
        ]);
    }

    public function install()
    {
        $pkg = parent::install();
        $this->setUp($pkg);
    }

    public function upgrade()
    {
        $before = $this->prepareSchemaMigration();
        parent::upgrade();
        $this->verifySchemaMigration($before);
        $this->refreshEntityMetadata();
        $this->setUp($this->getPackageEntity());
    }

    /**
     * The ledger tables are deliberately kept: the core removes pages, blocks and attributes but never drops a
     * package's entity tables, and nothing in here must either. Reinstalling the package picks the data up again.
     */
    public function uninstall()
    {
        parent::uninstall();
    }

    private function setUp($pkg)
    {
        $ci = new ContentImporter();
        $ci->importContentFile($this->getPackagePath() . '/install.xml');
        $this->installCategoryTree($ci);

        // Dashboard
        $sp = SinglePage::add('/dashboard/credit_manager', $pkg);
        $sp->update(['cName' => t('Credit Manager'), 'cDescription' => '']);
        $sp = SinglePage::add('/dashboard/credit_manager/products', $pkg);
        $sp->update(['cName' => t('Products'), 'cDescription' => '']);
        $sp = SinglePage::add('/dashboard/credit_manager/credit_manager', $pkg);
        $sp->update(['cName' => t('Credit Manager'), 'cDescription' => '']);
        // Frontend
        $sp = SinglePage::add('/balance', $pkg);
        $sp->update(['cName' => t('Kontostand'), 'cDescription' => '']);
        $sp = SinglePage::add('/pos', $pkg);
        $sp->update(['cName' => t('POS'), 'cDescription' => '']);
        $sp = SinglePage::add('/self_service_pos', $pkg);
        $sp->update(['cName' => t('Self-Service POS'), 'cDescription' => '']);
        $sp = SinglePage::add('/order_management', $pkg);
        $sp->update(['cName' => t('Order Management'), 'cDescription' => '']);

        $this->setGroupsAndPermissions();
    }

    /**
     * Imports the category topic tree once and records its id in the config, instead of re-importing it on every
     * upgrade (which created duplicate trees and made category names ambiguous).
     */
    private function installCategoryTree(ContentImporter $ci)
    {
        $tree = TopicTree::getByName(self::CATEGORY_TREE_NAME);
        if (!$tree) {
            $ci->importContentFile($this->getPackagePath() . '/install_tree.xml');
            $tree = TopicTree::getByName(self::CATEGORY_TREE_NAME);
        }
        $config = $this->app->make('config');
        if ($tree && (int) $config->get('credit_manager.categories_topic') <= 0) {
            $config->save('credit_manager.categories_topic', (int) $tree->getTreeID());
        }
    }

    private function setGroupsAndPermissions()
    {
        $adminGroup = Group::getByID(ADMIN_GROUP_ID);
        $cmGroup = Group::getByName('Credit Manager');
        if (!$cmGroup) {
            $cmGroup = Group::add('Credit Manager', t('The default Group for Credit Manager Administration'));
        }
        $cmGroupEntity = PermissionAccessEntity::getOrCreate($cmGroup);
        $adminGroupEntity = PermissionAccessEntity::getOrCreate($adminGroup);

        $dashboard = Page::getByPath('/dashboard');
        if ($dashboard && !$dashboard->isError()) {
            $dashboard->assignPermissions($cmGroupEntity, ['view_page']);
        }
        $creditManager = Page::getByPath('/dashboard/credit_manager');
        if ($creditManager && !$creditManager->isError()) {
            $creditManager->assignPermissions($cmGroupEntity, ['view_page']);
            $creditManager->assignPermissions($adminGroupEntity, ['view_page']);
        }
    }

    /**
     * Runs before Doctrine updates the schema: removes category rows without a record (they would block the new
     * foreign key) and notes the ledger total, so the float → decimal conversion can be checked afterwards.
     *
     * @return array|null [count, sum]
     */
    private function prepareSchemaMigration()
    {
        $db = Database::connection();
        $tables = $db->getSchemaManager()->listTableNames();
        if (!in_array('cmCreditRecord', $tables, true)) {
            return null;
        }
        if (in_array('cmCreditRecordCategory', $tables, true)) {
            $deleted = $db->executeUpdate('DELETE crc FROM cmCreditRecordCategory crc LEFT JOIN cmCreditRecord cr ON cr.Id = crc.crId WHERE cr.Id IS NULL');
            if ($deleted) {
                $this->app->make('log')->warning('Credit Manager: removed ' . $deleted . ' category rows that pointed to no record.');
            }
        }
        $row = $db->fetchAssoc('SELECT COUNT(*) AS c, COALESCE(SUM(ROUND(value, 2)), 0) AS s FROM cmCreditRecord');
        return [(int) $row['c'], round((float) $row['s'], 2)];
    }

    private function verifySchemaMigration($before)
    {
        if ($before === null) {
            return;
        }
        $row = Database::connection()->fetchAssoc('SELECT COUNT(*) AS c, COALESCE(SUM(value), 0) AS s FROM cmCreditRecord');
        $after = [(int) $row['c'], round((float) $row['s'], 2)];
        $log = $this->app->make('log');
        if ($after !== $before) {
            $log->error(sprintf('Credit Manager: ledger changed during the upgrade! before: %d records / %.2f, after: %d records / %.2f', $before[0], $before[1], $after[0], $after[1]));
        } else {
            $log->info(sprintf('Credit Manager: ledger verified after upgrade: %d records, total %.2f', $after[0], $after[1]));
        }
    }

    /**
     * Doctrine caches the entity mappings and generates the proxies once; after an upgrade that changed an entity
     * both would still describe the old version.
     */
    private function refreshEntityMetadata()
    {
        try {
            $em = $this->app->make('Doctrine\ORM\EntityManagerInterface');
            $cache = $em->getConfiguration()->getMetadataCacheImpl();
            if ($cache) {
                $cache->deleteAll();
            }
            $metadata = array_filter($em->getMetadataFactory()->getAllMetadata(), static function ($class) {
                return strpos($class->getName(), 'CreditManager\\') === 0;
            });
            $em->getProxyFactory()->generateProxyClasses($metadata);
        } catch (\Throwable $e) {
            $this->app->make('log')->warning('Credit Manager: unable to refresh the entity metadata: ' . $e->getMessage());
        }
    }
}
