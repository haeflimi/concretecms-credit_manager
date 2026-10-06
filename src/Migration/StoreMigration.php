<?php
namespace CreditManager\Migration;

use Concrete\Core\Application\Application;
use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Localization\Localization;
use Concrete\Core\Support\Facade\Config;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group as StoreGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductGroup;
use Doctrine\DBAL\Connection;

/**
 * Moves the credit_manager ledger into Community Store.
 *
 * Every ledger row becomes one archived, paid store order (charges and top-ups alike), each member's final
 * balance becomes either an unpaid "open balance" order (debt) or store credit (surplus, through the
 * community_store_credit package). The run is idempotent: orders carry the ledger row id as transaction
 * reference (`cm:<Id>`), balances use `cm:debt:<uId>` / `migration:cm_balance:<uId>`; rows already present are
 * skipped. A dry run writes the reports and changes nothing.
 */
class StoreMigration
{
    const REF_PREFIX = 'cm:';
    const SOURCE = 'cm_migration';
    const GROUP_NAME = 'Vereinskonto (Archiv)';
    const PRODUCT_TOPUP = 'Kontoeinzahlung';
    const PRODUCT_DEBT = 'Offener Saldo Vereinskonto';
    const PRODUCT_CHARGE_DEFAULT = 'Manuell';
    const PAYMENT_ACCOUNT = 'Vereinskonto';
    const STATUS_ARCHIVE = 'delivered';
    const STATUS_DEBT = 'incomplete';
    const PAYMENT_NAMES = ['Payrexx', 'Paypal', 'Bar', 'Überweisung'];
    const ATTRIBUTE_KEYS = [
        'cm_record_id' => ['number', 'Credit Manager Record Id'],
        'cm_source' => ['text', 'Credit Manager Source'],
        'cm_kind' => ['text', 'Credit Manager Kind'],
        'cm_archive' => ['boolean', 'Credit Manager Archive'],
        'cm_user' => ['text', 'Credit Manager User'],
    ];

    /** @var Application */
    protected $app;
    /** @var Connection */
    protected $db;
    /** @var \Doctrine\ORM\EntityManagerInterface */
    protected $em;
    /** @var callable */
    protected $log;
    protected $dryRun = true;
    protected $limit = 0;
    protected $reportDir;
    protected $cutover;

    protected $nodeNames = [];
    protected $users = [];
    protected $existingRefs = [];
    protected $products = [];
    protected $group;
    protected $stats = [];

    public function __construct(Application $app, array $options, callable $log)
    {
        $this->app = $app;
        $this->db = $app->make('database')->connection();
        $this->em = $this->db->getEntityManager();
        $this->log = $log;
        $this->dryRun = empty($options['apply']);
        $this->limit = isset($options['limit']) ? (int) $options['limit'] : 0;
        $this->reportDir = isset($options['reportDir']) ? rtrim($options['reportDir'], '/') : sys_get_temp_dir();
        $this->cutover = isset($options['cutover']) ? new \DateTime($options['cutover']) : new \DateTime('now');
    }

