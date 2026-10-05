<?php
namespace CreditManager\PageControllers;

use Concrete\Core\Http\Response;
use Concrete\Core\Page\Controller\PageController;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\User\UserList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductType\ProductType;
use CreditManager\Controller\DashboardDialog;
use CreditManager\CreditManager;
use CreditManager\Service\EventContext;
use Core;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared controller of the POS pages. A checkout books one credit record (idempotent per cart) and appends the
 * items to the user's standing Community Store order of the event, all in one database transaction.
 */
abstract class PosPageController extends PageController
{
    const TOKEN = 'pos_order';
    const MAX_QUANTITY = 100;

    /**
     * The category every checkout of this page is tagged with.
     *
     * @return string
     */
    abstract public function getCmCategory();

    /**
     * Staff pages may list the participants with their badge ids (to pick a customer by name); the self-service
     * kiosk must not hand out other people's badge ids.
     *
     * @return bool
     */
    protected function exposesBadgeIds()
    {
        return true;
    }

    /**
     * Whoever may open the Credit Manager dashboard may run the POS. Pages that override this to true rely on
     * the page permissions alone.
     *
     * @return bool
     */
    public function canAccess()
    {
        return DashboardDialog::userCanManage();
    }

    /**
     * Guests go to the login, other users without access get the forbidden page.
     */
    public function validateRequest()
    {
        $response = parent::validateRequest();
        if ($response !== true) {
            return $response;
        }
        if ($this->canAccess()) {
            return true;
        }
        $user = $this->app->make(User::class);
        if (!$user->isRegistered()) {
            return $this->buildRedirect((string) $this->app->make('url/manager')->resolve(['/login']) . '?rcID=' . (int) $this->getPageObject()->getCollectionID());
        }
        $this->replace('/page_forbidden');
        return true;
    }

    public function view()
    {
        $users = [];
        $group = EventContext::getParticipantGroup();
        if ($group) {
            $ul = new UserList();
            $ul->filterByGroup($group);
            $ul->sortBy('uName');
            foreach ($ul->getResults() as $ui) {
                $users[] = $this->userData($ui, $this->exposesBadgeIds());
            }
        }
        $this->set('users', json_encode($users));
        $this->set('products', json_encode($this->getVisibleProducts()));
        $this->set('eventTitle', EventContext::getEventTitle());

        $this->requireAsset('javascript', 'vue');
        $this->requireAsset('javascript', 'slimScroll');

        $this->set('ccm_token', json_encode(Core::make('token')->generate(self::TOKEN)));
        $this->set('orderAction', $this->action('processOrder'));
        $this->set('lookupAction', $this->action('lookupUser'));

        $this->setThemeViewTemplate((string) (Config::get('credit_manager.fullscreen_template') ?: 'blank.php'));
    }

    /**
     * Community Store products of the configured POS product type.
     */
    public function getVisibleProducts()
    {
        $productList = new ProductList();
        $typeId = (int) Config::get('credit_manager.pos_product_type_id');
        if ($typeId > 0) {
            $type = ProductType::getByID($typeId);
            if ($type) {
                $productList->setProductType($type);
            }
        }
        $products = [];
        foreach ($productList->getResults() as $p) {
            $products[] = [
                'id' => (int) $p->getID(),
                'name' => $p->getName(),
                'price' => (float) $p->getPrice(),
                'image' => $p->getImageObj(),
            ];
        }
        return $products;
    }

    /**
     * POST badge_id → the matching participant (name, avatar) without revealing anybody else's badge.
     */
    public function lookupUser()
    {
        if (!$this->validateActionRequest()) {
            return new Response('Invalid Request Token.', 401);
        }
        $badgeId = trim((string) $this->post('badge_id'));
        if ($badgeId === '') {
            return new Response('No Badge Id transmitted', 400);
        }
        $ui = $this->findUserByBadge($badgeId);
        if (!$ui) {
            return new Response('Kein Benutzer mit dieser Badge Id', 404);
        }
        return new JsonResponse($this->userData($ui, true, $badgeId));
    }

