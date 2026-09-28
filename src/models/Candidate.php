<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use johnhenry\stripereconciler\enums\CandidateType;

/**
 * An order carrying one or more unresolved Stripe transactions.
 *
 * Produced by discovery, consumed by reconciliation.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Candidate extends Model
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The Commerce order ID.
     */
    public int $orderId = 0;

    /**
     * @var Order|null The order, or null if it no longer exists.
     */
    public ?Order $order = null;

    /**
     * @var CandidateType How the order was classified.
     */
    public CandidateType $type = CandidateType::OrderMissing;

    /**
     * @var int[] IDs of the unresolved Stripe transactions on this order, oldest first.
     */
    public array $transactionIds = [];

    /**
     * @var int|null The gateway the transactions belong to.
     */
    public ?int $gatewayId = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the best available identifier: reference, then short number, then
     * element ID.
     *
     * A cart has no reference, since Commerce assigns one on completion.
     *
     * @return string A human readable identifier for console output and audit rows.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getLabel(): string
    {
        if ($this->order === null) {
            return '#' . $this->orderId;
        }

        if ($this->order->reference !== null) {
            return $this->order->reference;
        }

        if ($this->order->number !== null) {
            return $this->order->getShortNumber();
        }

        return '#' . $this->orderId;
    }
}