    /**
     * @return int exit code (0 = OK)
     */
    public function run()
    {
        $this->say(($this->dryRun ? 'DRY RUN' : 'APPLY') . ' — credit_manager → Community Store, cutover ' . $this->cutover->format('Y-m-d H:i'));
        if (!is_dir($this->reportDir) && !mkdir($this->reportDir, 0775, true)) {
            $this->say('Cannot create report dir ' . $this->reportDir);
            return 2;
        }
        $this->preflight();
        $this->loadNodeNames();
        $this->loadUsers();
        $this->loadExistingRefs();

        $rows = $this->loadRecords();
        $plan = [];
        foreach ($rows as $row) {
            $plan[] = $this->classify($row);
        }
        $this->writeCsv('migration_report.csv', ['recordId', 'uId', 'user', 'timestamp', 'value', 'kind', 'categories', 'paymentMethod', 'product', 'action', 'orderId'], array_map(function ($p) {
            return [$p['id'], $p['uId'], $p['userName'], $p['timestamp'], $p['value'], $p['kind'], implode('|', $p['categoryNames']), $p['paymentMethod'], $p['product'], $p['action'], isset($p['orderId']) ? $p['orderId'] : ''];
        }, $plan));
        $this->summarise($plan);
        $this->reportPossibleDuplicates($plan);

        $balances = $this->balances();
        $this->say(sprintf('Members: %d, in debt: %d (%.2f), with credit: %d (%.2f), settled: %d, without user account: %d',
            count($balances), count(array_filter($balances, function ($b) { return $b['balance'] < -0.005; })),
            array_sum(array_map(function ($b) { return $b['balance'] < -0.005 ? $b['balance'] : 0; }, $balances)),
            count(array_filter($balances, function ($b) { return $b['balance'] > 0.005; })),
            array_sum(array_map(function ($b) { return $b['balance'] > 0.005 ? $b['balance'] : 0; }, $balances)),
            count(array_filter($balances, function ($b) { return abs($b['balance']) <= 0.005; })),
            count(array_filter($balances, function ($b) { return !$b['userExists']; }))
        ));

        if ($this->dryRun) {
            $this->writeReconciliation($balances);
            $this->say('Dry run finished, nothing was written. Reports in ' . $this->reportDir);
            return 0;
        }

        $this->ensureAttributeKeys();
        $this->ensureCatalog();
        $this->applyRows($plan);
        $this->applyBalances($balances);
        $ok = $this->writeReconciliation($balances);
        $this->say($ok ? 'Reconciliation OK.' : 'RECONCILIATION FAILED — see reconciliation.csv');
        return $ok ? 0 : 1;
    }

    // ---------------------------------------------------------------- preparation

    protected function preflight()
    {
        foreach (['cmCreditRecord', 'cmCreditRecordCategory', 'CommunityStoreOrders', 'CommunityStoreOrderItems', 'CommunityStoreProducts'] as $table) {
            if (!$this->db->getSchemaManager()->tablesExist([$table])) {
                throw new \RuntimeException('Missing table ' . $table);
            }
        }
        foreach ([self::STATUS_ARCHIVE, self::STATUS_DEBT] as $handle) {
            if (!$this->db->fetchColumn('SELECT COUNT(*) FROM CommunityStoreOrderStatuses WHERE osHandle = ?', [$handle])) {
                throw new \RuntimeException('Missing Community Store order status ' . $handle);
            }
        }
        if (!class_exists('CommunityStoreCredit\Service\CreditService')) {
            throw new \RuntimeException('The community_store_credit package must be installed (it receives the surplus balances).');
        }
    }

    /**
     * Category names: config override first, then the live topic tree, else "node <id>".
     */
    protected function loadNodeNames()
    {
        $ids = $this->db->fetchAll('SELECT DISTINCT nodeId FROM cmCreditRecordCategory');
        $override = Config::get('credit_manager.migration.node_names');
        $override = is_array($override) ? $override : [];
        foreach ($ids as $row) {
            $id = (int) $row['nodeId'];
            if (isset($override[$id])) {
                $this->nodeNames[$id] = (string) $override[$id];
                continue;
            }
            $name = $this->db->fetchColumn('SELECT treeNodeName FROM TreeNodes WHERE treeNodeID = ?', [$id]);
            $this->nodeNames[$id] = $name ? (string) $name : 'node ' . $id;
        }
        foreach ($this->nodeNames as $id => $name) {
            $this->say(sprintf('  category node %d = %s', $id, $name));
        }
    }

    protected function loadUsers()
    {
        foreach ($this->db->fetchAll('SELECT uID, uName, uEmail FROM Users') as $row) {
            $this->users[(int) $row['uID']] = $row;
        }
    }

    protected function loadExistingRefs()
    {
        foreach ($this->db->fetchAll('SELECT oID, transactionReference FROM CommunityStoreOrders WHERE transactionReference LIKE ?', [self::REF_PREFIX . '%']) as $row) {
            $this->existingRefs[$row['transactionReference']] = (int) $row['oID'];
        }
        $this->say('Already migrated orders found: ' . count($this->existingRefs));
    }

