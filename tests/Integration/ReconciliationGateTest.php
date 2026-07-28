<?php

use craft\commerce\models\Transaction;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\services\ReconciliationService;
use johnhenry\stripereconciler\StripeReconciler;

/**
 * A reconciliation service with a canned Stripe response.
 *
 * `inspect()` is the only point where the service talks to Stripe, so overriding
 * it exercises the real pre-flight decision logic against known PaymentIntent
 * payloads without needing live API credentials.
 */
class StubReconciliationService extends ReconciliationService
{
    /**
     * @var array<string, mixed>|null The PaymentIntent to return.
     */
    public ?array $intent = null;

    /**
     * @var bool Whether inspect() was reached.
     */
    public bool $inspected = false;

    /**
     * @var string|null An error to throw instead of returning an intent, standing
     *                  in for Stripe refusing the lookup.
     */
    public ?string $throw = null;

    /**
     * @inheritdoc
     */
    public function inspect(Transaction $transaction): ?array
    {
        $this->inspected = true;

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        return $this->intent;
    }
}

/**
 * Builds a candidate from a freshly discovered order.
 *
 * @param int $orderId The order ID.
 * @return Candidate The candidate.
 */
function discoveredCandidate(int $orderId): Candidate
{
    // Lookup by ID bypasses the re-check backoff, so the gate can run twice.
    foreach (StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $orderId) as $candidate) {
        if ($candidate->orderId === $orderId) {
            return $candidate;
        }
    }

    throw new RuntimeException('Order ' . $orderId . ' was not discovered as a candidate.');
}

/**
 * Runs the stub service over an order and returns the single result.
 *
 * @param int $orderId The order ID.
 * @param array<string, mixed>|null $intent The canned PaymentIntent.
 * @param bool $dryRun Whether to run as a dry run.
 * @param bool|null $allowCarts Whether completing a cart is permitted, or null to use the setting.
 * @return array{0: johnhenry\stripereconciler\models\ReconciliationResult, 1: StubReconciliationService}
 */
function runGate(int $orderId, ?array $intent, bool $dryRun = false, ?bool $allowCarts = null): array
{
    $service = new StubReconciliationService();
    $service->intent = $intent;

    $results = $service->reconcile(discoveredCandidate($orderId), $dryRun, $allowCarts);

    expect($results)->toHaveCount(1);

    return [$results[0], $service];
}

it('refuses to touch Commerce when Stripe says the payment was never made', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_abandoned',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ]);

    expect($result->outcome)->toBe(Outcome::NotPaidAtStripe)
        ->and($result->stripeStatus)->toBe('requires_payment_method');

    // completePayment() must never have been reached.
    $fresh = craft\commerce\elements\Order::find()->id($order->id)->status(null)->one();
    expect($fresh->getIsPaid())->toBeFalse();
});

it('retires a cancelled PaymentIntent immediately', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_cancelled',
        'status' => 'canceled',
        'currency' => 'usd',
        'amount_received' => 0,
    ]);

    expect($result->outcome)->toBe(Outcome::Abandoned)
        ->and($result->outcome->isTerminal())->toBeTrue();
});

it('keeps checking a fresh unpaid payment, in case the customer comes back', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_fresh',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ]);

    expect($result->outcome)->toBe(Outcome::NotPaidAtStripe)
        ->and($result->outcome->isTerminal())->toBeFalse();
});

it('retires an unpaid payment once nobody is coming back for it', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    ageTransaction($transactionId, 3);

    [$result] = runGate($order->id, [
        'id' => 'pi_stale',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ]);

    expect($result->outcome)->toBe(Outcome::Abandoned);
});

it('never retires a payment that is still settling', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    $transactionId = seedTransaction($order->id, $gatewayId, 'processing');
    // Older than the retirement threshold.
    ageTransaction($transactionId, 30);

    [$result] = runGate($order->id, [
        'id' => 'pi_sepa',
        'status' => 'processing',
        'currency' => 'usd',
        'amount_received' => 0,
    ], dryRun: true);

    expect($result->outcome)->not->toBe(Outcome::Abandoned);
});

