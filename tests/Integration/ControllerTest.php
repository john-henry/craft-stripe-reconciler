<?php

/**
 * The control panel actions: who may call them, and which answer comes back
 * for an order with more than one payment attempt.
 */

use johnhenry\stripereconciler\StripeReconciler;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\ForbiddenHttpException;

it('refuses a user without the reconcile permission', function(string $action) {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    seedTransaction($order->id, $gatewayId);
    $this->actingAs(UserFactory::factory()->create());

    expect(fn() => $this->postJson('admin/actions/stripe-reconciler/reconcile/' . $action, ['orderId' => $order->id]))
        ->toThrow(ForbiddenHttpException::class);
})->with(['check', 'commit', 'all']);

it('refuses a signed-out request', function() {
    $order = completedUnpaidOrder(50.0);

    $response = null;

    try {
        $response = $this->postJson('admin/actions/stripe-reconciler/reconcile/check', ['orderId' => $order->id]);
    } catch (ForbiddenHttpException) {
        $response = 'forbidden';
    }

    expect($response === 'forbidden' || $response->getStatusCode() !== 200)->toBeTrue();
});

it('reports a paid attempt ahead of a later abandoned one', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder(50.0);
    $paid = seedTransaction($order->id, $gatewayId);
    $abandoned = seedTransaction($order->id, $gatewayId);

    $service = new StubReconciliationService();
    $service->intents = [
        $paid => ['id' => 'pi_paid', 'status' => 'succeeded', 'currency' => 'usd', 'amount_received' => 5000],
        $abandoned => ['id' => 'pi_later', 'status' => 'requires_payment_method', 'currency' => 'usd', 'amount_received' => 0],
    ];
    StripeReconciler::$plugin->set('reconciliation', $service);

    $this->actingAsAdmin();

    $body = json_decode($this->postJson('admin/actions/stripe-reconciler/reconcile/check', ['orderId' => $order->id])->content, true);

    expect($body['success'])->toBeTrue()
        ->and($body['reconcilable'])->toBeTrue()
        ->and($body['outcome'])->toBe('dryRun');
});