    protected function loadRecords()
    {
        $sql = 'SELECT cr.Id, cr.uId, cr.comment, cr.timestamp, cr.value, GROUP_CONCAT(c.nodeId) AS nodeIds'
            . ' FROM cmCreditRecord cr LEFT JOIN cmCreditRecordCategory c ON c.crId = cr.Id GROUP BY cr.Id ORDER BY cr.Id';
        if ($this->limit > 0) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        return $this->db->fetchAll($sql);
    }

    // ---------------------------------------------------------------- classification

    protected function classify(array $row)
    {
        $value = round((float) $row['value'], 2);
        $names = [];
        if (!empty($row['nodeIds'])) {
            foreach (explode(',', $row['nodeIds']) as $nodeId) {
                $names[] = isset($this->nodeNames[(int) $nodeId]) ? $this->nodeNames[(int) $nodeId] : 'node ' . $nodeId;
            }
        }
        $comment = trim((string) $row['comment']);
        $uId = (int) $row['uId'];
        $plan = [
            'id' => (int) $row['Id'],
            'uId' => $uId,
            'userName' => isset($this->users[$uId]) ? $this->users[$uId]['uName'] : '',
            'userExists' => isset($this->users[$uId]),
            'timestamp' => $row['timestamp'],
            'value' => number_format($value, 2, '.', ''),
            'comment' => $comment,
            'categoryNames' => $names,
            'kind' => $value < 0 ? 'charge' : ($value > 0 ? 'topup' : 'zero'),
            'paymentMethod' => '',
            'product' => '',
            'action' => '',
        ];
        if ($plan['kind'] === 'zero') {
            $plan['action'] = 'skip: zero value';
            return $plan;
        }
        $ref = self::REF_PREFIX . $plan['id'];
        if (isset($this->existingRefs[$ref])) {
            $plan['action'] = 'exists';
            $plan['orderId'] = $this->existingRefs[$ref];
        } else {
            $plan['action'] = 'create';
        }
        if ($plan['kind'] === 'topup') {
            $plan['paymentMethod'] = $this->paymentMethodFor($names, $comment);
            $plan['product'] = self::PRODUCT_TOPUP;
        } else {
            $plan['paymentMethod'] = self::PAYMENT_ACCOUNT;
            $plan['product'] = $this->chargeProductFor($names, $comment);
        }
        return $plan;
    }

    protected function paymentMethodFor(array $names, $comment)
    {
        foreach ($names as $name) {
            foreach (self::PAYMENT_NAMES as $pm) {
                if (strcasecmp($name, $pm) === 0) {
                    return $pm;
                }
            }
        }
        $c = mb_strtolower($comment);
        if (strpos($c, 'payrexx') !== false) {
            return 'Payrexx';
        }
        if (strpos($c, 'paypal') !== false) {
            return 'Paypal';
        }
        if (preg_match('/\bbar\b|bareinnahme|bezahlt bar|cash/u', $c)) {
            return 'Bar';
        }
        if (preg_match('/überweisung|ueberweisung|einzahlung|bank|twint|iban/u', $c)) {
            return 'Überweisung';
        }
        return self::PAYMENT_ACCOUNT;
    }

    protected function chargeProductFor(array $names, $comment)
    {
        foreach ($names as $name) {
            $isPayment = false;
            foreach (self::PAYMENT_NAMES as $pm) {
                if (strcasecmp($name, $pm) === 0) {
                    $isPayment = true;
                }
            }
            if (!$isPayment && strpos($name, 'node ') !== 0) {
                return $name;
            }
        }
        $c = mb_strtolower($comment);
        if (strpos($c, 'produkte gekauft') !== false || strpos($c, 'self service') !== false) {
            return 'Self Service POS';
        }
        if (strpos($c, 'catering') === 0) {
            return 'Catering Order';
        }
        if (preg_match('/beitrag|eintritt|teilnehmer/u', $c)) {
            return 'Beiträge';
        }
        return self::PRODUCT_CHARGE_DEFAULT;
    }