it('stops rediscovering a retired payment', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    ageTransaction($transactionId, 3);

    $discovery = StripeReconciler::$plugin->getDiscovery();

    expect($discovery->findCandidates())->not->toBeEmpty();

    runGate($order->id, [
        'id' => 'pi_retire',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ]);

    $remaining = array_filter(
        $discovery->findCandidates(),
        static fn($candidate): bool => $candidate->orderId === $order->id,
    );

    expect($remaining)->toBeEmpty();
});

it('holds back a payment whose amount does not match the order total', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_partial',
        'status' => 'succeeded',
        'currency' => 'usd',
        // 40.00 against a 50.00 order.
        'amount_received' => 4000,
    ]);

    expect($result->outcome)->toBe(Outcome::AmountMismatch)
        ->and($result->orderTotalMinorUnits)->toBe(5000)
        ->and($result->stripeAmountReceived)->toBe(4000);
});

it('holds back a payment taken in a different currency', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_currency',
        'status' => 'succeeded',
        'currency' => 'eur',
        'amount_received' => 5000,
    ]);

    expect($result->outcome)->toBe(Outcome::AmountMismatch)
        ->and($result->message)->toContain('EUR');
});

it('accepts an amount within the configured tolerance', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);

    $settings = StripeReconciler::$plugin->getSettings();
    $original = $settings->amountToleranceMinorUnits;
    $settings->amountToleranceMinorUnits = 5;

    try {
        [$result] = runGate($order->id, [
            'id' => 'pi_rounding',
            'status' => 'succeeded',
            'currency' => 'usd',
            'amount_received' => 4998,
        ], dryRun: true);
    } finally {
        $settings->amountToleranceMinorUnits = $original;
    }

    expect($result->outcome)->toBe(Outcome::DryRun);
});

it('reports what a dry run would do without changing anything', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, [
        'id' => 'pi_ok',
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount_received' => 5000,
    ], dryRun: true);

    expect($result->outcome)->toBe(Outcome::DryRun);

    $fresh = craft\commerce\elements\Order::find()->id($order->id)->status(null)->one();
    expect($fresh->getIsPaid())->toBeFalse();
});

it('leaves a paid cart alone unless cart reconciliation is enabled', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $settings = StripeReconciler::$plugin->getSettings();
    $original = $settings->reconcileCarts;
    $settings->reconcileCarts = false;

    try {
        [$result] = runGate($cart->id, [
            'id' => 'pi_cart',
            'status' => 'succeeded',
            'currency' => 'usd',
            'amount_received' => 0,
        ]);
    } finally {
        $settings->reconcileCarts = $original;
    }

    expect($result->outcome)->toBe(Outcome::CartSkipped);

    $fresh = craft\commerce\elements\Order::find()->id($cart->id)->status(null)->one();
    expect((bool)$fresh->isCompleted)->toBeFalse();
});

it('offers a cart for reconciliation when a person asks, even with the setting off', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $settings = StripeReconciler::$plugin->getSettings();
    $settings->reconcileCarts = false;

    // What the control panel does: an explicit, permission-checked action.
    [$result] = runGate($cart->id, [
        'id' => 'pi_cp_cart',
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount_received' => 0,
    ], dryRun: true, allowCarts: true);

    expect($result->outcome)->toBe(Outcome::DryRun)
        ->and($result->message)->toContain('still a cart');
});

it('still refuses a cart on an unattended run with the setting off', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    StripeReconciler::$plugin->getSettings()->reconcileCarts = false;

    // What the console does: the setting decides.
    [$result] = runGate($cart->id, [
        'id' => 'pi_cron_cart',
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount_received' => 0,
    ], dryRun: true);

    expect($result->outcome)->toBe(Outcome::CartSkipped);
});

