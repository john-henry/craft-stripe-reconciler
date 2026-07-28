<?php

use johnhenry\stripereconciler\enums\CandidateType;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\StripeReconciler;

/**
 * Returns the candidate for a given order ID, or null.
 *
 * @param Candidate[] $candidates The candidates.
 * @param int $orderId The order to look for.
 * @return Candidate|null The match.
 */
function candidateFor(array $candidates, int $orderId): ?Candidate
{
    foreach ($candidates as $candidate) {
        if ($candidate->orderId === $orderId) {
            return $candidate;
        }
    }

    return null;
}

it('finds Stripe gateways by class rather than by handle', function() {
    $gatewayId = stripeGatewayId('notCalledStripe');

    $gateways = StripeReconciler::$plugin->getDiscovery()->getStripeGateways();

    expect($gateways)->toHaveKey($gatewayId);
});

it('ignores non-Stripe gateways', function() {
    stripeGatewayId();
    $dummyId = dummyGatewayId();

    $gateways = StripeReconciler::$plugin->getDiscovery()->getStripeGateways();

    expect($dummyId)->not->toBeNull()
        ->and($gateways)->not->toHaveKey($dummyId);
});

it('honours the enabledGateways allow list', function() {
    $first = stripeGatewayId('stripeOne');
    $second = stripeGatewayId('stripeTwo');

    $settings = StripeReconciler::$plugin->getSettings();
    $original = $settings->enabledGateways;
    $settings->enabledGateways = [$second];

    try {
        $gateways = StripeReconciler::$plugin->getDiscovery()->getStripeGateways();
    } finally {
        $settings->enabledGateways = $original;
    }

    expect($gateways)->toHaveKey($second)
        ->and($gateways)->not->toHaveKey($first);
});

it('classifies an abandoned cart rather than discarding it', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();
    $candidate = candidateFor($candidates, $cart->id);

    expect($candidate)->not->toBeNull()
        ->and($candidate->type)->toBe(CandidateType::AbandonedCart)
        ->and($candidate->type->isActionable())->toBeTrue();
});

it('classifies a completed order with an outstanding balance as unpaid', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();
    $candidate = candidateFor($candidates, $order->id);

    expect($candidate)->not->toBeNull()
        ->and($candidate->type)->toBe(CandidateType::UnpaidCompletedOrder)
        ->and($candidate->type->isActionable())->toBeTrue();
});

it('labels a cart by its short number rather than its element id', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $candidate = candidateFor(StripeReconciler::$plugin->getDiscovery()->findCandidates(), $cart->id);

    expect($cart->reference)->toBeNull()
        ->and($candidate->getLabel())->toBe($cart->getShortNumber())
        ->and($candidate->getLabel())->not->toBe('#' . $cart->id);
});

it('labels a completed order by its reference', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    $candidate = candidateFor(StripeReconciler::$plugin->getDiscovery()->findCandidates(), $order->id);

    // Reference wins where there is one; short number only stands in.
    expect($order->reference)->not->toBeNull()
        ->and($candidate->getLabel())->toBe($order->reference);
});

it('leaves already paid orders out of a general sweep', function() {
    $gatewayId = stripeGatewayId();
    $order = paidOrder();
    seedTransaction($order->id, $gatewayId);

    // Commerce leaves the parent transaction in "redirect" permanently.
    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();

    expect(candidateFor($candidates, $order->id))->toBeNull();
});

it('still explains an already paid order when asked about it directly', function() {
    $gatewayId = stripeGatewayId();
    $order = paidOrder();
    seedTransaction($order->id, $gatewayId);

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $order->id);
    $candidate = candidateFor($candidates, $order->id);

    expect($candidate)->not->toBeNull()
        ->and($candidate->type)->toBe(CandidateType::AlreadyPaid)
        ->and($candidate->type->isActionable())->toBeFalse();
});

it('discovers processing transactions, not just redirects', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId, 'processing');

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();

    expect(candidateFor($candidates, $order->id))->not->toBeNull();
});

it('ignores transactions that already reached a final state', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId, 'failed');

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();

    expect(candidateFor($candidates, $order->id))->toBeNull();
});

it('ignores transactions belonging to a non-Stripe gateway', function() {
    stripeGatewayId();
    $dummyId = dummyGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $dummyId);

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();

    expect(candidateFor($candidates, $order->id))->toBeNull();
});

it('skips orders that were already reconciled', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    $discovery = StripeReconciler::$plugin->getDiscovery();

    expect(candidateFor($discovery->findCandidates(), $order->id))->not->toBeNull();

    $candidate = candidateFor($discovery->findCandidates(), $order->id);
    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => Outcome::Reconciled,
        'message' => 'Recorded by test.',
    ]));

    expect(candidateFor($discovery->findCandidates(), $order->id))->toBeNull();
});

it('keeps listing an outstanding payment it checked moments ago', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    $discovery = StripeReconciler::$plugin->getDiscovery();
    $candidate = candidateFor($discovery->findCandidates(), $order->id);

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    // The backoff limits Stripe calls; it must never hide outstanding work.
    expect(candidateFor($discovery->findCandidates(), $order->id))->not->toBeNull();
});

it('keeps an order whose other payment is still worth checking', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $retired = seedTransaction($order->id, $gatewayId);
    $live = seedTransaction($order->id, $gatewayId);

    $discovery = StripeReconciler::$plugin->getDiscovery();
    $candidate = candidateFor($discovery->findCandidates(), $order->id);

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $retired,
        'outcome' => Outcome::Abandoned,
        'message' => 'Recorded by test.',
    ]));

    // Filtering per order would lose the live payment with the dead one.
    $fresh = candidateFor($discovery->findCandidates(), $order->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->transactionIds)->toBe([$live]);
});

it('excludes candidates outside the lookback window', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    Craft::$app->getDb()->createCommand()->update(
        '{{%commerce_transactions}}',
        ['dateCreated' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-90 days'))],
        ['id' => $transactionId],
    )->execute();

    $discovery = StripeReconciler::$plugin->getDiscovery();

    expect(candidateFor($discovery->findCandidates(lookbackDays: 14), $order->id))->toBeNull()
        ->and(candidateFor($discovery->findCandidates(lookbackDays: 120), $order->id))->not->toBeNull();
});

it('ignores the lookback window when given an explicit order', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);

    Craft::$app->getDb()->createCommand()->update(
        '{{%commerce_transactions}}',
        ['dateCreated' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-90 days'))],
        ['id' => $transactionId],
    )->execute();

    $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(lookbackDays: 14, orderId: $order->id);

    expect(candidateFor($candidates, $order->id))->not->toBeNull();
});
