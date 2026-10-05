<?php

namespace Concrete\Package\CreditManager\Block\Shop;

use Application\Turicane\CurrentLan;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Http\Response;
use Concrete\Core\User\User;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\GroupList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Core;

class Controller extends BlockController {

  public $collection;
  protected $btTable = 'btCmShop';
  protected $btInterfaceWidth = "800";
  protected $btInterfaceHeight = "600";
  protected $btCacheBlockRecord = true;
  protected $btCacheBlockOutput = true;
  protected $btCacheBlockOutputOnPost = true;
  protected $btCacheBlockOutputForRegisteredUsers = false;
  protected $btCacheBlockOutputLifetime = 300;
  protected $btHandle = 'cm_shop';
  protected $communityStoreProductTypeId = 2;

  public function __construct($obj = null) {
    parent::__construct($obj);
  }

  public function getBlockTypeDescription() {
    return t("");
  }

  public function getBlockTypeName() {
    return t("Shop");
  }

  public function save($args) {
    parent::save($args);
  }

  public function add()
  {
      $this->edit();
  }

  public function edit()
  {
      $groups = [0 => t('Keine')];
      $groupList = GroupList::getGroupList();
      foreach ($groupList as $group) {
          $groups[$group->getGroupID()] = $group->getGroupName();
      }
      $this->set('categoryTreeNodes', $groups);
      $this->set('active_category', $this->active_category ?? 0);
      $this->set('run_time', $this->run_time ?? '');
  }

  public function view() {

      $this->requireAsset('javascript', 'vue');
      $this->requireAsset('javascript', 'slimScroll');
      $this->requireAsset('pnotify');

      $this->set('ccm_token', json_encode(Core::make('token')->generate('shop_block_order')));

      $products = [];
      if (!empty($this->active_category)) {
          $productList = new ProductList();
          if ($this->communityStoreProductTypeId) {
              $productList->setProductType($this->communityStoreProductTypeId);
          }
          $productList->setGroupID((int)$this->active_category);
          $productObjects = $productList->getResults();
          foreach ($productObjects as $po) {
              if (empty($po)) continue;
              $products[] = [
                  'id' => $po->getID(),
                  'name' => $po->getName(),
                  'price' => $po->getPrice()
              ];
          }
      }
      $this->set('products', json_encode($products));

      $u = new User();
      $orders = [];
      if ($u->isRegistered()) {
          $orderList = new OrderList();
          $orderList->setCustomerID($u->getUserID());
          $orderObjects = $orderList->getResults();

          foreach ($orderObjects as $oo) {
              if (!is_object($oo)) continue;

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

              if ($oo->getCancelled()) {
                  continue;
              }

              $productNames = [];
              foreach ($oo->getOrderItems() as $item) {
                  $qty = (int)$item->getQuantity();
                  $name = $item->getProductName();
                  $productNames[] = $qty > 1 ? ($qty . 'x ' . $name) : $name;
              }
              $productName = !empty($productNames) ? implode(', ', $productNames) : t('Kein Produkt');

              $statusName = $oo->getStatus();
              $statusHandle = $oo->getStatusHandle();
              if (empty($statusName)) {
                  $statusName = $statusHandle ? ucfirst($statusHandle) : t('Offen');
              }

              $orders[] = [
                  'id' => $oo->getOrderID(),
                  'product' => $productName,
                  'status' => $statusName,
                  'statusHandle' => $statusHandle,
              ];
          }
      }

      $this->set('orders', json_encode($orders));
      $this->set('run_time', $this->run_time);
      $this->set('bId', $this->bID);
      $this->set('userId', $u->getUserID());
  }

  public function action_orderProduct() {
      $order = $this->post('order');
      $token = \Core::make("token");
      if (!$token->validate('shop_block_order')) {
          return new Response('Invalid Request Token.', 401);
      }
      $user = User::getByUserID($order['user_id']);
      if(!$user){
          return new Response('Invalid User.', 401);
      }
      $product = Product::getByID($order['product_id']);
      if(!$product){
          return new Response('Invalid Product.', 401);
      }

      try {
          $csOrder = new Order();
          $csOrder->setCustomerID($user->getUserID());
          $csOrder->setDate(new \DateTime());
          $csOrder->setTotal($product->getPrice());
          $csOrder->save();

          if (class_exists(CurrentLan::class) && !empty(CurrentLan::$lanPageId)) {
              $csOrder->setAttribute('event_id', CurrentLan::$lanPageId);
          }

          $itemData = [
              'product' => [
                  'object' => $product,
                  'qty' => 1,
                  'pID' => $product->getID(),
              ],
              'productAttributes' => [],
          ];
          OrderItem::add($itemData, $csOrder->getOrderID());

          $csOrder->updateStatus();

          return new Response('Bestellung Erfolgreich');
      } catch (\Throwable $e) {
          return new Response('Failed: ' . $e->getMessage(), 500);
      }
  }

  public function action_deleteOrder() {
      $order = $this->post('order');
      $opId = $order['order_id'];
      $token = \Core::make("token");
      if (!$token->validate('shop_block_order')) {
          return new Response('Invalid Request Token.', 401);
      }
      $csOrder = Order::getByID($opId);
      if (!$csOrder) {
          return new Response('Invalid Order.', 401);
      }
      $u = new User();
      if ((int)$csOrder->getCustomerID() !== (int)$u->getUserID()) {
          return new Response('Unauthorized.', 403);
      }

      $statusHandle = $csOrder->getStatusHandle();
      $startingStatus = OrderStatus::getStartingStatus();
      $startingHandle = $startingStatus ? $startingStatus->getHandle() : 'incomplete';

      if ($statusHandle && !in_array($statusHandle, ['incomplete', 'open', $startingHandle])) {
          return new Response('Cannot delete processed Order.', 401);
      } else {
          try {
              $csOrder->remove();
              return new Response('Löschung Erfolgreich');
          } catch (\Throwable $e) {
              return new Response('Failed: ' . $e->getMessage(), 500);
          }
      }
  }
}