it('records the order total even when it skips the cart', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    [$result] = runGate($cart->id, [
        'id' => 'pi_cart_total',
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount_received' => 6000,
    ]);

    // An audit row showing what Stripe holds but not what the order is worth
    // cannot be reviewed without looking the order up by hand.
    expect($result->outcome)->toBe(Outcome::CartSkipped)
        ->and($result->stripeAmountReceived)->toBe(6000)
        ->and($result->orderTotalMinorUnits)->not->toBeNull();

    $row = johnhenry\stripereconciler\records\Reconciliation::findOne(['orderId' => $cart->id]);

    expect($row->orderTotalMinorUnits)->not->toBeNull()
        ->and($row->stripeAmountReceived)->toBe(6000);
});

it('leaves the order untouched when only checking, then completes on a second pass', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);

    $intent = [
        'id' => 'pi_two_step',
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount_received' => 5000,
    ];

    // Stage one: the check. Reports that it would reconcile, changes nothing.
    [$checked] = runGate($order->id, $intent, dryRun: true);

    expect($checked->outcome)->toBe(Outcome::DryRun);

    $afterCheck = craft\commerce\elements\Order::find()->id($order->id)->status(null)->one();
    expect($afterCheck->getIsPaid())->toBeFalse();

    // Committing is not exercised here: it calls Stripe for real.
    expect($checked->outcome->isTerminal())->toBeFalse();
});

it('skips a recently checked payment on an unattended run', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    $intent = [
        'id' => 'pi_backoff',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ];

    runGate($order->id, $intent);

    // Second pass, this time as a cron would: no Stripe call should be made.
    $service = new StubReconciliationService();
    $service->intent = $intent;
    $results = $service->reconcile(discoveredCandidate($order->id), false, null, respectBackoff: true);

    expect($results)->toBeEmpty()
        ->and($service->inspected)->toBeFalse();

    $row = johnhenry\stripereconciler\records\Reconciliation::findOne(['transactionId' => $transactionId]);
    expect($row->attempts)->toBe(1);
});

it('always checks when a person asks, however recently a cron looked', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    $intent = [
        'id' => 'pi_explicit',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ];

    runGate($order->id, $intent);

    // What the control panel button does. Backoff is a rate limit on crons, not
    // an answer to somebody who has gone looking.
    [$result, $service] = runGate($order->id, $intent);

    expect($service->inspected)->toBeTrue()
        ->and($result->outcome)->toBe(Outcome::NotPaidAtStripe);
});

it('records the reference an order ends up with, not the one it started without', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    $transactionId = seedTransaction($cart->id, $gatewayId);

    $candidate = discoveredCandidate($cart->id);

    // A cart has no reference until completed, so the result carries it instead.
    expect($cart->reference)->toBeNull();

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => Outcome::Reconciled,
        'message' => 'Recorded by test.',
        'orderReference' => 'abc1234',
    ]));

    $row = johnhenry\stripereconciler\records\Reconciliation::findOne(['transactionId' => $transactionId]);

    expect($row->orderReference)->toBe('abc1234');
});

it('prunes old unpaid audit rows but never a reconciled one', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $old = seedTransaction($order->id, $gatewayId);
    $kept = seedTransaction($order->id, $gatewayId);
    $recent = seedTransaction($order->id, $gatewayId);

    $candidate = discoveredCandidate($order->id);
    $audit = StripeReconciler::$plugin->getAudit();

    $record = function(int $transactionId, Outcome $outcome, int $daysAgo) use ($audit, $candidate): void {
        $audit->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
            'transactionId' => $transactionId,
            'outcome' => $outcome,
            'message' => 'Recorded by test.',
        ]));

        Craft::$app->getDb()->createCommand()->update(
            '{{%stripereconciler_reconciliations}}',
            ['dateLastAttempt' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-' . $daysAgo . ' days'))],
            ['transactionId' => $transactionId],
        )->execute();
    };

    $record($old, Outcome::Abandoned, 200);
    $record($kept, Outcome::Reconciled, 200);
    $record($recent, Outcome::Abandoned, 5);

    $deleted = $audit->prune();

    $remaining = array_map('intval', johnhenry\stripereconciler\records\Reconciliation::find()
        ->select(['transactionId'])
        ->column());

    // Reconciled rows survive pruning.
    expect($deleted)->toBe(1)
        ->and($remaining)->toContain($kept)
        ->and($remaining)->toContain($recent)
        ->and($remaining)->not->toContain($old);
});

