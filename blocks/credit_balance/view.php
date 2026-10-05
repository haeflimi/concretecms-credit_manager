<?php
defined('C5_EXECUTE') or die(_("Access Denied.")); ?>

<div class="row">
    <div class="col-md-4 col-sm-8 col-xs-6">
        <div class="box">
            <div class="description">
                Dein Guthaben
            </div>
            <div class="big-text <?=($balance>=0)?'text-success':'text-danger'?>">
                <?= round($balance,3) ?> SFr.
            </div>
        </div>

    </div>
    <div class="col-md-7 col-md-offset-1 col-sm-8 col-xs-12">

        <h4 id="payrexx-payment">Online Zahlung</h4>

        <?php if($balance < 0):?>

            <div class="row">
                <div class="col-xs-12 col-md-4">
                    <div id="paypal-button-container"></div>
                    <div id="success-message" class="alert alert-success" style="display: none">
                        <p><strong>Zahlung erfolgreich.</strong><br/>
                            Unter Umständen kann es einige Minuten dauern, bis der Kontostand auf unserer Homepage korrekt angezeigt wird. - Bitte die Zahlung NICHT wiederholen
                            und bei Unstimmigkeiten bie <a href="mailto:tuborg@turicane.ch">TuBorg</a> melden.</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <?=$paymentButton?>
                    <p class="small text-muted">Via Payrexx stehen diverse Online- Zahlungsmethoden zur Verfügung.</p>
                </div>
            </div>

        <?php else:?>
            <p>
                Die Online-Überweisung ist nur bei einem negativen Kontostand möglich.
            </p>
        <?php endif?>
    </p>
</div>

