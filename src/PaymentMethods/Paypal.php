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
use UserAttributeKey;
use Log;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;

defined('C5_EXECUTE') or die(_("Access Denied."));

class Paypal
{
    /**
     * Returns PayPal HTTP client instance with environment that has access
     * credentials context. Use this instance to invoke PayPal APIs, provided the
     * credentials have access.
     */
    public static function client()
    {
        return new PayPalHttpClient(self::environment());
    }

    /**
     * Set up and return PayPal PHP SDK environment with PayPal access credentials.
     * This sample uses SandboxEnvironment. In production, use ProductionEnvironment.
     */
    public static function environment()
    {
        if (Config::get('credit_manager.pacment_methods.paypal.environment') == 'sandbox') {
            $clientId = Config::get('credit_manager.pacment_methods.paypal.sandbox_client_id');
            $clientSecret = Config::get('credit_manager.pacment_methods.paypal.sandbox_client_secret');
            return new SandboxEnvironment($clientId, $clientSecret);
        } elseif (Config::get('credit_manager.pacment_methods.paypal.environment') == 'production') {
            $clientId = Config::get('credit_manager.pacment_methods.paypal.client_id');
            $clientSecret = Config::get('credit_manager.pacment_methods.paypal.client_secret');
            return new ProductionEnvironment($clientId, $clientSecret);
        } else {
            return false;
        }
    }

    /**
     * Callback for the Paypal Webhook
     * @param $html
     * @param $nl
     * @param bool|false $recipient
     * @return mixed
     */
    public function callback()
    {
        $req = Request::getInstance();
        if (empty($req->getContent())) {
            http_response_code(500);
            echo 'No valid Request Information';
            exit;
        }
        $requestData = json_decode($req->getContent());
        $userID = base64_decode($requestData->resource->custom);
        $amount = $requestData->resource->amount->total;
        if (
            // Validate the Webhook Request
            $requestData->resource_type == "sale" &&
            $requestData->event_type == 'PAYMENT.SALE.COMPLETED' &&
            is_numeric($userID) &&
            is_numeric($amount)
        ) {
            $user = User::getByUserID($userID);
            if ($this->updateBalance($user, $amount)) {
                http_response_code(200);
                exit;
            } else {
                http_response_code(500);
                echo 'Something went wrong when setting the payment status';
                exit;
            }
        } else {
            http_response_code(500);
            echo 'No valid Request Information';
            exit;
        }
    }

    /**
     * Verify a payment from paypal with our own ajax call
     * @param $html
     * @param $nl
     * @param bool|false $recipient
     * @return mixed
     */
    public function verify()
    {
        $req = Request::getInstance();
        $data = json_decode($req->getContent());
        $client = Paypal::client();
        $response = $client->execute(new OrdersGetRequest($data->orderID));
        /**
         *Enable the following line to print complete response as JSON.
         */
        //print json_encode($response->result);
        //print "Status Code: {$response->statusCode}\n";
        //print "Status: {$response->result->status}\n";
        //print "Order ID: {$response->result->id}\n";
        //print "Intent: {$response->result->intent}\n";
        //print "Links:\n";
        //foreach($response->result->links as $link)
        {
            //print "\t{$link->rel}: {$link->href}\tCall Type: {$link->method}\n";
        }
        if ($response->result->status == 'COMPLETED') {
            // 4. Save the transaction in your database. Implement logic to save transaction to your database for future reference.
            $paymentHandle = explode('-', base64_decode($response->result->purchase_units[0]->custom_id))[0];
            $userID = explode('-', base64_decode($response->result->purchase_units[0]->custom_id))[1];

            if ($paymentHandle == 'tgc_balance') {
                $this->updateBalance(User::getByUserID($userID), $response->result->purchase_units[0]->amount->value);
            } else {
                $this->setEventPaid(User::getByUserID($userID), $paymentHandle);
            }
            $success = t('Paypal: Payment verification successful').' - status:'.$response->result->status.' - paymentHandle:'.$paymentHandle.' - UserID:'.$userID.' - orderID:'.$response->result->id;
            Log::addNotice($success);
            echo json_encode($success);
        } else {
            $error = t('Paypal: Payment Verification Failed - ').' - status:'.$response->result->status.' - orderID:'.$response->result->id;
            Log::addError($error);
            echo json_encode($error);
        }
        die;
    }

    private function setEventPaid($user, $lanHandle)
    {
        $cl = new CurrentLan();
        $g = $cl->getParticipantGroup();
        $ui = $user->getUserInfoObject();
        $ak = UserAttributeKey::getByHandle($cl->getLANHandle() . '_paid');
        if (is_object($ak) && is_object($user) && $cl->getLANHandle() == $lanHandle && is_object($g)) {
            $cl->addParticipant($user);
            $ui->setAttribute($cl->getLANHandle() . '_paid', true);
        } else {
            throw new Exception('Something went wrong when adding User to Event participants.');
        }
    }

    private function updateBalance($user, $amount)
    {
        if (is_object($user) && is_numeric($amount)) {
            $now = new \DateTime('now');
            CreditManager::addRecord($user, $amount, 'Überweisung per Paypal vom '.$now->format('d.m.Y h:i'), ['Paypal']);
            return true;
        } else {
            throw new Exception('Something went wrong when updating the User balance!');
        }
    }

}