<?php
defined('C5_EXECUTE') or die("Access Denied.");
$fh = Core::make('helper/form');?>
<div id="cm-bulk-add-record" class="ccm-ui">
    <form action="<?= $this->action('confirm') ?>" method="POST">
        <div class="form-group">
            <label for="selectedGroup"><?= t('Group') ?></label>
            <?php echo $fh->select('selectedGroup', $relevant_groups, null, ['class' => 'form-control']) ?>
            <small class="text-muted">
                <?= t('The transaction will be added to every user in the selected group.') ?>
                <strong id="cm-bulk-count"></strong>
            </small>
        </div>
        <div class="form-group">
            <label><?= t('Add/ Subtract Value') ?></label>
            <?= $fh->number('recordValue', ['class' => 'form-control', 'step' => '0.01']) ?>
            <small class="text-muted"><?= t('Positive Values add to the balance, negative values substract from it.') ?></small>
        </div>
        <div class="form-group">
            <label for="comment"><?= t('Comment') ?></label>
            <?= $fh->text('recordComment', ['class' => 'form-control']) ?>
        </div>
        <input type="hidden" value="<?= Core::make('token')->generate('bulkAddRecord'); ?>" name="ccm_token">
        <input type="hidden" value="<?= h($batchId); ?>" name="batchId">
    </form>
    <div class="dialog-buttons">
        <button class="btn btn-success pull-left" id="cm-bulk-confirm"><?= t('Confirm') ?></button>
        <button class="btn btn-danger pull-right" onclick="jQuery.fn.dialog.closeTop()"><?= t('Cancel') ?></button>
    </div>
</div>
<script type="text/javascript">
    $(function() {
        var countUrl = <?= json_encode($countUrl) ?>;
        var affected = null;
        var refreshCount = function() {
            $('#cm-bulk-count').text('…');
            affected = null;
            $.getJSON(countUrl, {selectedGroup: $('#cm-bulk-add-record select[name=selectedGroup]').val()}, function(data) {
                affected = data.count;
                $('#cm-bulk-count').text(<?= json_encode(t('%s users affected.')) ?>.replace('%s', data.count));
            });
        };
        $('#cm-bulk-add-record select[name=selectedGroup]').on('change', refreshCount);
        refreshCount();
        $('#cm-bulk-confirm').on('click', function() {
            var value = $('#cm-bulk-add-record input[name=recordValue]').val();
            var question = <?= json_encode(t('Book %1$s for %2$s users?')) ?>.replace('%1$s', value).replace('%2$s', affected === null ? '?' : affected);
            if (!window.confirm(question)) {
                return false;
            }
            $(this).prop('disabled', true);
            $('#cm-bulk-add-record form').submit();
            return false;
        });
    });
</script>
