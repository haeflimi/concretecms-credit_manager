<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\User\User;
use CreditManager\Controller\DashboardDialog;
use CreditManager\CreditManager;
use CreditManager\Service\CategoryResolver;
use Core;
use URL;

class AddRecord extends DashboardDialog
{
    protected $viewPath = 'dialogs/add_record';

    public function view($uId)
    {
        $this->requireAsset('select2');
        $this->set('categoryTree', CategoryResolver::getTree());
        $this->set('categoryTreeNodes', CategoryResolver::getSelectableTopics());
        $this->set('uId', (int) $uId);
        $this->set('bookingId', uniqid('manual_', true));
    }

    public function confirm()
    {
        $e = $this->validate($this->post(), 'addRecord');
        if ($e === true) {
            $uId = (int) $this->post('recordUid');
            $categories = $this->post('selectedCategories');
            $bookingId = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $this->post('bookingId'));
            $record = CreditManager::addRecord(
                $uId,
                $this->post('recordValue'),
                $this->post('recordComment'),
                is_array($categories) ? $categories : [],
                CreditManager::SOURCE_MANUAL,
                $bookingId !== '' ? $bookingId : null
            );
            $this->flash('success', t('Record %s added for user %d', $record->getValueString(), $uId));
        } else {
            $this->flash('error', $e);
        }
        $this->redirect(URL::to('/dashboard/credit_manager'));
    }

    /**
     * @return ErrorList|true
     */
    public function validate($data, $action = false)
    {
        $errors = new ErrorList();

        if ($action && !Core::make('token')->validate($action)) {
            $errors->add(t('Invalid Request, token must be valid.'));
        }
        if (!$this->canAccess()) {
            $errors->add(t('Access Denied'));
        }

        if ($action == 'addRecord') {
            if (!isset($data['recordValue']) || !is_numeric($data['recordValue'])) {
                $errors->add(t('No valid Record Value set.'));
            }
            if (empty($data['recordComment'])) {
                $errors->add(t('You need to set a comment for the Record.'));
            }
            $uId = isset($data['recordUid']) ? (int) $data['recordUid'] : 0;
            if ($uId <= 0 || !User::getByUserID($uId)) {
                $errors->add(t('Unknown user.'));
            }
        }

        if ($errors->has()) {
            return $errors;
        }

        return true;
    }
}
