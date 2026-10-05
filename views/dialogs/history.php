<?php
defined('C5_EXECUTE') or die("Access Denied."); ?>
<div id="cm-history" class="ccm-ui ccm-dialog-content">
    <p>
        <strong><?=t('Balance')?>:</strong> <?=number_format($balance, 2)?>
        <span class="text-muted">(<?=t('%d records', $count)?><?php if ($count > count($history)): ?>, <?=t('newest %d shown', count($history))?><?php endif; ?>)</span>
    </p>
    <table class="ccm-search-results-table">
        <thead>
            <tr>
                <th><a><?=t('Date/ Time')?></a></th>
                <th><a><?=t('Comment')?></a></th>
                <th><a><?=t('Kategorien')?></a></th>
                <th><a><?=t('Source')?></a></th>
                <th><a><?=t('Value')?></a></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($history as $record):?>
                <tr>
                    <td><?=$record->getTimestamp()->format('d.m.Y H:i')?></td>
                    <td><?=h($record->getComment())?></td>
                    <td><?php foreach($record->getCategories() as $crc){
                            echo '<span class="badge pr-2">'.h($crc->getCategoryName()).'</span>';
                        }?></td>
                    <td class="text-muted small"><?=h($record->getSource())?><?php if ($record->getExternalRef()): ?>:<?=h($record->getExternalRef())?><?php endif; ?></td>
                    <td class="text-right"><?=number_format($record->getValue(), 2)?></td>
                </tr>
            <?php endforeach;?>
        </tbody>
    </table>
</div>