    protected function summarise(array $plan)
    {
        $byAction = [];
        $byKind = [];
        foreach ($plan as $p) {
            $byAction[$p['action']] = (isset($byAction[$p['action']]) ? $byAction[$p['action']] : 0) + 1;
            $key = $p['kind'] . ' / ' . ($p['kind'] === 'topup' ? $p['paymentMethod'] : $p['product']);
            if (!isset($byKind[$key])) {
                $byKind[$key] = ['n' => 0, 'sum' => 0.0];
            }
            $byKind[$key]['n']++;
            $byKind[$key]['sum'] += (float) $p['value'];
        }
        $this->say('Rows: ' . count($plan));
        foreach ($byAction as $action => $n) {
            $this->say(sprintf('  %-22s %6d', $action, $n));
        }
        ksort($byKind);
        foreach ($byKind as $key => $v) {
            $this->say(sprintf('  %-45s %6d  %12.2f', $key, $v['n'], $v['sum']));
        }
        $noUser = array_filter($plan, function ($p) { return !$p['userExists'] && $p['kind'] !== 'zero'; });
        $this->say('Rows of users without account (archived on customer 0): ' . count($noUser));
    }

    /**
     * Store orders that are not from the migration but look like a ledger row (same customer, total and minute).
     */
    protected function reportPossibleDuplicates(array $plan)
    {
        $orders = $this->db->fetchAll('SELECT oID, cID, oDate, oTotal FROM CommunityStoreOrders WHERE transactionReference IS NULL OR transactionReference NOT LIKE ?', [self::REF_PREFIX . '%']);
        $index = [];
        foreach ($orders as $o) {
            $index[(int) $o['cID']][] = $o;
        }
        $rows = [];
        foreach ($plan as $p) {
            if ($p['kind'] !== 'charge' || !isset($index[$p['uId']])) {
                continue;
            }
            $ts = strtotime($p['timestamp']);
            foreach ($index[$p['uId']] as $o) {
                if (abs((float) $o['oTotal'] - abs((float) $p['value'])) < 0.005 && abs(strtotime($o['oDate']) - $ts) <= 120) {
                    $rows[] = [$p['id'], $p['uId'], $p['timestamp'], $p['value'], $o['oID'], $o['oDate'], $o['oTotal']];
                }
            }
        }
        $this->writeCsv('possible_duplicates.csv', ['recordId', 'uId', 'timestamp', 'value', 'orderId', 'orderDate', 'orderTotal'], $rows);
        $this->say('Existing store orders that look like a ledger row: ' . count($rows) . ' (possible_duplicates.csv)');
    }

    protected function balances()
    {
        $sql = 'SELECT uId, ROUND(SUM(value), 2) AS balance, COUNT(*) AS n FROM cmCreditRecord GROUP BY uId ORDER BY uId';
        $result = [];
        foreach ($this->db->fetchAll($sql) as $row) {
            $uId = (int) $row['uId'];
            $result[$uId] = [
                'uId' => $uId,
                'balance' => round((float) $row['balance'], 2),
                'records' => (int) $row['n'],
                'userExists' => isset($this->users[$uId]),
                'userName' => isset($this->users[$uId]) ? $this->users[$uId]['uName'] : '',
            ];
        }
        return $result;
    }

    // ---------------------------------------------------------------- apply

    protected function ensureAttributeKeys()
    {
        $category = $this->app->make(CategoryService::class)->getByHandle('store_order');
        if (!$category) {
            throw new \RuntimeException('Attribute category store_order not found.');
        }
        $controller = $category->getController();
        $pkg = $this->app->make('Concrete\Core\Package\PackageService')->getByHandle('credit_manager');
        $pkgEntity = $pkg ? $pkg->getPackageEntity() : null;
        foreach (self::ATTRIBUTE_KEYS as $handle => $def) {
            // the cached lookup would remember a miss for the rest of the run, so ask the database directly
            if ($controller->getAttributeKeyByHandleUncached($handle)) {
                continue;
            }
            $key = $controller->add($def[0], ['akHandle' => $handle, 'akName' => $def[1]], null, $pkgEntity);
            $key->setIsAttributeKeySearchable(true);
            $this->em->persist($key);
            $this->em->flush();
            $this->say('  created order attribute ' . $handle);
        }
        $this->app->make('cache/request')->flush();
    }

