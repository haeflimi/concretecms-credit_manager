<?php
defined('C5_EXECUTE') or die(_("Access Denied.")); ?>

<div class="col-xs-12 col-sm-12">
    <table class="table table-striped">
        <thead class="bg-lighter">
        <tr>
            <th scope="col" colspan="3"><?=t('Latest Transactions')?></th>
        </tr>
        </thead>
        <tbody class="bg-lighter">
        <?php foreach($history as $record):
            ($record->getValue()>=0)?$cls='text-success':$cls='text-danger';?>
            <tr>
                <td><?=$record->getTimestamp()->format('d.m.Y H:i')?></td>
                <td><?=$record->getComment()?></td>
                <td class="<?=$cls?> font-weight-bold large"><?=$record->getValue()?></td>
            </tr>
        <?php endforeach; ?>
        <?php if($count > $limit):?>
            <tr>
                <td>...</td>
                <td>...</td>
                <td>...</td>
            </tr>
        <?php endif;?>
        </tbody>
        <tfoot>
        <tr>
            <td></td>
            <td></td>
            <td><a href="<?=URL::to('account/balance')?>" class="btn btn-primary pull-right">Alle anzeigen</a></td>
        </tr>
        </tfoot>
    </table>
</div>
