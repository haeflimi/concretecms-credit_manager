<?php
namespace CreditManager\PaymentMethods;

use Application\Turicane\CurrentLan;
use Concrete\Flysystem\Exception;
use Concrete\Core;
use Concrete\Core\Http\Request;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\User\User;
use CreditManager\CreditManager;
use CreditManager\Entity\CreditRecord;
use Payrexx\Models\Request\Transaction;
use Symfony\Component\HttpFoundation\JsonResponse;
use UserAttributeKey;
use Log;
use Payrexx\Payrexx as PayrexxClient;
use Payrexx\Models\Request\Gateway;
use Concrete\Core\Support\Facade\Url;

defined('C5_EXECUTE') or die(_("Access Denied."));

class Payrexx
{
    private static $payrexx;
    const CURRENCY = 'CHF';
    const PSP = [];

    /**
     * Get Payrexx Client
     */
    public static function client()
    {
        return self::getPayrexxClient();
    }


    /**
     * Callback for the Payrexx Webhook
     */
    public function callback()
    {
        $request = Request::getInstance();
        $data = json_decode($request->getContent());
        $email = $data->transaction->contact->email;
        $user = Core\User\UserInfo::getByEmail($email);
        $status = $data->transaction->status;
        $id = $data->transaction->id;
        $amount = $data->transaction->amount;
        if(!$this->verify($data->transaction->id, $data->transaction->amount)){
            return JsonResponse::fromJsonString('Payrexx Transaction could not be verified.', 500);
        }
        if(!$user){
            return JsonResponse::fromJsonString('Transaction User could not be determined.', 500);
        }
        if($status != 'confirmed'){
            return JsonResponse::fromJsonString('Not the correct Status to be processed.', 500);
        }
        try {
            $this->updateBalance($user, $amount/100);
        } catch (Exception $e) {
            \Log::addError('Error processing Payrexx Webhook Call. Transaction ID: '.$id. 'Error: ' . $e->getMessage());
            return new JsonResponse(['error' => ['message' => $e->getMessage()]], 500);
        }

        return new JsonResponse('Payment successfully processed', 200);
    }

    /**
     * Verify a payment from Payrexx call
     */
    public function verify(int $invoiceId, int $amount)
    {
        $payrexx = self::client();
        $obj = new Transaction();
        $obj->setId($invoiceId);
        try {
            $response = $payrexx->getOne($obj);
            if(($response->getStatus() == 'confirmed' || $response->getStatus() == 'authorized') && $response->getAmount() == $amount){
                return true;
            } else {
                return false;
            }
        } catch (\Payrexx\PayrexxException $e) {
            \Concrete\Core\Support\Facade\Log::addWarning($e->getMessage());
            return false;
        }
    }

    /**
     * Get the complete Markup for the Payment button with the Payrexx Payment Method
     */
    public function getPaymentButton(User $user, $amount)
    {
        $gateway = new Gateway();
        $gateway->setAmount($amount * 100);
        $gateway->setCurrency(self::CURRENCY);
        $gateway->setVatRate(null);
        $gateway->setSuccessRedirectUrl(Url::to('/account/edit_profile?success=true'));
        $gateway->setSkipResultPage(false);
        $gateway->setSku('userbalance_'.$user->getUserID());
        $gateway->setPurpose(['Ausgleich Vereinskonto '.$user->getUserName()]);
        $gateway->setPsp(self::PSP);
        $gateway->addField('email', $user->getUserInfoObject()->getUserEmail());
        $gateway->addField('forename', $user->getUserInfoObject()->getAttribute('user_firstname'));
        $gateway->addField('surname', $user->getUserInfoObject()->getAttribute('user_lastname'));
        try {
            $gw = self::client()->create($gateway);
        } catch (\Payrexx\PayrexxException $e) {
            Log::addWarning($e->getMessage());
            $gw = false;
        }

        if($gw){
            return '<a class="btn btn-primary btn-payrexx-modal"  href="'.$gw->getLink().'" target="_blank" ">Überweisung Starten</a>';
        } else {
            return '<p class="alert alert-danger">Something went wrong. - Gateway could not be created.</p>';
        }
    }


    private function setEventPaid($user, $lanHandle)
    {
        throw new Exception('Event Payment not implemented yet.');
    }

    private function updateBalance($user, $amount)
    {
        if (is_object($user) && is_numeric($amount)) {
            $now = new \DateTime('now');
            CreditManager::addRecord($user, $amount, 'Überweisung per Payrexx vom '.$now->format('d.m.Y h:i'), ['Payrexx']);
            return true;
        } else {
            throw new Exception('Something went wrong when updating the User balance!');
        }
    }

    /**
     * Get gateway
     * @param int $id ID
     * @return \Application\Payrexx\Models\Response\Gateway|null Gateway response
     */
    public function getGateway(int $id) {
        try {
            $gateway = new Gateway();
            $gateway->setId($id);
            return self::getPayrexx()->getOne($gateway);
        } catch (PayrexxException $e) {
            error_log($e->getTraceAsString());
            return null;
        }
    }

    /**
     * Get Payrexx instance
     * @return Payrexx
     */
    public static function getPayrexxClient() {
        if (self::$payrexx == null) {
            self::$payrexx = new PayrexxClient(\Config::get('payrexx.instance'), \Config::get('payrexx.apikey'));
        }
        return self::$payrexx;
    }
}