    protected function ensureCatalog()
    {
        $this->group = StoreGroup::getByName(self::GROUP_NAME);
        if (!$this->group) {
            $this->group = StoreGroup::add(self::GROUP_NAME);
            $this->say('  created product group ' . self::GROUP_NAME);
        }
    }

    protected function productFor($name)
    {
        if (isset($this->products[$name])) {
            return $this->products[$name];
        }
        $sku = 'CM-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
        $product = $this->em->getRepository(Product::class)->findOneBy(['pSKU' => $sku]);
        if (!$product) {
            $product = new Product();
            $product->setName($name);
            $product->setSKU($sku);
            $product->setDescription(t('Archived credit_manager bookings'));
            $product->setDetail('');
            $product->setPrice(0);
            $product->setIsActive(false);
            $product->setIsUnlimited(true);
            $product->setQty(0);
            $product->setNoQty(true);
            $product->setIsTaxable(false);
            $product->setIsShippable(false);
            $product->setIsFeatured(false);
            $product->setDateAdded(new \DateTime());
            $product->save();
            ProductGroup::add($product, $this->group->getGroupID());
            $this->say('  created product ' . $name . ' (' . $sku . ')');
        }
        $this->products[$name] = $product;
        return $product;
    }

    protected function applyRows(array $plan)
    {
        $byUser = [];
        foreach ($plan as $p) {
            if ($p['action'] === 'create') {
                $byUser[$p['uId']][] = $p;
            }
        }
        $done = 0;
        $total = array_sum(array_map('count', $byUser));
        foreach ($byUser as $uId => $rows) {
            $this->em->transactional(function () use ($rows) {
                foreach ($rows as $p) {
                    $this->createArchiveOrder($p);
                }
            });
            $done += count($rows);
            $this->resetEntityManager();
            $this->say(sprintf('  %s  %d / %d orders written (user %d)', date('H:i:s'), $done, $total, $uId));
        }
    }

    protected function createArchiveOrder(array $p)
    {
        $user = $p['userExists'] ? $this->users[$p['uId']] : null;
        $amount = abs((float) $p['value']);
        $date = new \DateTime($p['timestamp']);
        $product = $this->productFor($p['product']);
        $itemName = $p['comment'] !== '' ? mb_substr($p['comment'], 0, 250) : $p['product'];

        $order = new Order();
        $order->setCustomerID($user ? $p['uId'] : 0);
        $order->setDate($date);
        $order->setPaymentMethodName($p['paymentMethod']);
        $order->setShippingMethodName('');
        $order->setShippingTotal(0);
        $order->setTaxTotal(0);
        $order->setTaxIncluded(0);
        $order->setTotal($amount);
        $order->setTransactionReference(self::REF_PREFIX . $p['id']);
        $order->setPaid($date);
        $order->setLocale(Localization::activeLocale());
        $notes = $p['comment'] . "\n" . sprintf('[credit_manager #%d, %s, Kategorien: %s]', $p['id'], $p['kind'], implode(', ', $p['categoryNames']) ?: '-');
        if (!$user) {
            $notes .= "\n" . sprintf('[Benutzer #%d existiert nicht mehr]', $p['uId']);
        }
        $order->setNotes($notes);
        $order->save();
        $order->updateStatus(self::STATUS_ARCHIVE, 'credit_manager Archiv');

        $item = new OrderItem();
        $item->setOrder($order);
        $item->setProductID($product->getID());
        $item->setProductName($itemName);
        $item->setSKU($product->getSKU());
        $item->setPricePaid($amount);
        $item->setTax(0);
        $item->setTaxIncluded(0);
        $item->setTaxName('');
        $item->setQuantity(1);
        $item->setQuantityLabel('');
        $this->em->persist($item);
        $this->em->flush();

        $order->setAttribute('cm_record_id', $p['id']);
        $order->setAttribute('cm_source', 'credit_manager');
        $order->setAttribute('cm_kind', $p['kind']);
        $order->setAttribute('cm_archive', true);
        $order->setAttribute('cm_user', $user ? $user['uName'] : ('#' . $p['uId'] . ' (deleted)'));
        $this->existingRefs[self::REF_PREFIX . $p['id']] = $order->getOrderID();
    }

