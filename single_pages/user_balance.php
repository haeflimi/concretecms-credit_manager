<?php defined('C5_EXECUTE') or die("Access Denied."); ?>

<div class="container">

    <h2><?= t('Balance') ?></h2>
    <div class="row">
        <div class="col-md-4 col-sm-8 col-xs-6">
            <div class="card card-body bg-xdark fact animated fadeIn delay-1" data-perc="<?=$userBalance?>">
                <div class="big-text colored">
                    <span class="factor"></span> <?=$curencySymbol?>
                </div>
                <div class="description">
                    <?=$balanceWord?>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-sm-8 col-xs-6">
            <button class="btn btn-primary btn-rounded mt-3">Per Paypal ausgleichen</button>
        </div>
        <div class="col-md-4 col-sm-8 col-xs-6">
            <button class="btn btn-primary btn-rounded mt-3">Per Kreditkarte ausgleichen</button>
        </div>
    </div>

    <h2 class="mt-5"><?= t('Transaction History') ?></h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <td></td>
            </tr>
        </thead>
        <tbody>

        </tbody>
    </table>
</div>

