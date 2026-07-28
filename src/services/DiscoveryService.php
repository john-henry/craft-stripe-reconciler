<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use Carbon\Carbon;
use Craft;
use craft\commerce\base\Gateway as CommerceGateway;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use craft\db\Query;
use craft\helpers\Db;
use johnhenry\stripereconciler\enums\CandidateType;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\StripeReconciler;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Finds orders carrying unresolved Stripe transactions.
 *
 * Classifies and reports only. Whether money arrived is decided in
 * ReconciliationService, by asking Stripe.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class DiscoveryService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns every enabled Stripe gateway.
     *
     * Matched by class, not handle: handles are author-chosen and a store may run
     * several Stripe gateways.
     *
     * @return StripeGateway[] The Stripe gateways, keyed by gateway ID.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getStripeGateways(): array
    {
        $commerce = Commerce::getInstance();

        // Commerce can be disabled after install, in which case getInstance() is null.
        if ($commerce === null) {
            Craft::warning('Commerce is not available, so no Stripe gateways could be resolved.', 'stripe-reconciler');

            return [];
        }

        $settings = StripeReconciler::$plugin->getSettings();
        $allowed = $settings->enabledGateways;
        $gateways = [];

        /** @var CommerceGateway $gateway */
        foreach ($commerce->getGateways()->getAllGateways() as $gateway) {
            if (!$gateway instanceof StripeGateway) {
                continue;
            }

            if ($allowed !== [] && !in_array((int)$gateway->id, $allowed, true)) {
                continue;
            }

            $gateways[(int)$gateway->id] = $gateway;
        }

        return $gateways;
    }

    /**
     * Finds orders with Stripe transactions that never reached a final state.
     *
     * Covers both `redirect` (customer never returned) and `processing`
     * (asynchronous methods such as SEPA and Bacs, which settle days later).
     *
     * @param int|null $lookbackDays How many days back to search, or null to use the configured value.
     * @param int|null $gatewayId Restrict to a single gateway, or null for all Stripe gateways.
     * @param int|null $orderId Restrict to a single order, or null for all.
     * @return Candidate[] The candidates, oldest transaction first.
     * @throws InvalidConfigException If the audit service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function findCandidates(?int $lookbackDays = null, ?int $gatewayId = null, ?int $orderId = null): array
    {
        $gateways = $this->getStripeGateways();

        if ($gateways === []) {
            return [];
        }

        $gatewayIds = array_keys($gateways);

        if ($gatewayId !== null) {
            if (!in_array($gatewayId, $gatewayIds, true)) {
                return [];
            }

            $gatewayIds = [$gatewayId];
        }

        $settings = StripeReconciler::$plugin->getSettings();
        $days = max(1, $lookbackDays ?? $settings->lookbackDays);
        $since = Carbon::now()->subDays($days);

        $query = (new Query())
            ->select(['id', 'orderId', 'gatewayId'])
            ->from(CommerceTable::TRANSACTIONS)
            ->where([
                'status' => [TransactionRecord::STATUS_REDIRECT, TransactionRecord::STATUS_PROCESSING],
                'gatewayId' => $gatewayIds,
                'type' => [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_AUTHORIZE],
            ])
            ->orderBy(['dateCreated' => SORT_ASC]);

        if ($orderId !== null) {
            $query->andWhere(['orderId' => $orderId]);
        } else {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        return $this->_buildCandidates($rows, $orderId !== null);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Groups transaction rows by order and classifies each one.
     *
     * Filters only resolved payments. The re-check backoff lives in
     * ReconciliationService, since discovery also feeds the control panel listing,
     * which must not hide outstanding work.
     *
     * @param array<int, array<string, mixed>> $rows The transaction rows.
     * @param bool $explicit Whether a specific order was asked for, which keeps
     *                       already-paid orders in the results.
     * @return Candidate[] The classified candidates.
     * @throws InvalidConfigException If the audit service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _buildCandidates(array $rows, bool $explicit): array
    {
        $audit = StripeReconciler::$plugin->getAudit();

        $resolved = array_flip($audit->getTerminalTransactionIds(
            array_map(static fn(array $row): int => (int)$row['id'], $rows),
        ));

        $grouped = [];

        foreach ($rows as $row) {
            $transactionId = (int)$row['id'];

            if (isset($resolved[$transactionId])) {
                continue;
            }

            $rowOrderId = (int)$row['orderId'];
            $grouped[$rowOrderId]['transactionIds'][] = $transactionId;
            $grouped[$rowOrderId]['gatewayId'] = (int)$row['gatewayId'];
        }

        $candidates = [];

        foreach ($grouped as $groupedOrderId => $group) {
            // Guards against completing a second payment on an already paid order.
            if ($audit->hasReconciledOrder($groupedOrderId)) {
                continue;
            }

            $order = $this->_findOrder($groupedOrderId);
            $type = $this->_classify($order);

            // Commerce never rewrites the parent transaction, so it stays in
            // "redirect" permanently, meaning every order paid via a redirect
            // method keeps matching the query above.
            if (!$explicit && $type === CandidateType::AlreadyPaid) {
                continue;
            }

            $candidates[] = new Candidate([
                'orderId' => $groupedOrderId,
                'order' => $order,
                'type' => $type,
                'transactionIds' => $group['transactionIds'],
                'gatewayId' => $group['gatewayId'],
            ]);
        }

        return $candidates;
    }

    /**
     * Loads an order regardless of its status, including carts.
     *
     * @param int $orderId The order ID.
     * @return Order|null The order, or null if it no longer exists.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _findOrder(int $orderId): ?Order
    {
        /** @var Order|null $order */
        $order = Order::find()
            ->id($orderId)
            ->status(null)
            ->trashed(null)
            ->one();

        return $order;
    }

    /**
     * Classifies an order for reporting.
     *
     * Carts are classified, not discarded. `updateOrderPaidInformation()` calls
     * `markAsComplete()` itself once the payment completes, so a cart with a
     * successful payment resolves correctly if it reaches reconciliation.
     *
     * @param Order|null $order The order, or null if it no longer exists.
     * @return CandidateType The classification.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _classify(?Order $order): CandidateType
    {
        if ($order === null) {
            return CandidateType::OrderMissing;
        }

        if ($order->getIsPaid()) {
            return CandidateType::AlreadyPaid;
        }

        if (!$order->isCompleted) {
            return CandidateType::AbandonedCart;
        }

        return CandidateType::UnpaidCompletedOrder;
    }
}
