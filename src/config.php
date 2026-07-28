<?php

/**
 * Stripe Reconciler config.
 *
 * Copy this to `config/stripe-reconciler.php` in your project and edit as needed.
 *
 * @copyright Copyright (c) John Henry Donovan
 */

return [
    // How many days back to look for unresolved Stripe transactions.
    'lookbackDays' => 14,

    // Whether unattended runs (the console command and cron) may complete orders
    // that are still carts when Stripe confirms the payment succeeded.
    //
    // Does not restrict the control panel: anyone with the reconcile permission can
    // always complete a cart from the utility page.
    'reconcileCarts' => false,

    // Permitted difference, in minor units, between the amount Stripe received
    // and the order total. Anything beyond this is held back for manual review
    // rather than completed automatically.
    'amountToleranceMinorUnits' => 0,

    // How many days an unpaid payment is given before it stops being checked.
    // A cancelled payment is retired immediately; one still settling is never
    // retired.
    'settledAfterDays' => 1,

    // Minimum gap, in minutes, between two automatic checks of the same payment.
    // Explicit requests (a named order, or a control panel button) ignore it.
    'recheckAfterMinutes' => 60,

    // How many days to keep audit rows for payments that were never paid.
    // Reconciled rows are kept indefinitely. Pruning runs as part of Craft's
    // garbage collection and never applies a cutoff shorter than lookbackDays.
    'auditRetentionDays' => 90,

    // Notified when a run finds money at Stripe against an unfinished order. One
    // digest per run, from the console command only. Nothing is sent about
    // checkouts where no money was taken. Leave empty to disable.
    'notificationEmail' => '',

    // Gateway IDs to restrict reconciliation to. Leave empty for every Stripe
    // gateway on the store.
    'enabledGateways' => [],
];
