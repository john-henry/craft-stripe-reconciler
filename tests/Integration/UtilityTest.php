<?php

use craft\web\View;
use johnhenry\stripereconciler\StripeReconciler;
use johnhenry\stripereconciler\utilities\ReconcilerUtility;

/**
 * Renders the utility in control panel template mode.
 *
 * `contentHtml()` is only ever called from a CP request, where the view is
 * already in CP mode. A test has to put it there itself or the template root
 * will not resolve.
 *
 * @return string The rendered HTML.
 */
function renderUtility(): string
{
    $view = Craft::$app->getView();
    $original = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);

    try {
        return ReconcilerUtility::contentHtml();
    } finally {
        $view->setTemplateMode($original);
    }
}

it('is registered with Craft', function() {
    $types = Craft::$app->getUtilities()->getAllUtilityTypes();

    expect($types)->toContain(ReconcilerUtility::class);
});

it('exposes a stable id and display name', function() {
    expect(ReconcilerUtility::id())->toBe('stripe-reconciler')
        ->and(ReconcilerUtility::displayName())->toBe('Stripe Reconciler');
});

it('wears the plugin icon rather than a generic system glyph', function() {
    $icon = ReconcilerUtility::icon();

    expect($icon)->toEndWith('icon-mask.svg')
        ->and(is_file($icon))->toBeTrue();
});

it('renders the empty state when nothing is outstanding', function() {
    onlyGateway(stripeGatewayId());
    Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

    $html = renderUtility();

    expect($html)->toContain('Nothing outstanding');
});

it('says so when there is no Stripe gateway at all, rather than claiming all is well', function() {
    $settings = StripeReconciler::$plugin->getSettings();
    $original = $settings->enabledGateways;
    // No Stripe gateway is in scope, standing in for a store that has not set one
    // up. The two states must not read the same to an operator.
    $settings->enabledGateways = [999999];

    try {
        $html = renderUtility();
    } finally {
        $settings->enabledGateways = $original;
    }

    expect($html)->toContain('No Stripe gateway is available')
        ->and($html)->not->toContain('Nothing outstanding');
});

it('lists an abandoned cart with a link to the order', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $html = renderUtility();

    expect($html)->toContain('Abandoned cart')
        ->and($html)->toContain('commerce/orders/' . $cart->id);
});

it('renders the buttons its JavaScript binds to, for a user who may reconcile', function() {
    $this->actingAsAdmin();

    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $html = renderUtility();

    // The hooks the utility's JavaScript binds to.
    expect($html)->toContain('data-sr-check')
        ->and($html)->toContain('data-order-id="' . $cart->id . '"')
        ->and($html)->toContain('data-sr-check-all')
        ->and($html)->toContain('Check Stripe');
});

it('still lists an outstanding payment that was checked minutes ago', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $cart = cartOrder();
    $transactionId = seedTransaction($cart->id, $gatewayId);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

    $html = renderUtility();

    // The page must never claim an all clear while something is still outstanding
    // just because a cron happened to look at it recently.
    expect($html)->not->toContain('Nothing outstanding')
        ->and($html)->toContain('Abandoned cart')
        ->and(ReconcilerUtility::badgeCount())->toBe(1);
});

it('carries the staleness hook its JavaScript reveals after an action', function() {
    $this->actingAsAdmin();

    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $cart = cartOrder();
    $transactionId = seedTransaction($cart->id, $gatewayId);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    $html = renderUtility();

    expect($html)->toContain('data-sr-stale')
        ->and($html)->toContain('Recent attempts');
});

it('identifies a cart by its short number, and links both tables to the order', function() {
    $this->actingAsAdmin();

    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $cart = cartOrder();
    $transactionId = seedTransaction($cart->id, $gatewayId);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    $html = renderUtility();
    $shortNumber = $cart->getShortNumber();
    $orderUrl = 'commerce/orders/' . $cart->id;

    // A cart has no reference, so the short number stands in.
    expect($html)->toContain($shortNumber)
        ->and($html)->not->toContain('#' . $cart->id)
        // Both the candidate row and the history row link through to the order.
        ->and(substr_count($html, $orderUrl))->toBeGreaterThanOrEqual(2);
});

