<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\enums;

use Craft;

/**
 * Classification of an order carrying an unresolved Stripe transaction.
 *
 * Discovery classifies rather than filters, so an abandoned cart carrying a real
 * payment is reported rather than dropped.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum CandidateType: string
{
    /**
     * The order was completed but Commerce still believes it is unpaid.
     */
    case UnpaidCompletedOrder = 'unpaidCompletedOrder';

    /**
     * The order is still a cart. The customer may have been charged without any
     * order ever appearing in the control panel.
     */
    case AbandonedCart = 'abandonedCart';

    /**
     * The order is already paid. Nothing to reconcile.
     */
    case AlreadyPaid = 'alreadyPaid';

    /**
     * The transaction references an order that no longer exists.
     */
    case OrderMissing = 'orderMissing';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns whether this classification is worth attempting to reconcile.
     *
     * @return bool True if the candidate should be inspected against Stripe.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::UnpaidCompletedOrder, self::AbandonedCart => true,
            self::AlreadyPaid, self::OrderMissing => false,
        };
    }

    /**
     * Returns a short human readable label for console output.
     *
     * @return string The label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function label(): string
    {
        return Craft::t('stripe-reconciler', match ($this) {
            self::UnpaidCompletedOrder => 'Unpaid completed order',
            self::AbandonedCart => 'Abandoned cart',
            self::AlreadyPaid => 'Already paid',
            self::OrderMissing => 'Order missing',
        });
    }
}
