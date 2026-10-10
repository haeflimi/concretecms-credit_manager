<?php
namespace CreditManager\PaymentMethods;

use Concrete\Core\Http\Request;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use CreditManager\CreditManager;
use CreditManager\Entity\CreditRecord;
use CreditManager\Entity\PaymentEvent;
use Payrexx\Models\Request\Gateway;
use Payrexx\Models\Request\Transaction;
use Payrexx\Payrexx as PayrexxClient;
use Payrexx\PayrexxException;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die(_("Access Denied."));

/**
 * Top-ups through Payrexx.
 *
 * The webhook body is never trusted: only the transaction id is taken from it, everything else (status, amount,
 * currency, reference, contact) comes from the Payrexx API. Each notification is logged as a PaymentEvent, and
 * the transaction id is the idempotency key of the booking, so retries and repeated status notifications can
 * never credit an account twice.
 */
class Payrexx
{
    const REFERENCE_PREFIX = 'userbalance_';
    const SESSION_GATEWAY_KEY = 'credit_manager.payrexx_gateway';

    private static $payrexx;

    /**
     * @return PayrexxClient
     */
    public static function client()
    {
        if (self::$payrexx === null) {
            $instance = Config::get('credit_manager.payment_methods.payrexx.instance')
                ?: Config::get('payrexx.instance')
                ?: Config::get('community_store_payrexx.instanceName');
            $apiKey = Config::get('credit_manager.payment_methods.payrexx.apikey')
                ?: Config::get('payrexx.apikey')
                ?: Config::get('community_store_payrexx.secret');
            self::$payrexx = new PayrexxClient((string) $instance, (string) $apiKey);
        }
        return self::$payrexx;
    }

    public static function currency()
    {
        return (string) (Config::get('credit_manager.payment_methods.payrexx.currency') ?: 'CHF');
    }

    /**
     * Webhook endpoint (POST, form-encoded or JSON).
     */
    public function callback()
    {
        $request = Request::getInstance();
        $raw = (string) $request->getContent();
        $transaction = $request->request->get('transaction');
        if (!is_array($transaction)) {
            $decoded = json_decode($raw, true);
            $transaction = is_array($decoded) && isset($decoded['transaction']) ? $decoded['transaction'] : null;
        }
        $txId = is_array($transaction) && isset($transaction['id']) ? (int) $transaction['id'] : 0;
        if ($txId <= 0) {
            return new JsonResponse(['error' => 'No transaction id in the notification.'], 400);
        }
        $event = PaymentEvent::record(CreditManager::SOURCE_PAYREXX, (string) $txId, $raw !== '' ? $raw : json_encode($transaction));

        $existing = CreditManager::findByReference(CreditManager::SOURCE_PAYREXX, (string) $txId);
        if ($existing) {
            $event->finish('duplicate', $existing);
            return new JsonResponse(['status' => 'already booked'], 200);
        }

        try {
            $tx = $this->fetchTransaction($txId);
        } catch (PayrexxException $e) {
            $event->finish('error: lookup failed: ' . $e->getMessage());
            $this->log()->warning('Credit Manager: Payrexx transaction ' . $txId . ' could not be fetched: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Transaction could not be verified.'], 502);
        }
        if (!$tx) {
            $event->finish('ignored: unknown transaction');
            return new JsonResponse(['status' => 'ignored'], 200);
        }

        $status = (string) $tx->getStatus();
        if ($status !== 'confirmed') {
            $event->finish('ignored: status ' . $status);
            return new JsonResponse(['status' => 'ignored'], 200);
        }
        if (method_exists($tx, 'getCurrency') && $tx->getCurrency() && strcasecmp($tx->getCurrency(), self::currency()) !== 0) {
            $event->finish('ignored: currency ' . $tx->getCurrency());
            return new JsonResponse(['status' => 'ignored'], 200);
        }
        $amount = (int) $tx->getAmount();
        if ($amount <= 0) {
            $event->finish('ignored: amount ' . $amount);
            return new JsonResponse(['status' => 'ignored'], 200);
        }

        $uId = $this->resolveUser($tx);
        if (!$uId) {
            $event->finish('error: user not found');
            $this->log()->error('Credit Manager: Payrexx transaction ' . $txId . ' confirmed but no user matches it; book it by hand.');
            return new JsonResponse(['status' => 'user not found'], 200);
        }

