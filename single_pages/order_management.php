<?php
defined('C5_EXECUTE') or die("Access Denied.");
$c = Page::getCurrentPage();
$p = new Permissions($c);
$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$this->inc('elements/header_top.php');  ?>

<section id="cm-order-management" class="container-fluid">
    <a class="btn btn-primary pull-right" href="<?=\URL::to('/pos')?>">POS Terminal</a>
    <header>
        <h1>
            Order Management
        </h1>
    </header>
    <main>
        <div class="row">
            <div class="col-8 left-col d-flex flex-column">
                <div class="card products mb-3">
                    <div class="card-header">
                        <ul class="nav nav-pills card-header-pills">
                            <li class="nav-item">
                                <a class="nav-link active" href="#current" data-toggle="tab" role="tab" id="all-tab">Aktuelle Bestellungen</a>
                            </li>
                            <!--<li class="nav-item">
                                <a class="nav-link disabled" href="#closed" data-toggle="tab" role="tab" id="getraenke-tab">Abgeschlossene Bestellungen</a>
                            </li>-->
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-pane active" id="current" role="tabpanel" aria-labelledby="all-tab">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>
                                        <div class="checkbox-inline">
                                            <input type="checkbox" @click="selectAll" v-model="all_selected"/>
                                        </div>
                                    </th>
                                    <th></th>
                                    <th></th>
                                    <th></th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="order in orders" :key="order.id">
                                        <td>
                                            <div class="checkbox-inline">
                                                <input type="checkbox" :value="order.id" v-model="selected_orders"/>
                                            </div>
                                        </td>
                                        <td>
                                            {{order.status}}
                                        </td>
                                        <td>
                                            {{order.product_name}}
                                        </td>
                                        <td>
                                            {{order.user_name}}
                                        </td>
                                        <td class="text-right">
                                            {{order.value  | currency}}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-4 right-col d-flex flex-column">
                <div class="card cart mb-3">
                    <h4 class="card-header">Zusammenfassung</h4>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item" v-for="go in groupedOrders">
                            <span>{{go.length}} x </span> {{go[0].product_name}}
                        </li>
                    </ul>
                    <div class="card-footer mt-auto">
                        <span>
                            <strong>Total: {{ totalAmount | currency }}</strong>
                        </span>
                    </div>
                </div>
                <div class="card checkout mt-auto mb-3">
                    <h4 class="card-header">
                        Verwaltung
                    </h4>
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-12">
                                <button class="btn btn-primary btn-block" v-on:click="setOrdered">
                                    Markierte auf "Bestellt" setzen
                                </button>
                                <button class="btn btn-success btn-block" v-on:click="setDelivered">
                                    Markierte auf "Ausgeliefert" setzen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</section>
<script>
    Vue.filter('currency', function (value) {
        return parseFloat(value).toFixed(2)+' Fr.';
    });
    var OrderManagement = new Vue({
        el: '#cm-order-management',
        data: {
            is_processing: false,
            orders: [],
            all_selected: false,
            selected_orders: [],
            ccm_token: <?=$ccm_token?>,
        },
        methods: {
            /*removeItem: function (id) {
                $.post("<?=$orderSetOrderedAction?>", {this.selected_orders, ccm_token: this.ccm_token}, function (response) {
                    OrderManagement.alertSuccess(response.responseText)
                }).fail(function (response) {
                    OrderManagement.alertError(response)
                });
            },*/
            alertSuccess: function(message) {
                new PNotify({
                    type: 'success',
                    icon: 'fa fa-thumbs-up',
                    title: 'Erfolgreich',
                    text: message,
                    hide: true,
                });
            },
            alertError : function(message) {
                new PNotify({
                    type: 'error',
                    icon: 'fa fa-close',
                    title: 'Fehler',
                    text: message,
                    hide: true,
                });
            },
            selectAll: function() {
                if(!this.all_selected){
                    this.orders.forEach(function(order){
                        OrderManagement.selected_orders.push(order.id)
                    })
                } else {
                    this.selected_orders = [];
                }
            },
            getOrders: function(){
                this.is_processing = true;
                this.orders = [];
                $.get("<?=$orderGetAction?>", {ccm_token: this.ccm_token}, function (response) {
                    response.forEach(function(element){
                        OrderManagement.orders.push(element);
                    })
                    OrderManagement.is_processing = false;
                }).fail(function (response) {
                    OrderManagement.alertError(response)
                    OrderManagement.is_processing = false;
                });
            },
            setOrdered : function(){
                $.post("<?=$orderSetOrderedAction?>", {selected_orders:this.selected_orders, ccm_token: this.ccm_token}, function (response) {
                    OrderManagement.alertSuccess(response);
                    OrderManagement.getOrders();
                }).fail(function (response) {
                    OrderManagement.alertError(response)
                });
                this.selected_orders = [];
            },
            setDelivered: function(){
                let hasOpen = false;
                let orders = this.orders;
                if(this.selected_orders.length <= 0)return;
                this.selected_orders.forEach(function(orderID){
                    let ord = orders.find((o) => o.id === orderID);
                    if(ord && (ord.status === 'Offen' || ord.status_handle === 'incomplete' || ord.status_handle === 'open' || ord.status_handle === 'awaiting_processing')){
                        hasOpen = true;
                    }
                });
                if(hasOpen){
                    if(!confirm("Du hast Bestellungen ausgewählt, die noch nicht auf 'Bestellt' gesetzt wurden. Bist du sicher?")){
                        return;
                    }
                }
                $.post("<?=$orderSetDeliveredAction?>", {selected_orders:this.selected_orders, ccm_token: this.ccm_token}, function (response) {
                    OrderManagement.alertSuccess(response);
                    OrderManagement.getOrders();
                }).fail(function (response) {
                    OrderManagement.alertError(response)
                });
                this.selected_orders = [];
            },
            groupBy: function(xs, key) {
                return xs.reduce(function(rv, x) {
                    (rv[x[key]] = rv[x[key]] || []).push(x);
                    return rv;
                }, {});
            }
        },
        computed: {
            totalAmount: function () {
                var sum = 0;
                this.orders.forEach(order => {
                    sum += order.value;
                });
                return sum;
            },
            itemCount: function() {
                return this.orders.length;
            },
            groupedOrders: function() {
                var summary = this.groupBy(this.orders, 'product_id');
                return summary;
            }
        },
        mounted: function () {
            this.getOrders();
        }
    });
</script>
<style>
    #ccm-toolbar {
        display: none !important;
    }
    .ccm-notification-help-launcher {
        display: none !important;
    }
    #cm-order-management {
        height: 100vh;
    }
    #cm-order-management .product-item .product-price {
        padding-right: 30px;
    }
    #cm-order-management .right-col, #pos .left-col {
        height: calc(100vh - 86px);

    }
    #cm-order-management .left-col .card.products {
        flex-grow: 1;
    }
    #cm-order-management .right-col .card.cart {
        width: 100%;
        flex-grow: 1;
    }
    #cm-order-management .right-col .card.checkout {
        width: 100%;
    }
    #cm-order-management .card .list-group-item button  {
        margin-top: -5px;
    }
    #cm-order-management .fullscreen {
        position: fixed;
        height: 100vh;
        width: 100vw;
        top: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 999999;
        margin-left: -15px;
    }
    #cm-order-management .fullscreen .fa {
        font-size: 20rem;
        color: #fff;
    }
    #cm-order-management [v-cloak] {
        display: none;
    }
</style>
