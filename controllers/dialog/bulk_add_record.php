<?php

namespace Concrete\Package\CreditManager\Controller\Dialog;

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\User\UserList;
use CreditManager\Controller\DashboardDialog;
use CreditManager\CreditManager;
use Config;
use Core;
use Symfony\Component\HttpFoundation\JsonResponse;
use URL;

/**
 * Books the same amount for every user of a group. The dialog shows how many users are affected before the
 * clerk confirms, and the submission carries a batch id, so a double submit books every user once.
 */
class BulkAddRecord extends DashboardDialog
{
    protected $viewPath = 'dialogs/bulk_add_record';

    public function view()
    {
        $this->requireAsset('select2');
        $this->set('relevant_groups', $this->getGroups());
        $this->set('batchId', uniqid('bulk_', true));
        $this->set('countUrl', (string) URL::to('/ccm/credit_manager/bulk_add_record/count'));
    }

    /**
     * GET ?selectedGroup=… → {"count": n}
     */
    public function count()
    {
        if (!$this->canAccess()) {
            return new JsonResponse(['error' => 'Access Denied'], 403);
        }
        $group = $this->request->query->get('selectedGroup');
        if (!array_key_exists((string) $group, $this->getGroups())) {
            return new JsonResponse(['count' => 0]);
        }
        return new JsonResponse(['count' => count($this->getUsers($group))]);
    }

    public function confirm()
    {
        $e = $this->validate($this->post(), 'bulkAddRecord');
        if ($e === true) {
            $value = $this->post('recordValue');
            $comment = $this->post('recordComment');
            $selectedGroup = $this->post('selectedGroup');
            $batchId = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $this->post('batchId'));

            $count = 0;
            $skipped = 0;
            foreach ($this->getUsers($selectedGroup) as $user) {
                $uId = (int) $user->getUserID();
                $existing = CreditManager::findByReference(CreditManager::SOURCE_BULK, $batchId . ':' . $uId);
                if ($existing) {
                    $skipped++;
                    continue;
                }
                CreditManager::addRecord($user, $value, $comment, [], CreditManager::SOURCE_BULK, $batchId . ':' . $uId);
                $count++;
            }
            $message = t('%d records added', $count);
            if ($skipped) {
                $message .= ' ' . t('(%d already booked by this batch)', $skipped);
            }
            $this->flash('success', $message);
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

        if ($action == 'bulkAddRecord') {
            if (!isset($data['recordValue']) || !is_numeric($data['recordValue'])) {
                $errors->add(t('No valid Record Value set.'));
            }
            if (empty($data['recordComment'])) {
                $errors->add(t('You need to set a comment for the Record.'));
            }
            if (empty($data['selectedGroup']) || !array_key_exists((string) $data['selectedGroup'], $this->getGroups())) {
                $errors->add(t('You need to select a group.'));
            }
            if (empty($data['batchId'])) {
                $errors->add(t('Missing batch id, please reopen the dialog.'));
            }
        }

        if ($errors->has()) {
            return $errors;
        }

        return true;
    }

    private function getGroups()
    {
        $groups = Config::get('credit_manager.relevant_groups');
        $groups = is_array($groups) ? $groups : [];
        $groups['all'] = 'Alle';
        return $groups;
    }

    /**
     * @return \Concrete\Core\User\UserInfo[]
     */
    private function getUsers($selectedGroup)
    {
        $ul = new UserList();
        if (is_numeric($selectedGroup)) {
            $ul->filterByGroupID((int) $selectedGroup);
        }
        return $ul->getResults();
    }
}