        try {
            $now = new \DateTime('now');
            $record = CreditManager::addRecord(
                $uId,
                CreditRecord::normalizeAmount($amount / 100),
                'Überweisung per Payrexx vom ' . $now->format('d.m.Y H:i'),
                ['Payrexx'],
                CreditManager::SOURCE_PAYREXX,
                (string) $txId
            );
        } catch (\Throwable $e) {
            $event->finish('error: ' . $e->getMessage());
            $this->log()->error('Credit Manager: booking Payrexx transaction ' . $txId . ' failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Booking failed.'], 500);
        }
        $event->finish('booked', $record);
        return new JsonResponse(['status' => 'booked'], 200);
    }

    /**
     * Markup of the pay button. The gateway link is kept in the session and reused for a while, so reloading the
     * page does not create a new Payrexx gateway every time.
     *
     * @param User $user
     * @param float|string $amount in the account currency
     * @return string
     */
    public function getPaymentButton(User $user, $amount)
    {
        $cents = (int) round(((float) $amount) * 100);
        if ($cents <= 0) {
            return '';
        }
        $link = $this->getCachedGatewayLink($user, $cents);
        if (!$link) {
            try {
                $link = $this->createGateway($user, $cents);
                $this->cacheGatewayLink($user, $cents, $link);
            } catch (PayrexxException $e) {
                $this->log()->warning('Credit Manager: Payrexx gateway could not be created: ' . $e->getMessage());
                $link = null;
            }
        }
        if ($link) {
            return '<a class="btn btn-primary btn-payrexx-modal" href="' . h($link) . '" data-href="' . h($link) . '" target="_blank">Überweisung Starten</a>';
        }
        return '<p class="alert alert-danger">Something went wrong. - Gateway could not be created.</p>';
    }

    /**
     * @return \Payrexx\Models\Response\Transaction|null
     * @throws PayrexxException
     */
    private function fetchTransaction($txId)
    {
        $request = new Transaction();
        $request->setId($txId);
        $response = self::client()->getOne($request);
        return $response instanceof \Payrexx\Models\Response\Transaction ? $response : null;
    }

    /**
     * The user the transaction belongs to: by the reference the package put on the gateway, or, for gateways
     * created before references were used, by the contact e-mail reported by Payrexx.
     *
     * @return int
     */
    private function resolveUser(\Payrexx\Models\Response\Transaction $tx)
    {
        $reference = (string) $tx->getReferenceId();
        if ($reference === '') {
            $invoice = $tx->getInvoice();
            $reference = is_array($invoice) && isset($invoice['referenceId']) ? (string) $invoice['referenceId'] : '';
        }
        if (strpos($reference, self::REFERENCE_PREFIX) === 0) {
            return (int) substr($reference, strlen(self::REFERENCE_PREFIX));
        }
        $contact = $tx->getContact();
        $email = is_array($contact) && !empty($contact['email']) ? trim((string) $contact['email']) : '';
        if ($email !== '') {
            $ui = Application::getFacadeApplication()->make(UserInfoRepository::class)->getByEmail($email);
            if ($ui) {
                return (int) $ui->getUserID();
            }
        }
        return 0;
    }

    /**
     * @return string the gateway link
     * @throws PayrexxException
     */
    private function createGateway(User $user, $cents)
    {
        $ui = $user->getUserInfoObject();
        $gateway = new Gateway();
        $gateway->setAmount($cents);
        $gateway->setCurrency(self::currency());
        $gateway->setVatRate(null);
        $gateway->setSuccessRedirectUrl((string) Url::to('/account/edit_profile?success=true'));
        $gateway->setSkipResultPage(false);
        $gateway->setSku(self::REFERENCE_PREFIX . $user->getUserID());
        $gateway->setReferenceId(self::REFERENCE_PREFIX . $user->getUserID());
        $gateway->setPurpose(['Ausgleich Vereinskonto ' . $user->getUserName()]);
        $gateway->setPsp([]);
        if ($ui) {
            $gateway->addField('email', $ui->getUserEmail());
            $gateway->addField('forename', (string) $ui->getAttribute('billing_first_name'));
            $gateway->addField('surname', (string) $ui->getAttribute('billing_last_name'));
        }
        $gw = self::client()->create($gateway);
        return $gw ? (string) $gw->getLink() : '';
    }

    private function getCachedGatewayLink(User $user, $cents)
    {
        $session = Application::getFacadeApplication()->make('session');
        $cached = $session->get(self::SESSION_GATEWAY_KEY);
        $ttl = max(1, (int) Config::get('credit_manager.payment_methods.payrexx.gateway_reuse_minutes')) * 60;
        if (is_array($cached)
            && (int) $cached['uId'] === (int) $user->getUserID()
            && (int) $cached['cents'] === (int) $cents
            && !empty($cached['link'])
            && time() - (int) $cached['created'] < $ttl) {
            return $cached['link'];
        }
        return null;
    }

    private function cacheGatewayLink(User $user, $cents, $link)
    {
        if (!$link) {
            return;
        }
        Application::getFacadeApplication()->make('session')->set(self::SESSION_GATEWAY_KEY, [
            'uId' => (int) $user->getUserID(),
            'cents' => (int) $cents,
            'link' => $link,
            'created' => time(),
        ]);
    }

    private function log()
    {
        return Application::getFacadeApplication()->make('log');
    }
}
