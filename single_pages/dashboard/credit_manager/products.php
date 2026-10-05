<?php defined('C5_EXECUTE') or die("Access Denied.");
use CreditManager\CreditManager;
$nh = Core::make('helper/navigation'); ?>

<?php if(!empty(Config::get('credit_manager.categories_topic'))):?>
<div class="ccm-dashboard-header-buttons hidden-print">
    <a class="btn btn-primary" data-button="add_product"><i class="fa fa-plus"></i> <?=t('Add Product')?></a>
    <a href="/dashboard/system/attributes/topics/view<?=Config::get('credit_manager.product_categories_topic')?>" class="btn btn-primary">Kategorie Tags Verwalten</a>
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
</div>

<div class="ccm-dashboard-content-full">
    <table class="table ccm-search-results-table">
        <thead class="hidden-print">
        <tr>
            <th><?php $dir = ($pl->getActiveSortDirection() == 'asc' ? 'desc' : 'asc');?>
                <a href="<?=$pl->getSortURL('p.name',$dir)?>">Produktname</a>
            </th>
            <th class="text-center"><a>Kategorien</a></th>
            <th class="text-right"><a href="<?=$pl->getSortURL('p.price',$dir)?>">Preis</a></th>
            <th class="text-right"><a>Actions</a></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($productList as $product):;?>
            <tr>
                <td>
                    <?=$product->getName()?>
                </td>
                <td class="text-center"><?php foreach($product->getCategories() as $c):?>
                    <span class="badge badge-primary" data-categoryId="<?=$c->getCategoryId()?>"><?=$c->getCategoryName()?></span>
                    <?php endforeach; ?>
                </td>
                <td class="text-right"><?=number_format($product->getPrice(), 2)?></td>
                <td class="text-right">
                    <div class="pull-right">
                        <a href="<?=$this->action('deleteProduct', $product->getId())?>?ccm_token=<?=urlencode($deleteToken)?>" onclick="return window.confirm('<?=t('Delete this product?')?>');" class="btn btn-danger btn-sm mr-3"><i class="fa fa-trash"></i></a>
                        <a href="#" data-button="edit_product" data-pid="<?=$product->getId()?>" class="btn btn-primary btn-sm"><i class="fa fa-edit"></i></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    $('a[data-button=add_product]').on('click', function() {
        $.fn.dialog.open({
            href: '/ccm/credit_manager/add_product',
            title: 'Add Product',
            width: '800',
            height: '600',
            modal: true
        });
        return false;
    });
    $('a[data-button=edit_product]').on('click', function() {
        var pId = $(this).data('pid');
        $.fn.dialog.open({
            href: '/ccm/credit_manager/edit_product/'+pId,
            title: 'Edit Product',
            width: '800',
            height: '600',
            modal: true
        });
        return false;
    });
</script>