    protected function applyBalances(array $balances)
    {
        $credit = $this->app->make('CommunityStoreCredit\Service\CreditService');
        foreach ($balances as $b) {
            if (!$b['userExists'] || abs($b['balance']) <= 0.005) {
                continue;
            }
            if ($b['balance'] < 0) {
                $ref = self::REF_PREFIX . 'debt:' . $b['uId'];
                if (isset($this->existingRefs[$ref])) {
                    continue;
                }
                $this->em->transactional(function () use ($b, $ref) {
                    $amount = abs($b['balance']);
                    $product = $this->productFor(self::PRODUCT_DEBT);
                    $order = new Order();
                    $order->setCustomerID($b['uId']);
                    $order->setDate($this->cutover);
                    $order->setPaymentMethodName(self::PAYMENT_ACCOUNT);
                    $order->setShippingMethodName('');
                    $order->setShippingTotal(0);
                    $order->setTaxTotal(0);
                    $order->setTaxIncluded(0);
                    $order->setTotal($amount);
                    $order->setTransactionReference($ref);
                    $order->setLocale(Localization::activeLocale());
                    $order->setNotes(sprintf('Offener Saldo des Vereinskontos per %s (credit_manager, %d Buchungen)', $this->cutover->format('d.m.Y'), $b['records']));
                    $order->save();
                    $order->updateStatus(self::STATUS_DEBT, 'credit_manager Saldo');
                    $item = new OrderItem();
                    $item->setOrder($order);
                    $item->setProductID($product->getID());
                    $item->setProductName(self::PRODUCT_DEBT . ' per ' . $this->cutover->format('d.m.Y'));
                    $item->setSKU($product->getSKU());
                    $item->setPricePaid($amount);
                    $item->setTax(0);
                    $item->setTaxIncluded(0);
                    $item->setTaxName('');
                    $item->setQuantity(1);
                    $item->setQuantityLabel('');
                    $this->em->persist($item);
                    $this->em->flush();
                    $order->setAttribute('cm_source', 'credit_manager');
                    $order->setAttribute('cm_kind', 'debt');
                    $order->setAttribute('cm_user', $b['userName']);
                    $this->existingRefs[$ref] = $order->getOrderID();
                });
                $this->resetEntityManager();
            } else {
                $credit->add($b['uId'], $b['balance'], sprintf('Übernahme Guthaben Vereinskonto per %s', $this->cutover->format('d.m.Y')), 'migration', 'cm_balance:' . $b['uId']);
            }
        }
        $this->say('Balances applied.');
    }

    /**
     * Frees the entity manager between members. Everything cached per request (attribute keys, products,
     * the group) is dropped too, because those objects are detached by clear() and Doctrine would otherwise
     * treat them as new entities on the next order.
     */
    protected function resetEntityManager()
    {
        $this->em->clear();
        $this->app->make('cache/request')->flush();
        $this->products = [];
        $this->group = StoreGroup::getByName(self::GROUP_NAME);
    }

    // ---------------------------------------------------------------- reconciliation

