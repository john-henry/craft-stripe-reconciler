<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use Carbon\Carbon;
use Craft;
use craft\helpers\Db;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\records\Reconciliation;
use johnhenry\stripereconciler\StripeReconciler;
use yii\base\Component;

/**
 * Maintains the reconciliation audit trail.
 *
 * Doubles as the idempotency guard: an order recorded as reconciled is never
 * picked up again.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class AuditService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns whether an order has already been reconciled successfully.
     *
     * @param int $orderId The Commerce order ID.
     * @return bool True if a completed reconciliation is already on record.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function hasReconciledOrder(int $orderId): bool
    {
        return Reconciliation::find()
            ->where([
                'orderId' => $orderId,
                'outcome' => Outcome::Reconciled->value,
            ])
            ->exists();
    }

    /**
     * Returns which of the given transactions are finished with for good.
     *
     * Either reconciled, or confirmed by Stripe as never paid.
     *
     * @param int[] $transactionIds The transactions being considered.
     * @return int[] The transaction IDs that are resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getTerminalTransactionIds(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return array_map('intval', Reconciliation::find()
            ->select(['transactionId'])
            ->where([
                'transactionId' => $transactionIds,
                'outcome' => Outcome::terminalValues(),
            ])
            ->column());
    }

    /**
     * Returns which of the given transactions were checked against Stripe recently.
     *
     * Rate limits unattended runs only. Never used to filter what is displayed.
     *
     * @param int[] $transactionIds The transactions being considered.
     * @param int $withinMinutes How recently counts as recent.
     * @return int[] The transaction IDs checked inside the window.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getRecentlyCheckedTransactionIds(array $transactionIds, int $withinMinutes): array
    {
        if ($transactionIds === [] || $withinMinutes <= 0) {
            return [];
        }

        return array_map('intval', Reconciliation::find()
            ->select(['transactionId'])
            ->where(['transactionId' => $transactionIds])
            ->andWhere(['>', 'dateLastAttempt', Db::prepareDateForDb(Carbon::now()->subMinutes($withinMinutes))])
            ->column());
    }

    /**
     * Records the result of a reconciliation attempt.
     *
     * Upserts on `transactionId`, so repeated runs update one row rather than
     * adding one each time.
     *
     * @param Candidate $candidate The candidate that was attempted.
     * @param ReconciliationResult $result What happened.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function record(Candidate $candidate, ReconciliationResult $result): void
    {
        $record = Reconciliation::findOne(['transactionId' => $result->transactionId]);

        if ($record === null) {
            $record = new Reconciliation();
            $record->transactionId = $result->transactionId;
            $record->attempts = 0;
        }

        $order = $candidate->order;

        $record->orderId = $candidate->orderId;
        $record->gatewayId = $candidate->gatewayId;
        $record->candidateType = $candidate->type->value;
        $record->outcome = $result->outcome->value;
        $record->message = $result->message !== '' ? $result->message : null;
        $record->paymentIntentId = $result->paymentIntentId;
        $record->stripeStatus = $result->stripeStatus;
        $record->stripeAmountReceived = $result->stripeAmountReceived;
        $record->orderTotalMinorUnits = $result->orderTotalMinorUnits;
        $record->currency = $result->currency;
        $record->orderReference = $result->orderReference ?? $order->reference ?? null;
        $record->orderShortNumber = $result->orderShortNumber
            ?? ($order !== null && $order->number !== null ? $order->getShortNumber() : null);
        $record->email = $order->email ?? null;
        $record->attempts = $record->attempts + 1;
        $record->dateLastAttempt = Db::prepareDateForDb(Carbon::now());

        $record->save(false);
    }

    /**
     * Deletes audit rows for payments that were never paid and are long past.
     *
     * Reconciled rows are never deleted, whatever the retention period.
     *
     * The cutoff is `max(auditRetentionDays, lookbackDays)`. A shorter cutoff would
     * delete rows whose transactions are still discoverable, causing them to be
     * rediscovered and re-checked.
     *
     * @return int How many rows were deleted.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function prune(): int
    {
        $settings = StripeReconciler::$plugin->getSettings();
        $days = max($settings->auditRetentionDays, $settings->lookbackDays);
        $cutoff = Db::prepareDateForDb(Carbon::now()->subDays($days));

        return Craft::$app->getDb()->createCommand()
            ->delete(Reconciliation::tableName(), [
                'and',
                ['not', ['outcome' => Outcome::Reconciled->value]],
                ['<', 'dateLastAttempt', $cutoff],
            ])
            ->execute();
    }

    /**
     * Returns the most recent audit rows.
     *
     * @param int $limit How many rows to return.
     * @return array<int, array<string, mixed>> The rows, newest attempt first.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getRecent(int $limit = 25): array
    {
        return Reconciliation::find()
            ->orderBy(['dateLastAttempt' => SORT_DESC])
            ->limit(max(1, $limit))
            ->asArray()
            ->all();
    }
}
