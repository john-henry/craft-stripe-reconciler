# Release Notes for Stripe Reconciler

## 1.2.0 - 2026-11-27

### Added
- A `stripe-reconciler/reconcile/forget` command, so a payment that was closed wrongly can be looked at again without going into the database.

### Changed
- Counts in the utility and the notification email subject read "1 payment" or "3 payments" instead of "payment(s)".
- The utility no longer repeats its own title above the list.

### Fixed
- "Check them all" can no longer queue the same backlog twice before the page is reloaded.
- The Recent attempts table now fits a laptop-width window instead of hiding the Last checked column.
- A paid cart waiting for you now makes the console command exit non-zero.
- The console command keeps exiting non-zero while a payment needs you, including runs that skip it because it was checked recently.
- Checking a payment Commerce couldn't complete no longer lets the next automatic run try to complete it again.
- A payment Commerce hit an error completing now shows as "Could not complete", goes in the notification email, and waits for you instead of being retried.
- Audit rows for payments Stripe took money for are no longer cleared after the retention period when they recorded an error.

## 1.1.0 - 2026-09-25 [CRITICAL]

### Security
- Payments refunded or disputed in Stripe are no longer marked paid.

### Added
- A second successful payment on an order that's already paid is flagged as a possible double charge.
- Payments waiting on a bank transfer or voucher stay open while the customer can still pay.
- A run that starts while another is still going stops straight away.

### Changed
- Amounts are checked against what Commerce asked Stripe for, in the payment currency.
- A payment on an order whose total changed after it started is held for review.
- A settling payment on a completed order is left alone until Stripe settles it.
- A cart paid by a settling method is completed, and marked paid once Stripe settles.
- The notification email mentions each payment once, and again only if its outcome changes.
- Automatic runs leave a payment alone for its first fifteen minutes.
- Deleted orders, and authorised orders waiting to be captured, are left alone.
- Customer emails in the utility need Commerce's Manage orders permission.
- The audit trail no longer stores customer emails.
- Abandoned Checkout sessions, and payments Stripe has no record of, are closed instead of reported as errors every run.

### Fixed
- Payments still settling no longer add a transaction to the order on every run.
- A payment Commerce couldn't complete is no longer retried on every run; it waits for you in the control panel.
- Payments in a currency other than the store's are no longer held as amount mismatches.
- Authorised orders waiting to be captured are no longer reported as failures.
- Check Stripe no longer hides a paid attempt behind a later abandoned one.
- A dry run no longer holds off the next real run.
- Pruning no longer deletes the record of payments where money was taken.
- The utility and its badge are much quicker on busy stores.
- "Check them all" no longer times out on a large backlog.
- "Last checked" times show in the site's time zone.
- Large totals in currencies such as IDR are stored correctly.
- Uninstalling works on PostgreSQL.
- Button labels and messages in the utility can now be translated.
- Checking or reconciling a payment now announces the result to screen readers and keeps focus in a sensible place.
- The note warning that the history table is out of date is now announced when it appears.
- The payment tables now have proper names for screen readers.

## 1.0.0 - 2026-07-28

### Added
- Initial release.
