<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use Application\Turicane\CurrentLan;
use Concrete\Core\Http\Response;
use Concrete\Core\Page\Controller\PageController;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\User\User;
use Concrete\Core\User\UserList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use CreditManager\CreditManager;
use Core;
use Symfony\Component\HttpFoundation\JsonResponse;

class OrderManagement extends PageController
{
    public function view()
    {
        $this->requireAsset('javascript', 'vue');
        $this->requireAsset('javascript', 'slimScroll');
        $this->set('ccm_token', json_encode(Core::make('token')->generate('order_management')));
        $this->set('orderGetAction', $this->action('getOrders'));
        $this->set('orderSetOrderedAction', $this->action('setOrdered'));
        $this->set('orderSetDeliveredAction', $this->action('setDelivered'));
        $this->set('orderSetClosedAction', $this->action('setClosed'));
        $this->setThemeViewTemplate('blank.php');
    }

    public function getOrders()
    {
        $token = \Core::make("token");
        if (!$token->validate('order_management')) {
            return new Response('Invalid Request Token.', 401);
        }

        $orderList = new OrderList();
        $orderList->setCancelled(false);
        $orderObjects = $orderList->getResults();

        $statusMap = [
            'incomplete' => 'Offen',
            'processing' => 'Bestellt',
            'delivered' => 'Ausgeliefert'
        ];

        $orders = [];
        foreach ($orderObjects as $oo) {
            if (!is_object($oo)) {
                continue;
            }

            $standing = $oo->getAttribute('standing');
            if ($standing) {
                continue;
            }

            if (class_exists(CurrentLan::class) && !empty(CurrentLan::$lanPageId)) {
                $eventId = $oo->getAttribute('event_id');
                if (is_object($eventId) && method_exists($eventId, 'getCollectionID')) {
                    $eventId = $eventId->getCollectionID();
                } elseif (is_object($eventId) && method_exists($eventId, 'getValue')) {
                    $eventId = $eventId->getValue();
                }

                if ($eventId !== null && $eventId !== '' && (string)$eventId !== (string)CurrentLan::$lanPageId) {
                    continue;
                }
            }

            $statusHandle = $oo->getStatusHandle();
            if ($oo->getPaid() || in_array($statusHandle, ['delivered'])) {
                continue;
            }

            $productNames = [];
            $productIds = [];
            foreach ($oo->getOrderItems() as $item) {
                $qty = (int)$item->getQuantity();
                $name = $item->getProductName();
                $productNames[] = $qty > 1 ? ($qty . 'x ' . $name) : $name;
                $productIds[] = $item->getProductID();
            }
            $productName = !empty($productNames) ? implode(', ', $productNames) : t('Kein Produkt');
            $productId = !empty($productIds) ? $productIds[0] : 0;

            $cID = $oo->getCustomerID();
            $userName = '';
            if ($cID) {
                $user = User::getByUserID($cID);
                if ($user) {
                    $userName = $user->getUserName();
                }
            }

            $statusDisplay = $statusMap[$statusHandle] ?? ($oo->getStatus() ?: ucfirst($statusHandle));

            $orders[] = [
                'id' => $oo->getOrderID(),
                'product_name' => $productName,
                'product_id' => $productId,
                'value' => (float)$oo->getTotal(),
                'user_name' => $userName,
                'status' => $statusDisplay,
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
        $selected_orders = $this->post('selected_orders');
        $token = \Core::make("token");
        if (!$token->validate('order_management')) {
            return new Response('Invalid Request Token.', 401);
        }

        if (empty($selected_orders) || !is_array($selected_orders)) {
            return new Response('0 Bestellungen auf "Bestellt" gesetzt');
        }

        $orderedStatus = OrderStatus::getByHandle('processing');
        $orderedStatusHandle = $orderedStatus ? $orderedStatus->getHandle() : 'processing';
        $n = 0;
        foreach ($selected_orders as $soId) {
            $order = Order::getByID($soId);
            if ($order) {
                $currentStatus = $order->getStatusHandle();
                if (in_array($currentStatus, ['incomplete'])) {
                    $order->updateStatus($orderedStatusHandle);
                    $n++;
                }
            }
        }

        return new Response($n . ' Bestellungen auf "Bestellt" gesetzt');
    }

    public function setDelivered()
    {
        $selected_orders = $this->post('selected_orders');
        $token = \Core::make("token");
        if (!$token->validate('order_management')) {
            return new Response('Invalid Request Token.', 401);
        }

        if (empty($selected_orders) || !is_array($selected_orders)) {
            return new Response('0 Bestellungen auf "Ausgeliefert" gesetzt und verrechnet.');
        }

        $deliveredStatus = OrderStatus::getByHandle('delivered');
        $deliveredStatusHandle = $deliveredStatus ? $deliveredStatus->getHandle() : 'delivered';
        $n = 0;
        foreach ($selected_orders as $soId) {
            $order = Order::getByID($soId);
            if ($order) {
                $currentStatus = $order->getStatusHandle();
                if (in_array($currentStatus, ['processing'])) {
                    $cID = $order->getCustomerID();
                    $user = $cID ? User::getByUserID($cID) : null;

                    $productNames = [];
                    foreach ($order->getOrderItems() as $item) {
                        $qty = (int)$item->getQuantity();
                        $name = $item->getProductName();
                        $productNames[] = $qty > 1 ? ($qty . 'x ' . $name) : $name;
                    }
                    $productName = !empty($productNames) ? implode(', ', $productNames) : t('Produkt');

                    if ($user) {
                        $msg = 'Catering Auslieferung für ' . $productName . ' abgeschlossen';
                        $lanTitle = class_exists(CurrentLan::class) ? CurrentLan::getLANTitle() : '';
                        CreditManager::addRecord($user, -(float)$order->getTotal(), $msg, ['Catering Order', $lanTitle]);
                        $order->setShippingMethodName('Restaurant Sammellieferung');
                        $order->updateStatus($deliveredStatusHandle);
                        $order->save();
                        $n++;
                    }
                }
            }
        }

        return new Response($n . ' Bestellungen auf "Ausgeliefert" gesetzt und verrechnet.');
    }

    public function setClosed()
    {
        return $this->setDelivered();
    }

    public function processOrder()
    {
        $order = $this->post('order');
        $token = \Core::make("token");
        if (!$token->validate('order_management')) {
            return new Response('Invalid Request Token.', 401);
        }
        $badgeId = $order['badge_id'] ?? null;
        if (empty($badgeId)) {
            return new Response('No Badge Id transmitted', 401);
        }
        $ul = new UserList();
        $ul->filterByAttribute('badge_id', $badgeId);
        $user = $ul->getResults()[0] ?? null;
        if (!is_object($user)) {
            return new Response('No User associated to this Badge ID: ' . $badgeId, 401);
        }
        $items = $order['items'] ?? [];
        if (empty($items)) {
            return new Response('No Items selected', 500);
        }

        $totalPrice = 0;
        $itemCount = 0;
        $itemNames = [];
        foreach ($items as $i) {
            $product = Product::getByID($i['id']);
            if ($product) {
                $totalPrice += ($i['quantity'] * $product->getPrice());
                $itemCount += $i['quantity'];
                $itemNames[] = $product->getName();
            }
        }
        $message = $itemCount . ' Produkte gekauft: (' . implode(' ,', $itemNames) . ')';
        $lanName = class_exists(CurrentLan::class) ? CurrentLan::getLANTitle() : '';
        try {
            CreditManager::addRecord($user, -$totalPrice, $message, ['Catering POS', $lanName]);
        } catch (\Throwable $e) {
            return new Response("Failed: " . $e->getMessage(), 500);
        }

        return new Response('Transaktion Erfolgreich');
    }
}
