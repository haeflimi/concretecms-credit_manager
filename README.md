# credit_manager

Concrete CMS package that gives every user a credit account ("Vereinskonto"): an append-only ledger
(`cmCreditRecord`) whose sum is the balance. Money enters through Payrexx (and optionally PayPal) top-ups
or manual dashboard bookings and leaves through the POS pages, catering order deliveries or manual bookings.

## Guarantees (since 1.5.0)

- Every booking carries a `source` + `externalRef` pair under a unique index: a payment notification, a POS
  cart, an order delivery or a dashboard submission is booked at most once, even when retried or sent twice.
- Bookings and their categories are written in one database transaction; a POS checkout and the matching
  Community Store order succeed or fail together.
- Amounts are stored as DECIMAL(12,2) and summed by the database.
- Payment notifications are logged in `cmPaymentEvent` before processing (audit trail for reconciliation).
  Payrexx: only the transaction id is taken from the webhook, everything else comes from the Payrexx API.
  PayPal: disabled unless `payment_methods.paypal.enabled` and `webhook_id` are set; every notification is
  verified through PayPal's verify-webhook-signature API.
- The dashboard dialogs, the catering POS and the order desk require permission to view the
  `/dashboard/credit_manager` page. The self-service POS relies on its page permissions and never lists
  other people's badge ids.
- Records are never deleted or edited; corrections are new records.

## Configuration

Copy the keys you need from `config/credit_manager.php` to `application/config/credit_manager.php`.
`categories_topic` is set automatically on install. Without `Application\Turicane\CurrentLan` the event the
POS pages sell for comes from `event.page_id`, `event.title` and `event.participant_group_id`.
The full-screen pages use the theme template `fullscreen_template` (default `blank.php`), which must output
`$innerContent`.

## Upgrading from 1.4.x

The upgrade converts `value`/`price` from FLOAT to DECIMAL, removes category rows without a record (they
would block the new foreign key) and logs the record count and total before and after the schema change
("Credit Manager: ledger verified after upgrade"). Back up `cmCreditRecord` and `cmCreditRecordCategory`
before upgrading a live site anyway.
