<?php defined('C5_EXECUTE') or die("Access Denied.");
use CreditManager\CreditManager;
$nh = Core::make('helper/navigation') ?>

<?php if(!empty(Config::get('credit_manager.categories_topic'))):?>
<div class="ccm-dashboard-header-buttons">
    <a href="/dashboard/system/attributes/topics/view<?=Config::get('credit_manager.categories_topic')?>" class="btn btn-primary">Kategorie Tags Verwalten</a>
</div>
<?php endif; ?>

<div class="ccm-dashboard-content">

    <?php if($errors):;?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong><br/>
            <?=$errors->output();?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <form role="form" action="<?=$controller->action('view')?>/<?=$mId?>" class="form-inline ccm-search-fields">
        <div class="ccm-search-fields-row">
            <div class="form-group">
                <?php echo $form->label('keywords', t('Search'))?>
                <div class="ccm-search-field-content">
                    <div class="ccm-search-main-lookup-field">
                        <i class="fa fa-search"></i>
                        <?php echo $form->search('keywords', array('placeholder' => t('Keywords')))?>
                    </div>
                </div>
            </div>
        </div>
        <div class="ccm-search-fields-row">
            <div class="form-group">
                <?php echo $form->label('relevant_groups', t('Gruppenfilter'))?>
                <div class="ccm-search-field-content">
                    <div class="ccm-filter-group">
                        <?php echo $form->select('selectedGroup', $relevant_groups, $selectedGroup,['class' =>'form-control'])?>
                    </div>
                </div>
            </div>
        </div>
        <div class="ccm-search-fields-submit">
            <button type="submit" class="btn btn-primary pull-right"><?php echo t('Search')?></button>
        </div>
    </form>
</div>

<div class="ccm-dashboard-content-full">
    <table class="table ccm-search-results-table">
        <thead>
        <tr>
            <th><?php $dir = ($ul->getActiveSortDirection() == 'asc' ? 'desc' : 'asc');?>
                <a href="<?=$ul->getSortURL('u.uName',$dir)?>">Nickname</a>
            </th>
            <th><a href="<?=$ul->getSortURL('u.uEmail',$dir)?>">E-Mail</a></th>
            <th class="text-center"><a>Balance</a></th>
            <th class="text-right"><a>Actions</a></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($userList as $user):
            $ui = $user->getUserInfoObject();
            $userBalance = CreditManager::getUserBalance($user);?>
            <tr>
                <td>
                    <a href="<?= URL::to('/dashboard/users/search/view/', $user->getUserID()) ?>"><?= $user->getUserName() ?></a>
                </td>
                <td><?= $user->getUserEmail() ?></td>
                <td class="text-center <?=($userBalance >= 0)?'success':'danger'?>"><strong><?= $userBalance; ?></strong></td>
                <td class="text-right">
                    <div class="input-group pull-right">
                        <a class="btn btn-primary btn-xs" data-button="add_record" data-uid="<?=$user->getUserID()?>"><i class="fa fa-plus"></i> <?=t('Add Record')?></a>
                        <a class="btn btn-default btn-xs" data-button="history" data-uid="<?=$user->getUserID()?>"><i class="fa fa-history"></i> <?=t('Show History')?></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    $('a[data-button=add_record]').on('click', function() {
        var uId = $(this).data('uid');
        $.fn.dialog.open({
            href: '/ccm/credit_manager/add_record/'+uId,
            title: 'Add Record',
            width: '280',
            height: '300',
            modal: true
        });
        return false;
    });

    $('a[data-button=history]').on('click', function() {
        var uId = $(this).data('uid');
        $.fn.dialog.open({
            href: '/ccm/credit_manager/history/'+uId,
            title: 'History',
            width: '1024',
            height: '768',
            modal: true
        });
        return false;
    });
</script>