    /**
     * Per member: ledger sums vs. migrated orders, debt order, store credit. In a dry run only the ledger side
     * and the expected targets are listed.
     *
     * @return bool all members OK
     */
    protected function writeReconciliation(array $balances)
    {
        $migrated = [];
        foreach ($this->db->fetchAll(
            'SELECT cr.uId, SUM(CASE WHEN cr.value < 0 THEN o.oTotal ELSE 0 END) AS charges, SUM(CASE WHEN cr.value > 0 THEN o.oTotal ELSE 0 END) AS topups,'
            . ' SUM(cr.value <> 0) AS expected, COUNT(o.oID) AS orders'
            . ' FROM cmCreditRecord cr LEFT JOIN CommunityStoreOrders o ON o.transactionReference = CONCAT(?, cr.Id) GROUP BY cr.uId', [self::REF_PREFIX]
        ) as $row) {
            $migrated[(int) $row['uId']] = $row;
        }
        $debts = [];
        foreach ($this->db->fetchAll('SELECT transactionReference, oTotal, oPaid FROM CommunityStoreOrders WHERE transactionReference LIKE ?', [self::REF_PREFIX . 'debt:%']) as $row) {
            $debts[(int) substr($row['transactionReference'], strlen(self::REF_PREFIX . 'debt:'))] = $row;
        }
        $credits = [];
        if ($this->db->getSchemaManager()->tablesExist(['csCreditEntries'])) {
            foreach ($this->db->fetchAll('SELECT uID, amount FROM csCreditEntries WHERE source = ? AND externalRef LIKE ?', ['migration', 'cm_balance:%']) as $row) {
                $credits[(int) $row['uID']] = (float) $row['amount'];
            }
        }
        $rows = [];
        $allOk = true;
        foreach ($balances as $b) {
            $uId = $b['uId'];
            $m = isset($migrated[$uId]) ? $migrated[$uId] : ['charges' => 0, 'topups' => 0, 'expected' => 0, 'orders' => 0];
            $ledgerCharges = round((float) $this->db->fetchColumn('SELECT COALESCE(SUM(-value), 0) FROM cmCreditRecord WHERE uId = ? AND value < 0', [$uId]), 2);
            $ledgerTopups = round((float) $this->db->fetchColumn('SELECT COALESCE(SUM(value), 0) FROM cmCreditRecord WHERE uId = ? AND value > 0', [$uId]), 2);
            $debt = isset($debts[$uId]) ? (float) $debts[$uId]['oTotal'] : 0.0;
            $credit = isset($credits[$uId]) ? $credits[$uId] : 0.0;
            $expectedDebt = $b['userExists'] && $b['balance'] < -0.005 ? -$b['balance'] : 0.0;
            $expectedCredit = $b['userExists'] && $b['balance'] > 0.005 ? $b['balance'] : 0.0;
            if ($this->dryRun) {
                $status = 'planned';
            } else {
                $ok = (int) $m['orders'] === (int) $m['expected']
                    && abs((float) $m['charges'] - $ledgerCharges) < 0.005
                    && abs((float) $m['topups'] - $ledgerTopups) < 0.005
                    && abs($debt - $expectedDebt) < 0.005
                    && abs($credit - $expectedCredit) < 0.005
                    && (!isset($debts[$uId]) || $debts[$uId]['oPaid'] === null);
                $status = $ok ? 'OK' : 'DIFF';
                $allOk = $allOk && $ok;
            }
            $rows[] = [$uId, $b['userName'], $b['userExists'] ? 'yes' : 'no', $b['records'], number_format($ledgerCharges, 2, '.', ''), number_format($ledgerTopups, 2, '.', ''), number_format($b['balance'], 2, '.', ''),
                (int) $m['orders'], number_format((float) $m['charges'], 2, '.', ''), number_format((float) $m['topups'], 2, '.', ''),
                number_format($expectedDebt, 2, '.', ''), number_format($debt, 2, '.', ''), number_format($expectedCredit, 2, '.', ''), number_format($credit, 2, '.', ''), $status];
        }
        $this->writeCsv('reconciliation.csv', ['uId', 'user', 'userExists', 'ledgerRecords', 'ledgerCharges', 'ledgerTopups', 'ledgerBalance', 'migratedOrders', 'migratedCharges', 'migratedTopups', 'expectedDebt', 'debtOrder', 'expectedCredit', 'storeCredit', 'status'], $rows);
        return $allOk;
    }

    // ---------------------------------------------------------------- helpers

    protected function writeCsv($name, array $header, array $rows)
    {
        $path = $this->reportDir . '/' . $name;
        $fh = @fopen($path, 'w');
        if (!$fh) {
            throw new \RuntimeException('Cannot write report ' . $path . ' (check --report-dir permissions).');
        }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ';');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';');
        }
        fclose($fh);
    }

    protected function say($line)
    {
        call_user_func($this->log, $line);
    }
}
