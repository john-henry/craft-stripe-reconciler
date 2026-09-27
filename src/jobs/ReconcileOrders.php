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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReconcileOrders extends BaseJob
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int Most orders one job checks, so a job finishes well inside the
     * queue's time limit even when Stripe is slow and retrying.
     */
    public const BATCH_SIZE = 50;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int[] The order IDs to reconcile.
     */
    public array $orderIds = [];

    /**
     * @var bool Whether to ask Stripe but change nothing. Defaults to checking
     * only, so a job queued without saying otherwise can't complete anything.
     */
    public bool $dryRun = true;

    /**
     * @var bool Whether completing a cart is permitted. Off unless the caller
     * says so.
     */
    public bool $allowCarts = false;

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
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
