<?php defined('C5_EXECUTE') or die("Access Denied.");
if(empty($userId)):?>
<div v-if="!user_id" class="alert alert-info">
    You must be signed in to use this Functionality.
</div>
<?php return; endif ?>
<div id="shop-<?=$bId;?>" v-if="user_id > 0" class="catering-order-block">
    <h3>Deine Bestellungen für {{ run_time }}:</h3>
    <div v-if="orders.length == 0" class="alert alert-info">
        Aktuell keine Bestellungen vorhanden
    </div>
    <table v-if="orders.length > 0" class="table table-striped table-sm">
        <thead class="no-bd">
        <tr>
            <th>Status</th>
            <th>Produkt</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
            <tr v-for="order in orders">
                <td>{{ order.status }}</td>
                <td>{{ order.product }}</td>
                <td><button v-if="order.status == 'Offen' || order.statusHandle == 'incomplete' || order.status == 'Awaiting Processing'" class="btn btn-danger btn-sm pull-right" title="Bestellung löschen" v-on:click="removeItem(order.id)"><i class="fa fa-trash"></i></button></td>
            </tr>
        </tbody>
    </table>

    <h3>Bestellung Aufgeben</h3>

        <table class="table table-striped table-sm">
            <thead class="no-bd">
            <tr>
                <th>&nbsp;</th>
                <th>&nbsp;</th>
                <th>Produkt</th>
                <th class="text-align-right">Preis</th>
            </tr>
            </thead>
            <tbody>
                <tr v-for="product in products">
                    <td><div class="radio">
                            <input type="radio" :value="product.id" v-model="selected_product"/>
                    </div></td>
                    <td>&nbsp;</td>
                    <td>{{ product.name }}</td>
                    <td class="text-align-right">{{ product.price | currency }}</td>
                </tr>
            </tbody>
        </table>
    <div class="row">
        <div class="col-12">
            <button v-if="!is_processing" class="btn btn-success pull-right" v-on:click="confirm">
                Bestellen
            </button>
            <button v-if="is_processing" class="btn btn-success pull-right">
                <i class="fa fa-spinner fa-spin"></i>
            </button>
        </div>
    </div>
</div>

<script>
    Vue.filter('currency', function (value) {
        return parseFloat(value).toFixed(2)+' Fr.';
    });
    var Shop = new Vue({
        el: '#shop-<?=$bId;?>',
        data: {
            active_alert: null,
            run_time: '<?=$run_time?>',
            user_id: '<?=$userId?>',
            products: <?=$products?>,
            is_processing: false,
            orders: <?=$orders?>,
            selected_product: 0,
            ccm_token: <?=$ccm_token?>,
        },
        methods: {
            removeItem: function (id) {
                var order = {
                    order_id: id
                };
                $.post("<?=$this->action('deleteOrder')?>", {order,ccm_token: this.ccm_token}, function (response) {
                    Shop.alertSuccess(response);
                    location = location;
                }).fail(function (response) {
                    var message = response.responseText;
                    Shop.alertError(message);
                });
            },
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
            confirm: function () {
                var order = {
                    product_id: this.selected_product,
                    user_id: this.user_id
                }
                this.is_processing = true;
                $.post("<?=$this->action('orderProduct')?>", {order, ccm_token: this.ccm_token}, function (response) {
                    Shop.alertSuccess(response);
                    this.is_processing = false;
                    location = location;
                }).fail(function (response) {
                    var message = response.responseText;
                    Shop.alertError(message);
                    this.is_processing = false;
                    location = location;
                });
            }
        }
    });
</script>
<style>

</style>



