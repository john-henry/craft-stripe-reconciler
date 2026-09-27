<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\records;

use craft\db\ActiveRecord;

/**
 * Audit record for a single reconciliation attempt.
 *
 * One row per Commerce transaction, updated in place on each attempt. The unique
 * index on `transactionId` makes idempotency a property of the schema.
 *
 * Order reference and short number are denormalised so the row survives the
 * order being deleted, which cascades its transactions away. `orderId` is nulled
 * rather than cascaded.
 *
 * @property int $id
 * @property int|null $orderId
 * @property int $transactionId
 * @property int|null $gatewayId
 * @property string|null $paymentIntentId
 * @property string|null $orderReference
 * @property string|null $orderShortNumber
 * @property string $candidateType
 * @property string $outcome
 * @property string|null $stripeStatus
 * @property int|null $stripeAmountReceived
 * @property int|null $orderTotalMinorUnits
 * @property string|null $currency
 * @property int $attempts
 * @property string|null $message
 * @property string|null $notifiedOutcome
 * @property string $dateLastAttempt
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Reconciliation extends ActiveRecord
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The table name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%stripereconciler_reconciliations}}';
    }
}
