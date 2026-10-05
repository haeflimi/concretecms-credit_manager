<?php
defined('C5_EXECUTE') or die("Access Denied.");
$c = Page::getCurrentPage();
$p = new Permissions($c);
$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$this->inc('elements/header_top.php');  ?>

<section id="comp-pos" class="container-fluid">
    <header>
        <h1>
            Self Service
        </h1>
    </header>
    <main>
        <div class="row">
            <div class="col-8 left-col d-flex flex-column">
                <div class="card products mb-3">
                    <div class="card-header">
                        <ul class="nav nav-pills card-header-pills">
                            <li class="nav-item">
                                <a class="nav-link active" href="#all" data-toggle="tab" role="tab" id="all-tab">Alle</a>
                            </li>
                            <!--<li class="nav-item">
                                <a class="nav-link disabled" href="#getraenke" data-toggle="tab" role="tab" id="getraenke-tab">Getränke</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link disabled" href="#snacks" data-toggle="tab" role="tab" id="snacks-tab">Snacks</a>
                            </li>-->
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content slimScroll">
                            <div class="tab-pane active" id="all" role="tabpanel" aria-labelledby="all-tab">
                                <ul class="list-group">
                                    <li v-for="product in products" :key="product.id" class="list-group-item product-item">
                                        {{product.name}}
                                        <span class="pull-right">
                                            <button class="btn btn-success btn-sm" v-on:click="addItem(product.id)"><i class="fa fa-plus"></i></button>
                                        </span>
                                        <span class="pull-right product-price">{{product.price | currency }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-4 right-col d-flex flex-column">
                <div class="card cart mb-3">
                    <h4 class="card-header">Aktueller Einkauf</h4>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item" v-for="sp in selected_products" :key="sp.id">
                            <span>{{sp.quantity}} x </span> {{sp.name}}
                            <span class="pull-right">
                                <button class="btn btn-danger btn-sm" v-on:click="removeItem(sp.id)"><i class="fa fa-trash"></i></button>
                            </span>
                            <span class="pull-right mr-3">
                                <button class="btn btn-danger btn-sm" v-on:click="reduceItem(sp.id)"><i class="fa fa-minus"></i></button>
                            </span>
                        </li>
                    </ul>
                    <div class="card-footer mt-auto">
                        <span>
                            <strong>Total: {{totalAmount | currency }}</strong>
                        </span>
                    </div>
                </div>
                <div class="card checkout mt-auto mb-3">
                    <h4 class="card-header">
                        Checkout
                    </h4>
                    <div class="card-body" v-if="!active_user">
                        <div class="form-group">
                            <input type="text" tabindex="1" ref="badgeInput" class="form-control" v-model="badge_id" placeholder="Scan your Badge!" v-on:keyup.13="activateUser">
                        </div>
                    </div>
                    <div class="card-body" v-if="active_user">
                        <div class="row no-gutters">
                            <div class="col-md-4">
                                <img :src="active_user.avatar" class="card-img" :alt="active_user.name">
                            </div>
                            <div class="col-md-8">
                                <div class="card-body">
                                    <div class="card-title">{{ active_user.name }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-6">
                                <button class="btn btn-danger btn-block" v-on:click="reset">
                                    Zurücksetzen
                                </button>
                            </div>
                            <div class="col-6">
                                <button v-if="!is_processing" class="btn btn-success btn-block" :disabled="isDisabled" v-on:click="confirm">
                                    Bestätigen
                                </button>
                                <button v-if="is_processing" class="btn btn-success btn-block">
                                    <i class="fa fa-spinner fa-spin"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <div class="fullscreen success bg-success" v-if="active_alert == 'success'">
        <i class="fa fa-thumbs-up"></i>
    </div>
    <div class="fullscreen abort bg-danger" v-if="active_alert == 'error'">
        <i class="fa fa-trash"></i>
    </div>
</section>
<script>
    Vue.filter('currency', function (value) {
        return parseFloat(value).toFixed(2)+' Fr.';
    });
    $(document).click(function(event) {
        var $target = $(event.target);
        PointOfSales.setFocus();
    });
    var PointOfSales = new Vue({
        el: '#comp-pos',
        data: {
            badge_id: '',
            is_processing: false,
            active_alert: null,
            users: <?=$users?>,
            products: <?=$products?>,
            selected_products: [],
            active_user: null,
            cart_id: null,
            ccm_token: <?=$ccm_token?>,
        },
        methods: {
            addItem: function (id) {
                var idx = this.selected_products.findIndex(prod => prod.id === id);
                if(idx >= 0){
                    var item = this.selected_products[idx];
                    item.quantity++;
                    Vue.set(this.selected_products, idx, item)
                } else {
                    var prod = this.products.find(prod => prod.id === id);
                    prod.quantity = 1;
                    this.selected_products.push(prod)
                }
                this.setFocus();
            },
            removeItem: function (id) {
                var idx = this.selected_products.findIndex(prod => prod.id === id);
                if(idx >= 0){
                    this.selected_products.splice(idx,1)
                }
                this.setFocus();
            },
            reduceItem: function (id) {
                var idx = this.selected_products.findIndex(prod => prod.id === id);
                if(idx >= 0){
                    var item = this.selected_products[idx];
                    item.quantity--;
                    if(item.quantity === 0) {
                        this.selected_products.splice(idx,1)
                    } else {
                        Vue.set(this.selected_products, idx, item)
                    }
                }
                this.setFocus();
            },
            activateUser: function(event) {
                var badge_id = event.target.selectedOptions ? event.target.selectedOptions[0].value : event.target.value;
                if (!badge_id) {
                    return;
                }
                var self = this;
                $.post("<?=$lookupAction?>", {badge_id: badge_id, ccm_token: this.ccm_token}, function (user) {
                    self.active_user = user;
                }).fail(function () {
                    self.alertError('Kein Benutzer mit dieser Badge Id');
                    self.reset();
                });
            },
            newCartId: function() {
                // identifies this cart on the server: a retried or double-sent checkout is booked once
                this.cart_id = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2));
            },
            setFocus: function(){
                if(this.$refs.badgeInput){
                    this.$nextTick(() => this.$refs.badgeInput.focus())
                }
            },
            reset: function() {
                this.newCartId();
                this.selected_products.splice(0, this.selected_products.length);
                this.active_user = null;
                this.active_alert = null;
                this.is_processing = false;
                this.badge_id = '';
                location = location;
                this.$nextTick(() => this.setFocus());
            },
            flashFullScreenAlert(type) {
                this.active_alert = type;
                setTimeout(function(){
                    PointOfSales.reset();
                }, 1000);
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
            confirm : function () {
                var order = {
                    items: this.selected_products.map(function (p) { return {id: p.id, quantity: p.quantity}; }),
                    badge_id: this.active_user.badge_id,
                    item_count: this.itemCount,
                    uuid: this.cart_id
                }
                this.is_processing = true;
                $.post("<?=$orderAction?>", {order,ccm_token: this.ccm_token}, function (response) {
                    PointOfSales.flashFullScreenAlert('success')
                }).fail(function (response) {
                    PointOfSales.flashFullScreenAlert('error')
                });
            }
        },
        computed: {
            totalAmount: function () {
                var sum = 0;
                this.selected_products.forEach(e => {
                    sum += e.price * e.quantity;
                });
                return sum
            },
            itemCount: function() {
                var itemCount = 0;
                this.selected_products.forEach((item) => {
                    itemCount = itemCount + item.quantity;
                });
                return itemCount;
            },
            isDisabled: function() {
                if(!this.active_user || this.selected_products.length == 0){
                    return 'disabled';
                } else {
                    return null;
                }
            }
        },
        mounted: function () {
            this.newCartId();
            this.$nextTick(function () {
                this.setFocus();
            })
        }
    });
</script>
<style>
    html {
        height: 100%;
        width: 100%;
        overflow: hidden;
    }
    body {
        height: 100%;
        padding: 0;
        overflow: auto;
        margin: 0;
        -webkit-overflow-scrolling: touch;
    }
    #comp-pos {
        height: 100vh;
    }
    #ccm-toolbar {
        display: none !important;
    }
    .ccm-notification-help-launcher {
        display: none !important;
    }
    #comp-pos .product-item .product-price {
        padding-right: 30px;
    }
    #comp-pos .right-col, #pos .left-col {
        height: calc(100vh - 86px);

    }
    #comp-pos .left-col .card.products {
        flex-grow: 1;
    }
    #comp-pos .right-col .card.cart {
        width: 100%;
        flex-grow: 1;
    }
    #comp-pos .right-col .card.checkout {
        width: 100%;
    }
    #comp-pos .card .list-group-item button  {
        margin-top: -5px;
    }
    #comp-pos .fullscreen {
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
    #comp-pos .fullscreen .fa {
        font-size: 20rem;
        color: #fff;
    }
    #comp-pos [v-cloak] {
        display: none;
    }
    #comp-pos  .products .slimScroll {
        /*max-height: 400px;*/
        overflow-y: scroll;
    }
</style>