it('describes outcomes in words a shop manager can act on', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $cart = cartOrder();
    $transactionId = seedTransaction($cart->id, $gatewayId);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];

    StripeReconciler::$plugin->getAudit()->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $transactionId,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    $html = renderUtility();

    expect($html)->toContain('No money taken')
        ->and($html)->not->toContain('notPaidAtStripe')
        ->and($html)->toContain('Times checked')
        ->and($html)->not->toContain('>Tries<');
});

it('tells two payment attempts on one order apart', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $order = completedUnpaidOrder();

    $first = seedTransaction($order->id, $gatewayId, 'redirect', 'purchase', [
        'id' => 'pi_attempt_one',
        'object' => 'payment_intent',
    ]);
    $second = seedTransaction($order->id, $gatewayId, 'redirect', 'purchase', [
        'id' => 'pi_attempt_two',
        'object' => 'payment_intent',
    ]);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];
    $audit = StripeReconciler::$plugin->getAudit();

    $audit->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $first,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'paymentIntentId' => 'pi_attempt_one',
        'message' => 'Recorded by test.',
    ]));
    $audit->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $second,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::DryRun,
        'paymentIntentId' => 'pi_attempt_two',
        'message' => 'Recorded by test.',
    ]));

    $html = renderUtility();

    expect($html)->toContain('pi_attempt_one')
        ->and($html)->toContain('pi_attempt_two')
        ->and($html)->toContain('One row per payment attempt');
});

it('colours each outcome so the column can be read at a glance', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $order = completedUnpaidOrder();

    $paid = seedTransaction($order->id, $gatewayId, 'redirect', 'purchase', ['id' => 'pi_green', 'object' => 'payment_intent']);
    $dead = seedTransaction($order->id, $gatewayId, 'redirect', 'purchase', ['id' => 'pi_grey', 'object' => 'payment_intent']);

    $candidate = StripeReconciler::$plugin->getDiscovery()->findCandidates()[0];
    $audit = StripeReconciler::$plugin->getAudit();

    $audit->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $paid,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::Reconciled,
        'message' => 'Recorded by test.',
    ]));
    $audit->record($candidate, new johnhenry\stripereconciler\models\ReconciliationResult([
        'transactionId' => $dead,
        'outcome' => johnhenry\stripereconciler\enums\Outcome::NotPaidAtStripe,
        'message' => 'Recorded by test.',
    ]));

    $html = renderUtility();

    expect($html)->toContain('status green')
        ->and($html)->toContain('status grey');
});

it('maps every outcome to a colour', function() {
    // The match has no default, so a new case fails here rather than render blank.
    foreach (johnhenry\stripereconciler\enums\Outcome::cases() as $case) {
        expect($case->statusColour())->toBeIn(['green', 'orange', 'red', 'grey']);
    }
});

it('hides the buttons from a user who may not reconcile', function() {
    $gatewayId = stripeGatewayId();
    $cart = cartOrder();
    seedTransaction($cart->id, $gatewayId);

    $html = renderUtility();

    expect($html)->not->toContain('data-sr-check')
        ->and($html)->toContain('Not checked');
});

it('lists an unpaid completed order', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    $html = renderUtility();

    expect($html)->toContain('Unpaid completed order')
        ->and($html)->toContain($order->reference);
});

it('does not list orders that are already paid', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    $order = paidOrder();
    seedTransaction($order->id, $gatewayId);

    $html = renderUtility();

    expect($html)->toContain('Nothing outstanding');
});

it('counts actionable candidates for the nav badge', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

    expect(ReconcilerUtility::badgeCount())->toBe(0);

    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);
    Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

    expect(ReconcilerUtility::badgeCount())->toBe(1);
});

it('caches the badge count so the nav does not re-query on every request', function() {
    $gatewayId = stripeGatewayId();
    onlyGateway($gatewayId);
    Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

    expect(ReconcilerUtility::badgeCount())->toBe(0);

    $order = completedUnpaidOrder();
    seedTransaction($order->id, $gatewayId);

    expect(ReconcilerUtility::badgeCount())->toBe(0);
});
