<?php
defined('C5_EXECUTE') or die("Access Denied.");
$c = Page::getCurrentPage();
$p = new Permissions($c);
$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$this->inc('elements/header_top.php');?>

<section id="comp-balance" class="container">
    <main>

        <div class="row">
            <?php $balanceBlock = BlockType::getByHandle('credit_balance');
            $balanceBlock->render();
            ?>
        </div>

        <div class="row">
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
                </table>
            </div>
        </div>
    </main>
</section>