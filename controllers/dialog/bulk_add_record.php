<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use Concrete\Core\Controller\Controller;
use Concrete\Core\User\UserList;
use CreditManager\CreditManager;
use Config;
use URL;

class BulkAddRecord extends Controller
{
    protected $viewPath = 'dialogs/bulk_add_record';

    public function view()
    {
        $this->requireAsset('select2');
        $relevant_groups = Config::get('credit_manager.relevant_groups');
        $relevant_groups['all'] = 'Alle';
        $this->set('relevant_groups', $relevant_groups);
    }

    public function confirm()
    {
        $e = $this->validate($this->post(), 'bulkAddRecord');
        if ($e === true) {
            $value = $this->post('recordValue');
            $comment = $this->post('recordComment');
            $selectedGroup = $this->post('selectedGroup');

            $ul = new UserList();
            if (is_numeric($selectedGroup)) {
                $ul->filterByGroupID((integer)$selectedGroup);
            }
            $users = $ul->getResults();

            $count = 0;
            foreach ($users as $user) {
                CreditManager::addRecord($user, $value, $comment);
                $count++;
            }
            $this->flash('success', t('%d records added', $count));
        } else {
            $this->flash('error', $e);
        }
        $this->redirect(URL::to('/dashboard/credit_manager'));
    }

    public function validate($data, $action = false)
    {
        $errors = new \Concrete\Core\Error\Error();

        // we want to use a token to validate each call in order to protect from xss and request forgery
        $token = \Core::make("token");
        if ($action && !$token->validate($action)) {
            $errors->add('Invalid Request, token must be valid.');
        }

        if ($action == 'bulkAddRecord') {
            if (!is_numeric($data['recordValue'])) {
                $errors->add('No valid Record Value set.');
            }
            if (empty($data['recordComment'])) {
                $errors->add('You need to set a comment for the Record.');
            }
            if (empty($data['selectedGroup'])) {
                $errors->add('You need to select a group.');
            }
        }

        if ($errors->has()) {
            return $errors;
        }

        return true;
    }
}
