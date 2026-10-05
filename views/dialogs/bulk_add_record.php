<?php
$fh = Core::make('helper/form');?>
<div id="cm-bulk-add-record" class="ccm-ui">
    <form action="<?= $this->action('confirm') ?>" method="POST">
        <div class="form-group">
            <label for="selectedGroup"><?= t('Group') ?></label>
            <?php echo $fh->select('selectedGroup', $relevant_groups, null, ['class' => 'form-control']) ?>
            <small class="text-muted"><?= t('The transaction will be added to every user in the selected group.') ?></small>
        </div>
        <div class="form-group">
            <label><?= t('Add/ Subtract Value') ?></label>
            <?= $fh->number('recordValue', ['class' => 'form-control']) ?>
            <small class="text-muted"><?= t('Positive Values add to the balance, negative values substract from it.') ?></small>
        </div>
        <div class="form-group">
            <label for="comment"><?= t('Comment') ?></label>
            <?= $fh->text('recordComment', ['class' => 'form-control']) ?>
        </div>
        <input type="hidden" value="<?= Core::make('token')->generate('bulkAddRecord'); ?>" name="ccm_token">
    </form>
    <div class="dialog-buttons">
        <button class="btn btn-success pull-left" onclick="$('#cm-bulk-add-record form').submit()"><?= t('Confirm') ?></button>
        <button class="btn btn-danger pull-right" onclick="jQuery.fn.dialog.closeTop()"><?= t('Cancel') ?></button>
    </div>
</div>
