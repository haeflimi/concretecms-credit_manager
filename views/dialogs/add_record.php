<?php
defined('C5_EXECUTE') or die("Access Denied.");
$fh = Core::make('helper/form');?>
<div id="cm-add-record" class="ccm-ui">
    <form action="<?= $this->action('confirm') ?>" method="POST">
        <div class="form-group">
            <label><?= t('Add/ Subtract Value') ?></label>
            <?=$fh->number('recordValue', ['class'=>'form-control', 'step' => '0.01'])?>
            <small class="text-muted"><?=t('Positive Values add to the balance, negative values substract from it.')?></small>
        </div>
        <div class="form-group">
            <label for="category"><?= t('Category') ?></label>
            <?php echo $fh->selectMultiple('selectedCategories', $categoryTreeNodes, [],['style'=>'padding: 0;']) ?>
        </div>
        <div class="form-group">
            <label for="comment"><?= t('Comment') ?></label>
            <?=$fh->text('recordComment', ['class'=>'form-control'])?>
        </div>
        <input type="hidden" value="<?=Core::make('token')->generate('addRecord');?>" name="ccm_token">
        <input type="hidden" value="<?=(int) $uId;?>" name="recordUid">
        <input type="hidden" value="<?=h($bookingId);?>" name="bookingId">
    </form>
    <div class="dialog-buttons">
        <button class="btn btn-success pull-left" onclick="$('#cm-add-record form').submit(); $(this).prop('disabled', true);"><?=t('Confirm')?></button>
        <button class="btn btn-danger pull-right"  onclick="jQuery.fn.dialog.closeTop()"><?=t('Cancel')?></button>
    </div>
</div>
<script type="text/javascript">
    $(function() {
        $("#selectedCategories").select2();
    });
</script>
