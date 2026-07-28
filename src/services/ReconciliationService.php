<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use Carbon\Carbon;
use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeInterface;
use johnhenry\stripereconciler\enums\CandidateType;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Verifies unresolved payments against Stripe and finalises the ones that
 * actually succeeded.
 *
 * Every decision comes from a live PaymentIntent, never from local state.
 *
 * The intent must be confirmed payable before `completePayment()` is called.
 * `Payments::completePayment()` ends with
 * `Craft::$app->getResponse()->redirect()` then `Craft::$app->end()` when the
 * gateway response reports a redirect, and
 * `PaymentIntentResponse::isRedirect()` returns true for
 * `requires_payment_method`, the status an abandoned checkout leaves behind.
 * `yii\console\Response` has no `redirect()`, so calling it unchecked from the
 * console throws on the most common candidates.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconciliationService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string[] PaymentIntent statuses that mean Stripe has the money.
     */
    public const PAID_STATUSES = ['succeeded', 'requires_capture'];

    /**
     * @var string PaymentIntent status meaning the payment is still settling.
     */
    public const PROCESSING_STATUS = 'processing';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Reconciles every unresolved transaction on a candidate.
     *
     * Stops at the first transaction that resolves the order.
     *
     * @param Candidate $candidate The candidate to reconcile.
     * @param bool $dryRun When true, inspect Stripe but change nothing.
     * @param bool|null $allowCarts Whether completing a cart is permitted, or null to use the
     *                              `reconcileCarts` setting. Control panel actions pass true.
     * @param bool $respectBackoff Whether to skip payments checked within
     *                             `recheckAfterMinutes`. Unattended runs pass true.
     * @return ReconciliationResult[] One result per transaction attempted.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function reconcile(
        Candidate $candidate,
        bool $dryRun = false,
        ?bool $allowCarts = null,
        bool $respectBackoff = false,
    ): array {
        $results = [];

        if (!$candidate->type->isActionable()) {
            return $results;
        }

        $settings = StripeReconciler::$plugin->getSettings();
        $allowCarts ??= $settings->reconcileCarts;
        $transactionIds = $candidate->transactionIds;

        if ($respectBackoff) {
            $recent = array_flip(StripeReconciler::$plugin->getAudit()->getRecentlyCheckedTransactionIds(
                $transactionIds,
                $settings->recheckAfterMinutes,
            ));

            $transactionIds = array_values(array_filter(
                $transactionIds,
                static fn(int $id): bool => !isset($recent[$id]),
            ));
        }

        foreach ($transactionIds as $transactionId) {
            $result = $this->_reconcileTransaction($candidate, $transactionId, $dryRun, $allowCarts);
            $results[] = $result;

            StripeReconciler::$plugin->getAudit()->record($candidate, $result);

            if ($result->outcome === Outcome::Reconciled) {
                break;
            }
        }

        return $results;
    }

    /**
     * Fetches the live PaymentIntent behind a Commerce transaction.
     *
     * Handles both the PaymentIntent and Checkout Session shapes of a stored
     * gateway response, as the Stripe gateway itself does.
     *
     * @param Transaction $transaction The Commerce transaction.
     * @return array<string, mixed>|null The PaymentIntent as an array, or null if it cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function inspect(Transaction $transaction): ?array
    {
        $gateway = $transaction->getGateway();

        if (!$gateway instanceof StripeGateway) {
            return null;
        }

        $data = Json::decodeIfJson($transaction->response);

        if (!is_array($data) || !isset($data['id'], $data['object'])) {
            return null;
        }

        $client = $gateway->getStripeClient();

        if ($data['object'] === 'payment_intent') {
            return $client->paymentIntents->retrieve($data['id'])->toArray();
        }

        // Anything else is a Checkout Session, which carries the intent id.
        $session = $client->checkout->sessions->retrieve($data['id']);
        $intentId = $session['payment_intent'] ?? null;

        if (!is_string($intentId) || $intentId === '') {
            return null;
        }

        return $client->paymentIntents->retrieve($intentId)->toArray();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Reconciles a single transaction.
     *
     * @param Candidate $candidate The owning candidate.
     * @param int $transactionId The Commerce transaction ID.
     * @param bool $dryRun When true, inspect Stripe but change nothing.
     * @param bool $allowCarts Whether completing a cart is permitted.
     * @return ReconciliationResult What happened.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reconcileTransaction(Candidate $candidate, int $transactionId, bool $dryRun, bool $allowCarts): ReconciliationResult
    {
        $result = new ReconciliationResult(['transactionId' => $transactionId]);

        $transaction = Commerce::getInstance()->getTransactions()->getTransactionById($transactionId);

        if ($transaction === null) {
            $result->outcome = Outcome::Errored;
            $result->message = 'Transaction no longer exists.';

            return $result;
        }

        try {
            $intent = $this->inspect($transaction);
        } catch (Throwable $e) {
            Craft::error('Could not retrieve PaymentIntent for transaction ' . $transactionId . ': ' . $e->getMessage(), 'stripe-reconciler');

            $result->outcome = Outcome::Errored;
            $result->message = 'Could not reach Stripe for this payment. The details are in the logs.';

            return $result;
        }

        if ($intent === null) {
            $result->outcome = Outcome::Errored;
            $result->message = 'Could not resolve a PaymentIntent from the stored gateway response.';

            return $result;
        }

        $status = (string)($intent['status'] ?? '');
        $result->paymentIntentId = isset($intent['id']) ? (string)$intent['id'] : null;
        $result->stripeStatus = $status;
        $result->currency = isset($intent['currency']) ? strtoupper((string)$intent['currency']) : null;
        $result->stripeAmountReceived = $this->_amountFromIntent($intent, $status);

        $isPaid = in_array($status, self::PAID_STATUSES, true);
        $isProcessing = $status === self::PROCESSING_STATUS;

        // Stops before Commerce is touched. See the class docblock for why this
        // must come before completePayment().
        if (!$isPaid && !$isProcessing) {
            $finished = $this->_isFinishedUnpaid($status, $transaction);

            $result->outcome = $finished ? Outcome::Abandoned : Outcome::NotPaidAtStripe;
            $result->message = 'Stripe reports the payment as "' . $status . '". No money was taken, so nothing was changed.'
                . ($finished ? ' This one is not coming back, so it will not be checked again.' : '');

            return $result;
        }

        $order = $candidate->order;

        if ($order === null) {
            $result->outcome = Outcome::Errored;
            $result->message = 'Stripe confirmed a payment but the order no longer exists.';

            return $result;
        }

        // Set before any branch that can act, so audit rows carry both amounts.
        $currencyError = $this->_setOrderTotal($order, $result);

        $isCart = $candidate->type === CandidateType::AbandonedCart;

        if ($isCart && !$allowCarts) {
            $result->outcome = Outcome::CartSkipped;
            $result->message = 'Stripe reports "' . $status . '" on an order that is still a cart. '
                . 'Reconcile it from the control panel, or enable the reconcileCarts setting to have unattended runs complete it.';

            return $result;
        }

        if ($isPaid) {
            $mismatch = $currencyError ?? $this->_checkAmount($order, $result);

            if ($mismatch !== null) {
                $result->outcome = Outcome::AmountMismatch;
                $result->message = $mismatch;

                return $result;
            }
        }

        if ($dryRun) {
            $result->outcome = Outcome::DryRun;
            $result->message = $isCart
                ? 'Stripe reports "' . $status . '" and the amount matches. This is still a cart, so reconciling will create the order and mark it paid.'
                : 'Stripe reports "' . $status . '" and the amount matches. This order would be marked paid.';

            return $result;
        }

        return $this->_complete($order, $transaction, $result, $isProcessing);
    }

    /**
     * Asks Commerce to complete the payment and reports what changed.
     *
     * @param Order $order The order being reconciled.
     * @param Transaction $transaction The transaction to complete.
     * @param ReconciliationResult $result The result being built up.
     * @param bool $isProcessing Whether Stripe reports the payment as still settling.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _complete(Order $order, Transaction $transaction, ReconciliationResult $result, bool $isProcessing): ReconciliationResult
    {
        $wasCompleted = (bool)$order->isCompleted;
        $error = null;

        try {
            Commerce::getInstance()->getPayments()->completePayment($transaction, $error);
        } catch (Throwable $e) {
            Craft::error('completePayment() failed for transaction ' . $transaction->id . ': ' . $e->getMessage(), 'stripe-reconciler');

            $result->outcome = Outcome::Errored;
            $result->message = 'Commerce could not complete the payment. The details are in the logs.';

            return $result;
        }

        $fresh = Order::find()->id($order->id)->status(null)->trashed(null)->one();

        if ($fresh instanceof Order) {
            // A cart has no reference until completion, so read it back here.
            $result->orderReference = $fresh->reference;
            $result->orderShortNumber = $fresh->number !== null ? $fresh->getShortNumber() : null;
        }

        if ($fresh instanceof Order && $fresh->getIsPaid()) {
            $result->outcome = Outcome::Reconciled;
            $result->message = 'Stripe confirmed the payment and the order is now marked paid.';

            Craft::info('Reconciled order ' . $fresh->reference . ' (id ' . $fresh->id . ').', 'stripe-reconciler');

            return $result;
        }

        // An authorise-only gateway completes the order without marking it paid.
        if ($fresh instanceof Order && !$wasCompleted && (bool)$fresh->isCompleted) {
            $result->outcome = Outcome::Reconciled;
            $result->message = 'Stripe confirmed the payment and the order is now completed, awaiting capture or settlement.';

            Craft::info('Completed order ' . $fresh->reference . ' (id ' . $fresh->id . ') from a confirmed Stripe payment.', 'stripe-reconciler');

            return $result;
        }

        if ($isProcessing) {
            $result->outcome = Outcome::StillProcessing;
            $result->message = 'Stripe is still settling this payment. It will be retried on the next run.';

            return $result;
        }

        $result->outcome = Outcome::CompletionFailed;
        $result->message = 'Stripe confirmed the payment but Commerce did not mark the order paid.'
            . ($error !== null && $error !== '' ? ' Commerce said: ' . $error : '');

        Craft::warning($result->message . ' Order id ' . $order->id . '.', 'stripe-reconciler');

        return $result;
    }

    /**
     * Returns the amount Stripe holds for an intent, in minor units.
     *
     * `amount_received` is zero on a manual-capture intent until it is captured,
     * so `amount` is used for `requires_capture`.
     *
     * @param array<string, mixed> $intent The PaymentIntent.
     * @param string $status The intent status.
     * @return int|null The amount in minor units, or null if absent.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _amountFromIntent(array $intent, string $status): ?int
    {
        $key = $status === 'requires_capture' ? 'amount' : 'amount_received';

        if (!isset($intent[$key])) {
            return null;
        }

        return (int)$intent[$key];
    }

    /**
     * Returns whether an unpaid payment is finished for good.
     *
     * A cancelled intent is final. Anything else unpaid is retired once older than
     * `settledAfterDays`. Payments still settling never reach here, so SEPA and
     * similar are never retired early.
     *
     * @param string $status The PaymentIntent status reported by Stripe.
     * @param Transaction $transaction The Commerce transaction.
     * @return bool True if the payment should stop being re-checked.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _isFinishedUnpaid(string $status, Transaction $transaction): bool
    {
        if ($status === 'canceled') {
            return true;
        }

        $days = StripeReconciler::$plugin->getSettings()->settledAfterDays;
        $createdAt = $transaction->dateCreated;

        if (!$createdAt instanceof DateTimeInterface) {
            return false;
        }

        return Carbon::instance(DateTimeImmutable::createFromInterface($createdAt))
            ->addDays(max(0, $days))
            ->isPast();
    }

    /**
     * Records the order total on the result, in minor units.
     *
     * @param Order $order The order being reconciled.
     * @param ReconciliationResult $result The result being built up.
     * @return string|null A description of why the total could not be resolved, or null on success.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _setOrderTotal(Order $order, ReconciliationResult $result): ?string
    {
        $orderCurrency = strtoupper($order->getPaymentCurrency());

        try {
            $teller = Commerce::getInstance()->getCurrencies()->getTeller($orderCurrency);
            $result->orderTotalMinorUnits = (int)$teller->convertToMoney($order->getTotalPrice())->getAmount();
        } catch (Throwable $e) {
            Craft::error('Could not resolve the order currency "' . $orderCurrency . '": ' . $e->getMessage(), 'stripe-reconciler');

            return 'The order currency "' . $orderCurrency . '" could not be resolved.';
        }

        return null;
    }

    /**
     * Checks the Stripe amount against the order total.
     *
     * Guards against partial payments and totals that changed after the attempt.
     *
     * @param Order $order The order being reconciled.
     * @param ReconciliationResult $result The result being built up, populated with the order total.
     * @return string|null A description of the mismatch, or null if the amounts agree.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _checkAmount(Order $order, ReconciliationResult $result): ?string
    {
        $orderCurrency = strtoupper($order->getPaymentCurrency());

        if ($result->orderTotalMinorUnits === null) {
            return 'Could not resolve the order total in "' . $orderCurrency . '".';
        }

        if ($result->currency !== null && $result->currency !== $orderCurrency) {
            return 'Stripe took ' . $result->currency . ' but the order is in ' . $orderCurrency . '.';
        }

        if ($result->stripeAmountReceived === null) {
            return 'Stripe did not report an amount for this payment.';
        }

        $tolerance = StripeReconciler::$plugin->getSettings()->amountToleranceMinorUnits;
        $difference = abs($result->stripeAmountReceived - $result->orderTotalMinorUnits);

        if ($difference > $tolerance) {
            return 'Stripe holds ' . $result->stripeAmountReceived . ' but the order total is '
                . $result->orderTotalMinorUnits . ' (' . $orderCurrency . ', minor units). Held for review.';
        }

        return null;
    }
}
