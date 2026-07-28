<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * Configured through `config/stripe-reconciler.php`. There is no control panel
 * settings screen in this release.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class SettingsModel extends Model
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int How many days back to look for unresolved Stripe transactions.
     */
    public int $lookbackDays = 14;

    /**
     * @var bool Whether to complete orders that are still carts when Stripe
     *           confirms the payment succeeded.
     *
     * Off by default. Turning this on means the plugin can bring an order into
     * existence that a customer never saw confirmed, which is usually the right
     * outcome (they were charged) but is a decision each store should make
     * deliberately rather than inherit.
     */
    public bool $reconcileCarts = false;

    /**
     * @var int Permitted difference, in minor units, between the amount Stripe
     *          received and the order total before the payment is held back for
     *          manual review.
     */
    public int $amountToleranceMinorUnits = 0;

    /**
     * @var int How many days an unpaid payment is given before it is treated as
     *          finished and stops being re-checked.
     *
     * A checkout nobody completed does not come back to life. Without this every
     * dead payment would be looked up at Stripe again on every run, for as long as
     * it stayed inside the lookback window, which on a busy store is most of the
     * work the plugin does and none of the value.
     */
    public int $settledAfterDays = 1;

    /**
     * @var int Minimum gap, in minutes, between two automatic checks of the same
     *          payment.
     *
     * Bounds how often a cron re-asks Stripe about something it has already asked
     * about. Explicit requests, a named order on the command line or a button in
     * the control panel, ignore this.
     */
    public int $recheckAfterMinutes = 60;

    /**
     * @var int How many days to keep audit rows for payments that were never paid.
     *
     * Rows recording a completed reconciliation are kept indefinitely whatever
     * this is set to: money actually moved, and that is the evidence for it. Only
     * rows saying a payment never happened are pruned, since a note that somebody
     * abandoned a checkout last winter is worth nothing.
     *
     * Never applied more aggressively than `lookbackDays`. Pruning a retired row
     * while its transaction is still inside the lookback window would only cause
     * the payment to be rediscovered and checked all over again.
     */
    public int $auditRetentionDays = 90;

    /**
     * @var string Address told when a run finds money at Stripe against an order
     *             that is not finished. Empty disables notifications.
     *
     * One digest per run, never one mail per payment, and only from unattended
     * runs. Somebody working in the control panel is already looking at the answer.
     */
    public string $notificationEmail = '';

    /**
     * @var int[] Gateway IDs to restrict reconciliation to. Empty means every
     *            Stripe gateway.
     */
    public array $enabledGateways = [];

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<int, array<int, mixed>> The validation rules.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['reconcileCarts'], 'boolean'];
        $rules[] = [['lookbackDays'], 'integer', 'min' => 1];
        $rules[] = [['amountToleranceMinorUnits'], 'integer', 'min' => 0];
        $rules[] = [['settledAfterDays'], 'integer', 'min' => 0];
        $rules[] = [['recheckAfterMinutes'], 'integer', 'min' => 0];
        $rules[] = [['auditRetentionDays'], 'integer', 'min' => 1];
        $rules[] = [['notificationEmail'], 'string'];
        $rules[] = [['notificationEmail'], 'email', 'skipOnEmpty' => true];
        $rules[] = [['enabledGateways'], 'each', 'rule' => ['integer']];

        return $rules;
    }
}
