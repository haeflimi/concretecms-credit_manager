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

## Migration to Community Store (retirement)

`tools/migrate_to_store.php` moves the whole ledger into Community Store and the balances into the
`community_store_credit` package (which must be installed first):

```
concrete/bin/concrete c5:exec packages/credit_manager/tools/migrate_to_store.php -- [--apply] [--cutover=YYYY-MM-DD] [--report-dir=DIR] [--limit=N]
```

- Without `--apply` nothing is written. The reports land in `data/migration/` (or `--report-dir`):
  `migration_report.csv` (one line per ledger row: kind, category, payment method, product, action),
  `possible_duplicates.csv` (existing store orders that look like a ledger row) and `reconciliation.csv`
  (per member: ledger sums vs. migrated orders, expected debt/credit).
- Every ledger row with a value becomes one paid, archived store order: customer = member (0 with the
  member id in the notes if the account is gone), order date and paid date = record timestamp,
  payment method = Payrexx / Paypal / Bar / Überweisung for top-ups (from the category, else from the
  comment) and "Vereinskonto" for charges, one item on a placeholder product per category in the product
  group "Vereinskonto (Archiv)" (inactive products, price 0), the comment as item name, the ledger id as
  transaction reference `cm:<Id>`, order attributes `cm_record_id`, `cm_kind`, `cm_archive`, `cm_user`.
- Each member's final balance: debt → one unpaid order "Offener Saldo Vereinskonto" (`cm:debt:<uId>`,
  status incomplete); surplus → store credit entry `migration:cm_balance:<uId>`. Members without an
  account get neither (listed in the reconciliation).
- The run is idempotent and resumable: rows whose transaction reference already exists are skipped, each
  member is written in one transaction. `--apply` ends with the reconciliation; exit code 1 means a DIFF.
- Category names come from the live topic tree; `credit_manager.migration.node_names` (array node id →
  name) overrides them, needed where the tree was re-imported and ids are stale.
- Zero-value rows are skipped and listed. Nothing is deleted from the ledger tables.

Production sequence: back up `cmCreditRecord`/`cmCreditRecordCategory`/`CommunityStoreOrders`/
`CommunityStoreOrderItems`, install `community_store_credit`, dry run, review the three CSVs, `--apply`,
check "Reconciliation OK", then disable the credit_manager pages/webhooks (uninstall the package; the
tables stay) and spot-check a few members in Store › Orders and Store › Store Credit.
