<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use Carbon\Carbon;
use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\stripe\base\Gateway as StripeGateway;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeInterface;
use johnhenry\stripereconciler\enums\CandidateType;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\StripeReconciler;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
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
 * A refund or dispute never changes a PaymentIntent's status: it stays
 * `succeeded` with its full `amount_received`, and only the charge records it.
 * So the latest charge is always fetched with the intent and checked first.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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

    /**
     * @var int How old, in minutes, a payment must be before an unattended run
     * looks at it, so a cron never races a customer who is still on their way
     * back from Stripe, or the webhook that follows them.
     */
    public const MIN_AGE_MINUTES = 15;

    /**
     * @var string[] `next_action` types that send the customer somewhere to pay
     * now. Any other pending action (bank transfer instructions, a voucher to pay
     * at a shop) means the customer can still pay days later.
     */
    public const INTERACTIVE_ACTIONS = ['redirect_to_url', 'use_stripe_sdk'];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Reconciles every unresolved transaction on a candidate.
     *
     * Stops at the first transaction that resolves the order, or on a dry run,
     * at the first one that would.
     *
     * @param Candidate $candidate The candidate to reconcile.
     * @param bool $dryRun When true, inspect Stripe but change nothing.
     * @param bool|null $allowCarts Whether completing a cart is permitted, or null to use the
     *                              `reconcileCarts` setting. Control panel actions pass true.
     * @param bool $respectBackoff Whether to skip payments checked within `recheckAfterMinutes`,
     *                             or started in the last few minutes. Unattended runs pass true.
     * @return ReconciliationResult[] One result per transaction attempted.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
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
            $skip = array_flip(array_merge(
                StripeReconciler::$plugin->getAudit()->getRecentlyCheckedTransactionIds(
                    $transactionIds,
                    $settings->recheckAfterMinutes,
                ),
                $this->_tooRecentTransactionIds($transactionIds),
            ));

            $transactionIds = array_values(array_filter(
                $transactionIds,
                static fn(int $id): bool => !isset($skip[$id]),
            ));
        }

        // Commerce adds a transaction each time it's asked to complete a payment,
        // so an unattended run never asks twice about one it couldn't complete.
        $failedBefore = $respectBackoff
            ? array_flip(StripeReconciler::$plugin->getAudit()->getTransactionIdsWithOutcome($transactionIds, Outcome::CompletionFailed))
            : [];

        foreach ($transactionIds as $transactionId) {
            $result = $this->_reconcileTransaction($candidate, $transactionId, $dryRun, $allowCarts, isset($failedBefore[$transactionId]));
            $results[] = $result;

            StripeReconciler::$plugin->getAudit()->record($candidate, $result);

            if ($result->outcome === Outcome::Reconciled || $result->outcome === Outcome::DryRun) {
                break;
            }
        }

        return $results;
    }

    /**
     * Fetches the live PaymentIntent behind a Commerce transaction, with its
     * latest charge expanded.
     *
     * Handles both the PaymentIntent and Checkout Session shapes of a stored
     * gateway response, as the Stripe gateway itself does.
     *
     * @param Transaction $transaction The Commerce transaction.
     * @return array<string, mixed>|null The PaymentIntent as an array, or null if it cannot be resolved.
     * @throws ApiErrorException If Stripe refuses the request.
     * @throws InvalidConfigException If the gateway's Stripe client can't be built.
     * @author John Henry Donovan <info@johnhenry.ie>
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
        $expand = ['expand' => ['latest_charge']];

        if ($data['object'] === 'payment_intent') {
            return $client->paymentIntents->retrieve($data['id'], $expand)->toArray();
        }

        // Anything else is a Checkout Session, which carries the intent id once
        // the customer has confirmed.
        $session = $client->checkout->sessions->retrieve($data['id'])->toArray();
        $intentId = $session['payment_intent'] ?? null;

        if (!is_string($intentId) || $intentId === '') {
            return $this->sessionAsIntent($session);
        }

        return $client->paymentIntents->retrieve($intentId, $expand)->toArray();
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Describes a Checkout Session that never created a PaymentIntent in the
     * shape of one, so it's judged like any other unpaid attempt.
     *
     * An expired session is as final as a cancelled intent; an open one is
     * still waiting on the customer.
     *
     * @param array<string, mixed> $session The Checkout Session.
     * @return array<string, mixed> A PaymentIntent-shaped description.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    protected function sessionAsIntent(array $session): array
    {
        return [
            'id' => $session['id'] ?? null,
            'status' => ($session['status'] ?? null) === 'expired' ? 'canceled' : 'requires_payment_method',
            'currency' => $session['currency'] ?? null,
            'amount' => $session['amount_total'] ?? null,
            'amount_received' => 0,
        ];
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
     * @param bool $failedBefore Whether an unattended run already failed to complete it.
     * @return ReconciliationResult What happened.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _reconcileTransaction(Candidate $candidate, int $transactionId, bool $dryRun, bool $allowCarts, bool $failedBefore = false): ReconciliationResult
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
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                $result->outcome = Outcome::MissingAtStripe;
                $result->message = 'Stripe has no record of this payment. The store\'s Stripe keys may have changed since it was taken. It will not be checked again.';

                return $result;
            }

            return $this->_unreachable($result, $transactionId, $e);
        } catch (Throwable $e) {
            return $this->_unreachable($result, $transactionId, $e);
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

        $reference = $intent['metadata']['transaction_reference'] ?? null;

        if (is_string($reference) && $reference !== '' && $reference !== $transaction->hash) {
            $result->outcome = Outcome::Errored;
            $result->message = 'The Stripe payment belongs to a different Commerce transaction, so nothing was changed.';

            return $result;
        }

        $isPaid = in_array($status, self::PAID_STATUSES, true);
        $isProcessing = $status === self::PROCESSING_STATUS;

        // Stops before Commerce is touched. See the class docblock for why this
        // must come before completePayment().
        if (!$isPaid && !$isProcessing) {
            return $this->_unpaid($intent, $status, $transaction, $result);
        }

        $order = $candidate->order;

        if ($order === null) {
            $result->outcome = Outcome::Errored;
            $result->message = 'Stripe confirmed a payment but the order no longer exists.';

            return $result;
        }

        if ($isPaid && ($problem = $this->_refundOrDispute($intent)) !== null) {
            $result->outcome = Outcome::RefundedOrDisputed;
            $result->message = $problem . ' Nothing was changed, and it will not be checked again.';

            return $result;
        }

        if ($candidate->type === CandidateType::ExtraAttempt) {
            $result->outcome = $isPaid ? Outcome::PossibleDoubleCharge : Outcome::StillProcessing;
            $result->message = $isPaid
                ? 'Stripe took money for this attempt, but another payment already paid the order. The customer may have been charged twice.'
                : 'Stripe is still settling an extra payment on an order that is already paid. It will be checked again.';

            return $result;
        }

        $mismatch = $this->_setExpectedAmount($order, $transaction, $result);

        $isCart = $candidate->type === CandidateType::AbandonedCart;

        if ($isCart && !$allowCarts) {
            $result->outcome = Outcome::CartSkipped;
            $result->message = 'Stripe reports "' . $status . '" on an order that is still a cart. '
                . 'Reconcile it from the control panel, or enable the reconcileCarts setting to have unattended runs complete it.';

            return $result;
        }

        $mismatch ??= $this->_checkAmount($result, $transaction);

        if ($mismatch !== null) {
            $result->outcome = Outcome::AmountMismatch;
            $result->message = $mismatch;

            return $result;
        }

        // Commerce only acts on a settling payment when the order is still a cart,
        // by completing it. On an order that's already complete there's nothing to
        // do until Stripe settles, and asking would only add a transaction.
        if ($isProcessing && !$isCart) {
            $result->outcome = Outcome::StillProcessing;
            $result->message = 'Stripe is still settling this payment. It will be checked again.';

            return $result;
        }

        if ($dryRun) {
            $result->outcome = Outcome::DryRun;
            $result->message = match (true) {
                $isProcessing => 'Stripe is still settling this payment and the amount matches. This is still a cart, so reconciling will create the order; it will be marked paid once Stripe settles.',
                $isCart => 'Stripe reports "' . $status . '" and the amount matches. This is still a cart, so reconciling will create the order and mark it paid.',
                default => 'Stripe reports "' . $status . '" and the amount matches. This order would be marked paid.',
            };

            return $result;
        }

        if ($failedBefore) {
            $result->outcome = Outcome::CompletionFailed;
            $result->message = 'Stripe confirmed the payment but Commerce did not mark the order paid last time. '
                . 'It is not retried automatically; complete it from the control panel once the cause is sorted.';

            return $result;
        }

        if ($transaction->status === TransactionRecord::STATUS_PROCESSING) {
            return $this->_settleProcessingTransaction($order, $transaction, $intent, $result);
        }

        return $this->_complete($order, $transaction, $result, $isProcessing);
    }

    /**
     * Records that Stripe couldn't be asked about a payment.
     *
     * @param ReconciliationResult $result The result being built up.
     * @param int $transactionId The Commerce transaction ID.
     * @param Throwable $e What went wrong.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _unreachable(ReconciliationResult $result, int $transactionId, Throwable $e): ReconciliationResult
    {
        Craft::error('Could not retrieve PaymentIntent for transaction ' . $transactionId . ': ' . $e->getMessage(), 'stripe-reconciler');

        $result->outcome = Outcome::Errored;
        $result->message = 'Could not reach Stripe for this payment. The details are in the logs.';

        return $result;
    }

    /**
     * Describes a payment Stripe hasn't been paid for.
     *
     * @param array<string, mixed> $intent The PaymentIntent.
     * @param string $status Its status.
     * @param Transaction $transaction The Commerce transaction.
     * @param ReconciliationResult $result The result being built up.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _unpaid(array $intent, string $status, Transaction $transaction, ReconciliationResult $result): ReconciliationResult
    {
        if ($this->_isAwaitingCustomer($intent, $status)) {
            $result->outcome = Outcome::StillProcessing;
            $result->message = 'Stripe is waiting for the customer to pay, by bank transfer or a voucher. It will be checked again.';

            return $result;
        }

        $finished = $this->_isFinishedUnpaid($status, $transaction);

        $result->outcome = $finished ? Outcome::Abandoned : Outcome::NotPaidAtStripe;
        $result->message = 'Stripe reports the payment as "' . $status . '". No money was taken, so nothing was changed.'
            . ($finished ? ' This one is not coming back, so it will not be checked again.' : '');

        return $result;
    }

    /**
     * Returns whether the customer has been given a way to pay later, such as
     * bank transfer details or a voucher, rather than sent off to pay now.
     *
     * @param array<string, mixed> $intent The PaymentIntent.
     * @param string $status Its status.
     * @return bool True if the payment can still arrive days from now.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _isAwaitingCustomer(array $intent, string $status): bool
    {
        if ($status !== 'requires_action') {
            return false;
        }

        $type = $intent['next_action']['type'] ?? null;

        return is_string($type) && !in_array($type, self::INTERACTIVE_ACTIONS, true);
    }

    /**
     * Describes a refund or dispute recorded on the payment's latest charge.
     *
     * @param array<string, mixed> $intent The PaymentIntent, with `latest_charge` expanded.
     * @return string|null What happened, or null if the charge is untouched.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _refundOrDispute(array $intent): ?string
    {
        $charge = $intent['latest_charge'] ?? null;

        if (!is_array($charge)) {
            return null;
        }

        if (!empty($charge['disputed'])) {
            return 'Stripe took this payment but the customer has disputed it.';
        }

        if (!empty($charge['refunded']) || (int)($charge['amount_refunded'] ?? 0) > 0) {
            return 'Stripe took this payment but it has since been refunded.';
        }

        return null;
    }

    /**
     * Returns the IDs of transactions started too recently for an unattended run
     * to look at.
     *
     * @param int[] $transactionIds The transactions being considered.
     * @return int[] The ones younger than `MIN_AGE_MINUTES`.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _tooRecentTransactionIds(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return array_map(static fn(mixed $id): int => (int)$id, (new Query())
            ->select(['id'])
            ->from(CommerceTable::TRANSACTIONS)
            ->where(['id' => $transactionIds])
            ->andWhere(['>', 'dateCreated', Db::prepareDateForDb(Carbon::now()->subMinutes(self::MIN_AGE_MINUTES))])
            ->column());
    }

    /**
     * Asks Commerce to complete the payment and reports what changed.
     *
     * @param Order $order The order being reconciled.
     * @param Transaction $transaction The transaction to complete.
     * @param ReconciliationResult $result The result being built up.
     * @param bool $isProcessing Whether Stripe reports the payment as still settling.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _complete(Order $order, Transaction $transaction, ReconciliationResult $result, bool $isProcessing): ReconciliationResult
    {
        $error = null;

        try {
            Commerce::getInstance()->getPayments()->completePayment($transaction, $error);
        } catch (Throwable $e) {
            Craft::error('completePayment() failed for transaction ' . $transaction->id . ': ' . $e->getMessage(), 'stripe-reconciler');

            $result->outcome = Outcome::Errored;
            $result->message = 'Commerce could not complete the payment. The details are in the logs.';

            return $result;
        }

        return $this->_describeOrderAfter($order, $result, $isProcessing, $error);
    }

    /**
     * Marks a transaction Commerce left in `processing` as successful, the way the
     * Stripe gateway's own webhook does, since Commerce won't complete a payment
     * in that state.
     *
     * @param Order $order The order being reconciled.
     * @param Transaction $transaction The processing transaction.
     * @param array<string, mixed> $intent The PaymentIntent Stripe reported as paid.
     * @param ReconciliationResult $result The result being built up.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _settleProcessingTransaction(Order $order, Transaction $transaction, array $intent, ReconciliationResult $result): ReconciliationResult
    {
        // The same lock Commerce and the webhook take, so the two can't both settle it.
        $lock = 'commerceTransaction:' . $transaction->hash;
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($lock, 15)) {
            $result->outcome = Outcome::Errored;
            $result->message = 'The payment is being updated by something else right now. It will be checked again.';

            return $result;
        }

        try {
            $record = TransactionRecord::findOne($transaction->id);

            if ($record === null || $record->status !== TransactionRecord::STATUS_PROCESSING) {
                return $this->_describeOrderAfter($order, $result, false, null);
            }

            $record->status = TransactionRecord::STATUS_SUCCESS;
            $record->message = '';
            $record->response = Json::encode($intent);
            $record->save(false);

            $transaction->getOrder()?->updateOrderPaidInformation();
        } catch (Throwable $e) {
            Craft::error('Could not settle transaction ' . $transaction->id . ': ' . $e->getMessage(), 'stripe-reconciler');

            $result->outcome = Outcome::Errored;
            $result->message = 'Commerce could not complete the payment. The details are in the logs.';

            return $result;
        } finally {
            $mutex->release($lock);
        }

        return $this->_describeOrderAfter($order, $result, false, null);
    }

    /**
     * Reads the order back after Commerce has acted, and records what it now says.
     *
     * @param Order $order The order being reconciled.
     * @param ReconciliationResult $result The result being built up.
     * @param bool $isProcessing Whether Stripe reports the payment as still settling.
     * @param string|null $error Anything Commerce reported.
     * @return ReconciliationResult The populated result.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _describeOrderAfter(Order $order, ReconciliationResult $result, bool $isProcessing, ?string $error): ReconciliationResult
    {
        $fresh = Order::find()->id($order->id)->status(null)->one();

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

        if ($isProcessing && $fresh instanceof Order && $fresh->isCompleted) {
            $result->outcome = Outcome::StillProcessing;
            $result->message = 'The order is now completed. Stripe is still settling the payment, so it will be marked paid once the money arrives.';

            Craft::info('Completed order ' . $fresh->reference . ' (id ' . $fresh->id . ') from a payment Stripe is still settling.', 'stripe-reconciler');

            return $result;
        }

        // An authorise-only gateway completes the order without marking it paid.
        if ($fresh instanceof Order && $fresh->isCompleted && $fresh->dateAuthorized !== null) {
            $result->outcome = Outcome::Reconciled;
            $result->message = 'Stripe confirmed the payment and the order is now completed, authorised and waiting to be captured.';

            Craft::info('Completed order ' . $fresh->reference . ' (id ' . $fresh->id . ') from an authorised Stripe payment.', 'stripe-reconciler');

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
     * A manual-capture intent holds `amount_capturable` until it's captured, and a
     * settling one has received nothing yet, so its `amount` is what's coming.
     *
     * @param array<string, mixed> $intent The PaymentIntent.
     * @param string $status The intent status.
     * @return int|null The amount in minor units, or null if absent.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _amountFromIntent(array $intent, string $status): ?int
    {
        $key = match ($status) {
            'requires_capture' => 'amount_capturable',
            self::PROCESSING_STATUS => 'amount',
            default => 'amount_received',
        };

        if (!isset($intent[$key])) {
            return null;
        }

        return (int)$intent[$key];
    }

    /**
     * Returns whether an unpaid payment is finished for good.
     *
     * A cancelled intent is final. Anything else unpaid is retired once older than
     * `settledAfterDays`. Payments still settling, or waiting on a bank transfer or
     * voucher, never reach here.
     *
     * @param string $status The PaymentIntent status reported by Stripe.
     * @param Transaction $transaction The Commerce transaction.
     * @return bool True if the payment should stop being re-checked.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Records the amount Commerce asked Stripe for, in minor units of the payment
     * currency, and checks the order still owes what it did when the payment
     * started.
     *
     * @param Order $order The order being reconciled.
     * @param Transaction $transaction The payment attempt.
     * @param ReconciliationResult $result The result being built up.
     * @return string|null Why the payment can't be matched to the order, or null if it can.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _setExpectedAmount(Order $order, Transaction $transaction, ReconciliationResult $result): ?string
    {
        $currencies = Commerce::getInstance()->getCurrencies();
        $paymentCurrency = strtoupper((string)$transaction->paymentCurrency);
        $orderCurrency = strtoupper((string)$order->currency);

        try {
            $result->orderTotalMinorUnits = (int)$currencies->getTeller($paymentCurrency)
                ->convertToMoney($transaction->paymentAmount)
                ->getAmount();

            $orderTeller = $currencies->getTeller($orderCurrency);
            $asked = (int)$orderTeller->convertToMoney($transaction->amount)->getAmount();
            $owed = (int)$orderTeller->convertToMoney($order->getOutstandingBalance())->getAmount();
        } catch (Throwable $e) {
            Craft::error('Could not resolve the currency for transaction ' . $transaction->id . ': ' . $e->getMessage(), 'stripe-reconciler');

            return 'The payment or order currency could not be resolved.';
        }

        if (abs($asked - $owed) > StripeReconciler::$plugin->getSettings()->amountToleranceMinorUnits) {
            return 'The order changed after this payment started: the payment was for ' . $asked
                . ' but the order now owes ' . $owed . ' (' . $orderCurrency . ', minor units). Held for review.';
        }

        return null;
    }

    /**
     * Checks what Stripe holds against what Commerce asked it for.
     *
     * Guards against partial payments and a payment taken in another currency.
     *
     * @param ReconciliationResult $result The result being built up, with the expected amount set.
     * @param Transaction $transaction The payment attempt.
     * @return string|null A description of the mismatch, or null if the amounts agree.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _checkAmount(ReconciliationResult $result, Transaction $transaction): ?string
    {
        $paymentCurrency = strtoupper((string)$transaction->paymentCurrency);

        if ($result->orderTotalMinorUnits === null) {
            return 'Could not resolve the amount this payment was for.';
        }

        if ($result->currency === null) {
            return 'Stripe did not report a currency for this payment.';
        }

        if ($result->currency !== $paymentCurrency) {
            return 'Stripe took ' . $result->currency . ' but the payment was for ' . $paymentCurrency . '.';
        }

        if ($result->stripeAmountReceived === null) {
            return 'Stripe did not report an amount for this payment.';
        }

        $tolerance = StripeReconciler::$plugin->getSettings()->amountToleranceMinorUnits;
        $difference = abs($result->stripeAmountReceived - $result->orderTotalMinorUnits);

        if ($difference > $tolerance) {
            return 'Stripe holds ' . $result->stripeAmountReceived . ' but the payment was for '
                . $result->orderTotalMinorUnits . ' (' . $paymentCurrency . ', minor units). Held for review.';
        }

        return null;
    }
}
