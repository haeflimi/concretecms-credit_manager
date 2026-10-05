<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage;

use CreditManager\PageControllers\PosPageController;

/**
 * Self-service kiosk: access is governed by the page permissions, customers identify with their own badge, the
 * page never lists other people's badge ids.
 */
class SelfServicePos extends PosPageController
{
    public function getCmCategory()
    {
        return 'Self Service POS';
    }

    protected function exposesBadgeIds()
    {
        return false;
    }

    public function canAccess()
    {
        return true;
    }
}
