<?php
namespace Concrete\Package\CreditManager\Controller\SinglePage\Dashboard;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Tree\Type\Topic as TopicTree;
use Concrete\Core\User\UserList;
use Concrete\Core\User\EditResponse as UserEditResponse;
use CreditManager\Repository\CmUserList;
use Exception;
use PermissionKey;
use Permissions;
use User;
use UserAttributeKey;
use UserInfo;
use Group;
use Page;
use Site;
use Config;
use Core;

class CreditManager extends DashboardPageController
{
    public function __construct(Page $c)
    {
        parent::__construct($c);

    }

    public function view()
    {
        $ul = new CmUserList();
        $this->requireAsset('core/topics');
        $this->requireAsset('select2');
        $relevant_groups = Config::get('credit_manager.relevant_groups');
        $relevant_groups['all'] = 'Alle';
        $this->set('relevant_groups', $relevant_groups);

        // only apply default filtering when not looking for someone specific
        if($keywords = $this->get('keywords')){
            $ul->filterByKeywords($keywords);
        }
        if(is_numeric($selectedGroup = $this->get('selectedGroup'))){
            $ul->filterByGroupID((integer)$selectedGroup);
        } elseif (empty($selectedGroup)) {
            $ul->filterByGroupID(key($relevant_groups));
        }
        $ul->sortByUserName();
        $this->set('userList', $ul->getResults());
        $this->set('ul', $ul);

        $site = Site::getSite();
        $balance = $site->getAttribute('balance');
        $this->set('balance', $balance);
    }
}