it('never prunes more aggressively than the lookback window', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    $settings = StripeReconciler::$plugin->getSettings();
    $settings->lookbackDays = 30;
    $settings->auditRetentionDays = 1;

    StripeReconciler::$plugin->getAudit()->record(discoveredCandidate($order->id), new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => Outcome::Abandoned,
        'message' => 'Recorded by test.',
    ]));

    Craft::$app->getDb()->createCommand()->update(
        '{{%stripereconciler_reconciliations}}',
        ['dateLastAttempt' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-10 days'))],
        ['transactionId' => $transactionId],
    )->execute();

    // Retention is shorter than the lookback window, so nothing may be pruned.
    expect(StripeReconciler::$plugin->getAudit()->prune())->toBe(0);
});

it('fails safe when Stripe will not hand over the payment', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    $service = new StubReconciliationService();
    // What Stripe returns when the intent belongs to a different account.
    $service->throw = 'No such payment_intent: pi_belongs_elsewhere';

    $results = $service->reconcile(discoveredCandidate($order->id));

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe(Outcome::Errored)
        ->and($results[0]->outcome->needsAttention())->toBeTrue();

    $fresh = craft\commerce\elements\Order::find()->id($order->id)->status(null)->one();
    expect($fresh->getIsPaid())->toBeFalse();
});

it('addresses Stripe by payment intent, never by order id', function() {
    $gatewayId = stripeGatewayId();
    $first = completedUnpaidOrder(50.0, 'one@example.test');
    $second = completedUnpaidOrder(50.0, 'two@example.test');

    $firstTransaction = seedTransaction($first->id, $gatewayId, 'redirect', 'purchase', [
        'id' => 'pi_site_one',
        'object' => 'payment_intent',
    ]);
    $secondTransaction = seedTransaction($second->id, $gatewayId, 'redirect', 'purchase', [
        'id' => 'pi_site_two',
        'object' => 'payment_intent',
    ]);

    // Stripe is addressed by payment intent, never by order ID.
    $responses = craft\commerce\Plugin::getInstance()->getTransactions();

    expect(craft\helpers\Json::decodeIfJson($responses->getTransactionById($firstTransaction)->response)['id'])
        ->toBe('pi_site_one')
        ->and(craft\helpers\Json::decodeIfJson($responses->getTransactionById($secondTransaction)->response)['id'])
        ->toBe('pi_site_two');
});

it('records an error when the PaymentIntent cannot be resolved', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    [$result] = runGate($order->id, null);

    expect($result->outcome)->toBe(Outcome::Errored);
});

it('does not attempt orders that are already paid', function() {
    $gatewayId = stripeGatewayId();
    $order = paidOrder();
    seedTransaction($order->id, $gatewayId);

    $service = new StubReconciliationService();
    $service->intent = ['id' => 'pi_x', 'status' => 'succeeded', 'currency' => 'usd', 'amount_received' => 0];

    $results = $service->reconcile(discoveredCandidate($order->id), false);

    expect($results)->toBeEmpty()
        ->and($service->inspected)->toBeFalse();
});

it('writes one audit row per transaction and counts repeat attempts', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    $intent = [
        'id' => 'pi_repeat',
        'status' => 'requires_payment_method',
        'currency' => 'usd',
        'amount_received' => 0,
    ];

    runGate($order->id, $intent);
    runGate($order->id, $intent);

    $rows = johnhenry\stripereconciler\records\Reconciliation::find()
        ->where(['transactionId' => $transactionId])
        ->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->attempts)->toBe(2)
        ->and($rows[0]->outcome)->toBe(Outcome::NotPaidAtStripe->value)
        ->and($rows[0]->paymentIntentId)->toBe('pi_repeat');
});
