<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Concrete\Core\Http\Response;
use Concrete\Core\Page\Controller\PageController;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\User\User;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use CreditManager\Controller\DashboardDialog;
use CreditManager\CreditManager;
use CreditManager\Service\EventContext;
use Core;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Catering order desk: moves Community Store orders of the event from open to ordered to delivered; delivering
 * charges the customer's account. Only users with Credit Manager dashboard access may use it.
 */
class OrderManagement extends PageController
{
    const TOKEN = 'order_management';

    public function canAccess()
    {
        return DashboardDialog::userCanManage();
    }

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
        $this->requireAsset('javascript', 'vue');
        $this->requireAsset('javascript', 'slimScroll');
        $this->set('ccm_token', json_encode(Core::make('token')->generate(self::TOKEN)));
        $this->set('orderGetAction', $this->action('getOrders'));
        $this->set('orderSetOrderedAction', $this->action('setOrdered'));
        $this->set('orderSetDeliveredAction', $this->action('setDelivered'));
        $this->set('orderSetClosedAction', $this->action('setClosed'));
        $this->setThemeViewTemplate((string) (Config::get('credit_manager.fullscreen_template') ?: 'blank.php'));
    }

    public function getOrders()
    {
        if (!$this->validateActionRequest()) {
            return new Response('Invalid Request Token.', 401);
        }

        $orderList = new OrderList();
        $orderList->setCancelled(false);

        $statusMap = [
            'incomplete' => 'Offen',
            'processing' => 'Bestellt',
            'delivered' => 'Ausgeliefert',
        ];
        $eventPageId = EventContext::getEventPageId();

        $orders = [];
        foreach ($orderList->getResults() as $oo) {
            if (!is_object($oo) || $oo->getAttribute('standing')) {
                continue;
            }
            if ($eventPageId) {
                $eventId = CreditManager::attributeValue($oo->getAttribute('event_id'));
                if ($eventId !== null && $eventId !== '' && (string) $eventId !== (string) $eventPageId) {
                    continue;
                }
            }
            $statusHandle = $oo->getStatusHandle();
            if ($oo->getPaid() || $statusHandle === 'delivered') {
                continue;
            }

            $productIds = [];
            foreach ($oo->getOrderItems() as $item) {
                $productIds[] = $item->getProductID();
            }
            $userName = '';
            $cID = $oo->getCustomerID();
            if ($cID) {
                $user = User::getByUserID($cID);
                if ($user) {
                    $userName = $user->getUserName();
                }
            }
            $orders[] = [
                'id' => $oo->getOrderID(),
                'product_name' => $this->describeItems($oo, t('Kein Produkt')),
                'product_id' => !empty($productIds) ? $productIds[0] : 0,
                'value' => (float) $oo->getTotal(),
                'user_name' => $userName,
                'status' => $statusMap[$statusHandle] ?? ($oo->getStatus() ?: ucfirst((string) $statusHandle)),
                'status_handle' => $statusHandle,
            ];
        }

        usort($orders, function ($a, $b) {
            return strcmp($a['product_name'], $b['product_name']);
        });

        return new JsonResponse($orders);
    }

    public function setOrdered()
    {
        if (!$this->validateActionRequest()) {
            return new Response('Invalid Request Token.', 401);
        }
        $selected = $this->post('selected_orders');
        if (empty($selected) || !is_array($selected)) {
            return new Response('0 Bestellungen auf "Bestellt" gesetzt');
        }
        $orderedStatus = OrderStatus::getByHandle('processing');
        $orderedStatusHandle = $orderedStatus ? $orderedStatus->getHandle() : 'processing';
        $n = 0;
        foreach ($selected as $soId) {
            $order = Order::getByID((int) $soId);
            if ($order && $order->getStatusHandle() === 'incomplete') {
                $order->updateStatus($orderedStatusHandle);
                $n++;
            }
        }
        return new Response($n . ' Bestellungen auf "Bestellt" gesetzt');
    }

    /**
     * Delivers the selected orders and charges them. Each order is handled in its own transaction with the order
     * row locked, and the charge carries the order id as reference, so an order can never be charged twice, not
     * even by two clerks clicking at the same moment.
     */
    public function setDelivered()
    {
        if (!$this->validateActionRequest()) {
            return new Response('Invalid Request Token.', 401);
        }
        $selected = $this->post('selected_orders');
        if (empty($selected) || !is_array($selected)) {
            return new Response('0 Bestellungen auf "Ausgeliefert" gesetzt und verrechnet.');
        }
        $deliveredStatus = OrderStatus::getByHandle('delivered');
        $deliveredStatusHandle = $deliveredStatus ? $deliveredStatus->getHandle() : 'delivered';
        $lanTitle = EventContext::getEventTitle();
        $db = Database::connection();
        $em = $db->getEntityManager();

        $n = 0;
        $failed = [];
        foreach ($selected as $soId) {
            $oID = (int) $soId;
            if ($oID <= 0) {
                continue;
            }
            try {
                // the outcome travels by reference: Doctrine's transactional() turns a false return value into true
                $delivered = false;
                $em->transactional(function () use ($db, $em, $oID, $deliveredStatusHandle, $lanTitle, &$delivered) {
                    // lock the order row for the duration of the transaction
                    $db->executeQuery('SELECT oID FROM CommunityStoreOrders WHERE oID = ? FOR UPDATE', [$oID]);
                    $order = Order::getByID($oID);
                    if (!$order) {
                        return;
                    }
                    $em->refresh($order);
                    if ($order->getStatusHandle() !== 'processing') {
                        return;
                    }
                    $cID = $order->getCustomerID();
                    $user = $cID ? User::getByUserID($cID) : null;
                    if (!$user) {
                        return;
                    }
                    $msg = 'Catering Auslieferung für ' . $this->describeItems($order, t('Produkt')) . ' abgeschlossen';
                    CreditManager::addRecord(
                        $user,
                        -(float) $order->getTotal(),
                        $msg,
                        ['Catering Order', $lanTitle],
                        CreditManager::SOURCE_STORE_ORDER,
                        $oID . ':delivered'
                    );
                    $order->setShippingMethodName('Restaurant Sammellieferung');
                    $order->updateStatus($deliveredStatusHandle);
                    $order->save();
                    $delivered = true;
                });
                if ($delivered) {
                    $n++;
                }
            } catch (\Throwable $e) {
                $failed[] = $oID;
                $this->app->make('log')->error('Credit Manager: delivering order ' . $oID . ' failed: ' . $e->getMessage());
                if (!$em->isOpen()) {
                    // a failed transaction closes the entity manager; stop instead of working with a dead one
                    break;
                }
            }
        }

        $message = $n . ' Bestellungen auf "Ausgeliefert" gesetzt und verrechnet.';
        if ($failed) {
            return new Response($message . ' Fehlgeschlagen: ' . implode(', ', $failed), 500);
        }
        return new Response($message);
    }

    public function setClosed()
    {
        return $this->setDelivered();
    }

    protected function validateActionRequest()
    {
        if (!$this->canAccess()) {
            return false;
        }
        return (bool) Core::make('token')->validate(self::TOKEN);
    }

    private function describeItems($order, $fallback)
    {
        $names = [];
        foreach ($order->getOrderItems() as $item) {
            $qty = (int) $item->getQuantity();
            $name = $item->getProductName();
            $names[] = $qty > 1 ? ($qty . 'x ' . $name) : $name;
        }
        return !empty($names) ? implode(', ', $names) : $fallback;
    }
}
