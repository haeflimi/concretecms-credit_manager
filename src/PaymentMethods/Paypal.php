<?php

namespace CreditManager\PaymentMethods;

use Concrete\Core\Http\Request;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Config;
use CreditManager\CreditManager;
use CreditManager\Entity\CreditRecord;
use CreditManager\Entity\PaymentEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die(_("Access Denied."));

/**
 * Top-ups through PayPal webhooks.
 *
 * Disabled unless `credit_manager.payment_methods.paypal.enabled` is true and a webhook id is configured.
 * Every notification is verified with PayPal's verify-webhook-signature API before anything is booked, each
 * notification is logged as a PaymentEvent and the PayPal resource id is the idempotency key of the booking.
 * The former client-side "verify" endpoint (a user posting an order id) was removed: it needed an SDK that is
 * not installed and let the client decide what gets booked.
 */
class Paypal
{
    const CUSTOM_PREFIX = 'tgc_balance';

    public static function config($key, $default = null)
    {
        $value = Config::get('credit_manager.payment_methods.paypal.' . $key);
        return $value === null ? $default : $value;
    }

    public static function isEnabled()
    {
        return (bool) self::config('enabled', false) && self::config('webhook_id', '') !== '';
    }

    public static function apiBase()
    {
        return self::config('environment') === 'production' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * @return array [clientId, clientSecret]
     */
    public static function credentials()
    {
        if (self::config('environment') === 'production') {
            return [(string) self::config('client_id'), (string) self::config('client_secret')];
        }
        return [(string) self::config('sandbox_client_id'), (string) self::config('sandbox_client_secret')];
    }

    /**
     * Webhook endpoint (POST, JSON).
     */
    public function callback()
    {
        if (!self::isEnabled()) {
            return new JsonResponse(['error' => 'PayPal payments are not enabled.'], 403);
        }
        $request = Request::getInstance();
        $raw = (string) $request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['event_type']) || !isset($data['resource']) || !is_array($data['resource'])) {
            return new JsonResponse(['error' => 'No valid request information.'], 400);
        }
        $resource = $data['resource'];
        $resourceId = isset($resource['id']) ? (string) $resource['id'] : null;
        $event = PaymentEvent::record(CreditManager::SOURCE_PAYPAL, $resourceId, $raw);

        if (!$this->verifySignature($request, $data)) {
            $event->finish('error: signature verification failed');
            $this->log()->warning('Credit Manager: PayPal webhook signature could not be verified (event ' . ($data['id'] ?? '?') . ').');
            return new JsonResponse(['error' => 'Signature verification failed.'], 400);
        }

        $eventType = (string) $data['event_type'];
        if (!in_array($eventType, ['PAYMENT.SALE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
            $event->finish('ignored: ' . $eventType);
            return new JsonResponse(['status' => 'ignored'], 200);
        }
        if ($resourceId === null || $resourceId === '') {
            $event->finish('error: no resource id');
            return new JsonResponse(['error' => 'No resource id.'], 400);
        }
        $existing = CreditManager::findByReference(CreditManager::SOURCE_PAYPAL, $resourceId);
        if ($existing) {
            $event->finish('duplicate', $existing);
            return new JsonResponse(['status' => 'already booked'], 200);
        }

        $amount = $this->extractAmount($resource);
        $uId = $this->extractUserId($resource);
        if ($amount === null || $amount <= 0) {
            $event->finish('ignored: amount');
            return new JsonResponse(['status' => 'ignored'], 200);
        }
        if (!$uId) {
            $event->finish('error: user not found');
            $this->log()->error('Credit Manager: PayPal payment ' . $resourceId . ' completed but no user matches it; book it by hand.');
            return new JsonResponse(['status' => 'user not found'], 200);
        }

        try {
            $now = new \DateTime('now');
            $record = CreditManager::addRecord(
                $uId,
                CreditRecord::normalizeAmount($amount),
                'Überweisung per Paypal vom ' . $now->format('d.m.Y H:i'),
                ['Paypal'],
                CreditManager::SOURCE_PAYPAL,
                $resourceId
            );
        } catch (\Throwable $e) {
            $event->finish('error: ' . $e->getMessage());
            $this->log()->error('Credit Manager: booking PayPal payment ' . $resourceId . ' failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Booking failed.'], 500);
        }
        $event->finish('booked', $record);
        return new JsonResponse(['status' => 'booked'], 200);
    }

    /**
     * Asks PayPal whether the notification really came from them.
     */
    private function verifySignature(Request $request, array $webhookEvent)
    {
        $headers = $request->headers;
        $body = [
            'auth_algo' => (string) $headers->get('PAYPAL-AUTH-ALGO'),
            'cert_url' => (string) $headers->get('PAYPAL-CERT-URL'),
            'transmission_id' => (string) $headers->get('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => (string) $headers->get('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => (string) $headers->get('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => (string) self::config('webhook_id'),
            'webhook_event' => $webhookEvent,
        ];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $required) {
            if ($body[$required] === '') {
                return false;
            }
        }
        try {
            $token = $this->accessToken();
            if (!$token) {
                return false;
            }
            $client = Application::getFacadeApplication()->make('http/client');
            $response = $client->post(self::apiBase() . '/v1/notifications/verify-webhook-signature', [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                'body' => json_encode($body),
                'timeout' => 15,
            ]);
            $result = json_decode((string) $response->getBody(), true);
            return is_array($result) && isset($result['verification_status']) && $result['verification_status'] === 'SUCCESS';
        } catch (\Throwable $e) {
            $this->log()->warning('Credit Manager: PayPal signature verification request failed: ' . $e->getMessage());
            return false;
        }
    }

    private function accessToken()
    {
        list($clientId, $clientSecret) = self::credentials();
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }
        $client = Application::getFacadeApplication()->make('http/client');
        $response = $client->post(self::apiBase() . '/v1/oauth2/token', [
            'auth' => [$clientId, $clientSecret],
            'form_params' => ['grant_type' => 'client_credentials'],
            'timeout' => 15,
        ]);
        $result = json_decode((string) $response->getBody(), true);
        return is_array($result) && !empty($result['access_token']) ? (string) $result['access_token'] : null;
    }

    /**
     * @return float|null
     */
    private function extractAmount(array $resource)
    {
        if (isset($resource['amount']['total']) && is_numeric($resource['amount']['total'])) {
            return (float) $resource['amount']['total'];
        }
        if (isset($resource['amount']['value']) && is_numeric($resource['amount']['value'])) {
            return (float) $resource['amount']['value'];
        }
        return null;
    }

    /**
     * The user id the checkout button put into the custom field: a plain id or base64("tgc_balance-<id>").
     *
     * @return int
     */
    private function extractUserId(array $resource)
    {
        $custom = $resource['custom'] ?? $resource['custom_id'] ?? '';
        $custom = trim((string) $custom);
        if ($custom === '') {
            return 0;
        }
        $candidates = [$custom];
        $decoded = base64_decode($custom, true);
        if ($decoded !== false) {
            $candidates[] = $decoded;
        }
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
            $parts = explode('-', $candidate);
            if (count($parts) === 2 && $parts[0] === self::CUSTOM_PREFIX && is_numeric($parts[1])) {
                return (int) $parts[1];
            }
        }
        return 0;
    }

    private function log()
    {
        return Application::getFacadeApplication()->make('log');
    }
}
