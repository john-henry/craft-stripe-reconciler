<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\controllers;

use Craft;
use craft\web\Controller;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\jobs\ReconcileOrders;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\StripeReconciler;
use johnhenry\stripereconciler\utilities\ReconcilerUtility;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\Response;

/**
 * Handles reconciliation requests from the control panel utility.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconcileController extends Controller
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Permission required to reconcile a payment.
     *
     * Referenced by the registration in PluginTrait, the gate below and the
     * utility's template check, so a typo cannot drift between them.
     */
    public const PERMISSION_RECONCILE = 'stripe-reconciler:reconcile';

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Asks Stripe what happened to an order's payment, without changing anything.
     *
     * Separate from committing, so the answer can be seen before acting.
     *
     * @return Response The outcome as JSON, including whether it can be reconciled.
     * @throws Throwable If the check fails unexpectedly.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCheck(): Response
    {
        return $this->_run(dryRun: true);
    }

    /**
     * Reconciles a single order against Stripe.
     *
     * Runs inline: one order is at most a couple of Stripe calls. Every gate runs
     * again here, so a stale page cannot commit something a fresh check refuses.
     *
     * @return Response The outcome as JSON.
     * @throws Throwable If reconciliation fails unexpectedly.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCommit(): Response
    {
        return $this->_run(dryRun: false);
    }

    /**
     * Queues a check of every outstanding candidate against Stripe.
     *
     * Checks only, never commits. Bulk completion lives in the console command.
     *
     * Queued because each candidate costs a Stripe round trip and a backlog would
     * outlast the request timeout.
     *
     * @return Response The queued job count as JSON.
     * @throws Throwable If the candidates cannot be loaded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionAll(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_RECONCILE);

        $orderIds = array_map(
            static fn(Candidate $candidate): int => $candidate->orderId,
            $this->_actionableCandidates(),
        );

        if ($orderIds === []) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('stripe-reconciler', 'There is nothing waiting to be checked.'),
            ]);
        }

        Craft::$app->getQueue()->push(new ReconcileOrders([
            'orderIds' => $orderIds,
            'dryRun' => true,
        ]));

        return $this->asJson([
            'success' => true,
            'queued' => count($orderIds),
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Runs a single order through reconciliation, checking or committing.
     *
     * @param bool $dryRun When true, ask Stripe but change nothing.
     * @return Response The outcome as JSON.
     * @throws Throwable If reconciliation fails unexpectedly.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _run(bool $dryRun): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(self::PERMISSION_RECONCILE);

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $candidate = $this->_findCandidate($orderId);

        if ($candidate === null) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('stripe-reconciler', 'That order is no longer waiting to be reconciled.'),
            ]);
        }

        try {
            // `reconcileCarts` guards unattended runs only; this path is an
            // explicit, permission-checked action.
            $results = StripeReconciler::$plugin->getReconciliation()->reconcile($candidate, $dryRun, allowCarts: true);
        } catch (Throwable $e) {
            Craft::error('Reconciliation failed for order ' . $orderId . ': ' . $e->getMessage(), 'stripe-reconciler');

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('stripe-reconciler', 'Could not reconcile this order. Check the logs for details.'),
            ]);
        }

        if (!$dryRun) {
            $this->_clearBadge();
        }

        if ($results === []) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('stripe-reconciler', 'There was nothing to reconcile on that order.'),
            ]);
        }

        $last = end($results);

        return $this->asJson([
            'success' => true,
            'outcome' => $last->outcome->value,
            'reconciled' => $last->outcome === Outcome::Reconciled,
            'needsAttention' => $last->outcome->needsAttention(),
            'statusColour' => $last->outcome->statusColour(),
            'reconcilable' => $last->outcome === Outcome::DryRun,
            'message' => $last->message,
        ]);
    }

    /**
     * Returns the candidates worth acting on.
     *
     * @return Candidate[] The actionable candidates.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _actionableCandidates(): array
    {
        return array_values(array_filter(
            StripeReconciler::$plugin->getDiscovery()->findCandidates(),
            static fn(Candidate $candidate): bool => $candidate->type->isActionable(),
        ));
    }

    /**
     * Re-runs discovery for a single order, rather than trusting a posted ID, so
     * classification and eligibility are evaluated fresh.
     *
     * @param int $orderId The order ID.
     * @return Candidate|null The candidate, or null if it is no longer actionable.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan
     */
    private function _findCandidate(int $orderId): ?Candidate
    {
        $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $orderId);

        foreach ($candidates as $candidate) {
            if ($candidate->orderId === $orderId && $candidate->type->isActionable()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Drops the cached badge count.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _clearBadge(): void
    {
        Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);
    }
}