    public function processOrder()
    {
        if (!$this->validateActionRequest()) {
            return new Response('Invalid Request Token.', 401);
        }
        $order = $this->post('order');
        if (!is_array($order)) {
            return new Response('No order transmitted', 400);
        }
        $badgeId = isset($order['badge_id']) ? trim((string) $order['badge_id']) : '';
        if ($badgeId === '') {
            return new Response('No Badge Id transmitted', 400);
        }
        $ui = $this->findUserByBadge($badgeId);
        if (!$ui) {
            return new Response('No User associated to this Badge ID: ' . $badgeId, 404);
        }
        $cartId = isset($order['uuid']) ? preg_replace('/[^A-Za-z0-9\-_.]/', '', (string) $order['uuid']) : '';
        if ($cartId === '' || strlen($cartId) > 100) {
            return new Response('No cart id transmitted', 400);
        }
        $externalRef = $this->getCmCategory() . ':' . $cartId;

        $already = CreditManager::findByReference(CreditManager::SOURCE_POS, $externalRef);
        if ($already) {
            return new Response('Transaktion bereits verbucht');
        }

        $lines = $this->normalizeItems(isset($order['items']) ? $order['items'] : []);
        if (empty($lines)) {
            return new Response('No Items selected', 400);
        }

        $totalPrice = 0.0;
        $itemCount = 0;
        $itemNames = [];
        foreach ($lines as $line) {
            $totalPrice += $line['quantity'] * (float) $line['product']->getPrice();
            $itemCount += $line['quantity'];
            $itemNames[] = ($line['quantity'] > 1 ? $line['quantity'] . 'x ' : '') . $line['product']->getName();
        }
        $totalPrice = round($totalPrice, 2);

        $limit = Config::get('credit_manager.negative_balance_limit');
        if ($limit !== null && $limit !== '' && is_numeric($limit)) {
            $balanceAfter = CreditManager::getUserBalance($ui) - $totalPrice;
            if ($balanceAfter < -abs((float) $limit)) {
                return new Response('Guthaben reicht nicht aus (Limit ' . number_format(abs((float) $limit), 2) . ')', 409);
            }
        }

        $message = $itemCount . ' Produkte gekauft: (' . implode(', ', $itemNames) . ')';
        $categories = [$this->getCmCategory(), EventContext::getEventTitle()];
        $eventPageId = EventContext::getEventPageId();
        $em = Database::connection()->getEntityManager();
        try {
            $em->transactional(function () use ($em, $ui, $totalPrice, $message, $categories, $externalRef, $lines, $eventPageId) {
                CreditManager::addRecord($ui, -$totalPrice, $message, $categories, CreditManager::SOURCE_POS, $externalRef);
                if (!$eventPageId) {
                    return;
                }
                $standingOrder = CreditManager::getCommunityStoreStandingOrder($ui, $eventPageId);
                if (!$standingOrder) {
                    $standingOrder = CreditManager::createCommunityStoreStandingOrder($ui, $eventPageId);
                }
                foreach ($lines as $line) {
                    OrderItem::add([
                        'product' => [
                            'object' => $line['product'],
                            'qty' => $line['quantity'],
                            'pID' => $line['product']->getID(),
                        ],
                        'productAttributes' => [],
                    ], $standingOrder->getOrderID());
                }
                $standingOrder->setTotal(round((float) $standingOrder->getTotal() + $totalPrice, 2));
                if ($standingOrder->getStatusHandle() != 'delivered') {
                    $standingOrder->updateStatus('delivered', 'Self-Checkout Purchase');
                }
                $standingOrder->save();
            });
        } catch (\Throwable $e) {
            $this->app->make('log')->error('Credit Manager: POS checkout ' . $externalRef . ' failed: ' . $e->getMessage());
            return new Response('Failed: ' . $e->getMessage(), 500);
        }

        return new Response('Transaktion Erfolgreich');
    }

    /**
     * Token check plus, for staff pages, the permission check (page permissions alone do not cover POSTs).
     */
    protected function validateActionRequest()
    {
        if (!$this->canAccess()) {
            return false;
        }
        return (bool) Core::make('token')->validate(self::TOKEN);
    }

    /**
     * @param array $items [['id' => productId, 'quantity' => n], ...]
     * @return array [['product' => Product, 'quantity' => int], ...] merged per product
     */
    protected function normalizeItems($items)
    {
        $lines = [];
        foreach ((array) $items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                continue;
            }
            $product = Product::getByID((int) $item['id']);
            if (!$product) {
                continue;
            }
            $pID = (int) $product->getID();
            if (isset($lines[$pID])) {
                $lines[$pID]['quantity'] += $quantity;
            } else {
                $lines[$pID] = ['product' => $product, 'quantity' => $quantity];
            }
        }
        return array_values($lines);
    }

    /**
     * Exact badge match first; a single partial match is accepted because some readers cut the id short.
     * Reads the attribute values directly, so it does not depend on the user search index.
     *
     * @return UserInfo|null
     */
    protected function findUserByBadge($badgeId)
    {
        $db = Database::connection();
        $sql = 'SELECT uav.uID FROM UserAttributeValues uav'
            . ' INNER JOIN AttributeKeys ak ON ak.akID = uav.akID'
            . ' INNER JOIN atDefault d ON d.avID = uav.avID'
            . ' INNER JOIN Users u ON u.uID = uav.uID'
            . ' WHERE ak.akHandle = ? AND u.uIsActive = 1 AND d.value ';
        $ids = $db->fetchAll($sql . '= ?', ['badge_id', $badgeId]);
        if (count($ids) === 0 && strlen($badgeId) >= 4) {
            $ids = $db->fetchAll($sql . 'LIKE ?', ['badge_id', '%' . addcslashes($badgeId, '%_\\') . '%']);
        }
        if (count($ids) !== 1) {
            return null;
        }
        $ui = $this->app->make(UserInfoRepository::class)->getByID((int) $ids[0]['uID']);
        return $ui instanceof UserInfo ? $ui : null;
    }

    protected function userData(UserInfo $ui, $withBadge, $badgeOverride = null)
    {
        $data = [
            'id' => (int) $ui->getUserID(),
            'name' => $ui->getUserName(),
            'avatar' => $ui->getUserAvatar() ? $ui->getUserAvatar()->getPath() : '',
            'badge_id' => '',
        ];
        if ($withBadge) {
            $data['badge_id'] = $badgeOverride !== null ? (string) $badgeOverride : (string) $ui->getAttribute('badge_id');
        }
        return $data;
    }
}
