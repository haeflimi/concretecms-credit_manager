<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use CreditManager\PageControllers\PosPageController;

/**
 * Staff-operated catering POS: customers are picked by name or badge scan.
 */
class Pos extends PosPageController
{
    public function getCmCategory()
    {
        return 'Catering POS';
    }
}
