<?php
namespace CreditManager\Controller;

use Concrete\Controller\Backend\UserInterface;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;

/**
 * Base for the package's modal dialogs. They are reached through plain routes, so the permission check has to
 * live here: only users who may open the Credit Manager dashboard section can view or submit them.
 */
abstract class DashboardDialog extends UserInterface
{
    const DASHBOARD_PATH = '/dashboard/credit_manager';

    protected function canAccess()
    {
        return self::userCanManage();
    }

    /**
     * Whether the current user may administer credit accounts (= may view the Credit Manager dashboard section).
     */
    public static function userCanManage()
    {
        $page = Page::getByPath(self::DASHBOARD_PATH);
        if (!$page || $page->isError()) {
            return false;
        }
        return (bool) (new Checker($page))->canViewPage();
    }
}
