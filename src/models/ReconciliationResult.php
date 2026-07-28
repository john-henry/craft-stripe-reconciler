<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\models;

use craft\base\Model;
use johnhenry\stripereconciler\enums\Outcome;

/**
 * The result of reconciling a single Commerce transaction against Stripe.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconciliationResult extends Model
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The Commerce transaction that was inspected.
     */
    public int $transactionId = 0;

    /**
     * @var Outcome What happened.
     */
    public Outcome $outcome = Outcome::Errored;

    /**
     * @var string Detail for the audit trail and console output.
     */
    public string $message = '';

    /**
     * @var string|null The Stripe PaymentIntent that was inspected.
     */
    public ?string $paymentIntentId = null;

    /**
     * @var string|null The PaymentIntent status reported by Stripe.
     */
    public ?string $stripeStatus = null;

    /**
     * @var int|null The amount Stripe received, in minor units.
     */
    public ?int $stripeAmountReceived = null;

    /**
     * @var int|null The order total, in minor units.
     */
    public ?int $orderTotalMinorUnits = null;

    /**
     * @var string|null The currency the payment was taken in.
     */
    public ?string $currency = null;

    /**
     * @var string|null The order reference after the attempt.
     *
     * Completing a cart is what assigns the reference, so the order loaded at the
     * start of the attempt has none.
     */
    public ?string $orderReference = null;

    /**
     * @var string|null The order short number as it stands after the attempt.
     */
    public ?string $orderShortNumber = null;
}
