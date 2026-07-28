<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

/**
 * Reconciles a batch of orders in the background.
 *
 * Queued because each order costs at least one Stripe round trip.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconcileOrders extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int[] The order IDs to reconcile.
     */
    public array $orderIds = [];

    /**
     * @var bool Whether to ask Stripe but change nothing.
     */
    public bool $dryRun = false;

    /**
     * @var bool Whether completing a cart is permitted.
     *
     * Defaults to true: this job is only queued by a control panel action. An
     * unattended caller should pass the `reconcileCarts` setting.
     */
    public bool $allowCarts = true;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Each order is caught individually so one failure does not abandon the batch.
     * Outcomes are recorded by the reconciliation service.
     *
     * @param QueueInterface|Queue $queue The queue running the job.
     * @return void
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan
     */
    public function execute($queue): void
    {
        $total = count($this->orderIds);
        $discovery = StripeReconciler::$plugin->getDiscovery();
        $reconciliation = StripeReconciler::$plugin->getReconciliation();

        foreach (array_values($this->orderIds) as $index => $orderId) {
            $this->setProgress($queue, $total > 0 ? $index / $total : 0);

            try {
                foreach ($discovery->findCandidates(orderId: (int)$orderId) as $candidate) {
                    if ($candidate->type->isActionable()) {
                        $reconciliation->reconcile($candidate, $this->dryRun, $this->allowCarts);
                    }
                }
            } catch (Throwable $e) {
                Craft::error('Reconciliation failed for order ' . $orderId . ': ' . $e->getMessage(), 'stripe-reconciler');
            }
        }
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The job description.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function defaultDescription(): string
    {
        if ($this->dryRun) {
            return Craft::t('stripe-reconciler', 'Checking Stripe payments');
        }

        return Craft::t('stripe-reconciler', 'Reconciling Stripe payments');
    }
}
