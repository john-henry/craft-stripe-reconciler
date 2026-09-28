<?php

/**
 * The paths that change money: completing a payment, and the cases where
 * Stripe's answer must stop that from happening.
 *
 * Commerce's own completion calls the gateway, which calls Stripe, so it's
 * replaced by a fake that records the call and stands in for what Commerce
 * would do. That keeps the tests on the plugin's decisions: whether it calls
 * Commerce at all, and what it records afterwards.
 */

use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Payments;
use johnhenry\stripereconciler\enums\CandidateType;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\jobs\ReconcileOrders;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\records\Reconciliation;
use johnhenry\stripereconciler\StripeReconciler;
use Stripe\Exception\InvalidRequestException;

/**
 * Stands in for Commerce's payment completion.
 */
class FakePayments extends Payments
{
    /**
     * @var int How many times completion was asked for.
     */
    public int $calls = 0;

    /**
     * @var (callable(Transaction): void)|null What Commerce would have done.
     */
    public $then = null;

    /**
     * @inheritdoc
     */
    public function completePayment(Transaction $transaction, ?string &$customError): bool
    {
        $this->calls++;

        if ($this->then !== null) {
            ($this->then)($transaction);
        }

        return true;
    }
}

/**
 * Swaps in the fake and returns it.
 */
function fakePayments(?callable $then = null): FakePayments
{
    $fake = new FakePayments();
    $fake->then = $then;
    Commerce::getInstance()->set('payments', $fake);

    return $fake;
}

/**
 * What Commerce does when a payment succeeds: a successful child transaction,
 * then the order's paid information brought up to date.
 */
function paySuccessfully(Transaction $transaction): void
{
    seedTransaction(
        (int)$transaction->orderId,
        (int)$transaction->gatewayId,
        'success',
        'purchase',
        null,
        (int)$transaction->id,
        (float)$transaction->amount,
    );

    $order = Order::find()->id($transaction->orderId)->status(null)->one();
    $order->isCompleted = true;
    $order->updateOrderPaidInformation();
}

/**
 * A paid PaymentIntent with its latest charge expanded.
 *
 * @param array<string, mixed> $charge Overrides for the charge.
 * @return array<string, mixed>
 */
function paidIntent(int $amount = 5000, array $charge = []): array
{
    return [
        'id' => 'pi_paid_' . mt_rand(),
        'status' => 'succeeded',
        'currency' => 'usd',
        'amount' => $amount,
        'amount_received' => $amount,
        'latest_charge' => array_merge(['amount_refunded' => 0, 'refunded' => false, 'disputed' => false], $charge),
    ];
}

describe('A payment Stripe never took', function() {
    it('never reaches Commerce, however many times it is checked, so it adds no transactions', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments();
        $unpaid = ['id' => 'pi_never', 'status' => 'requires_payment_method', 'currency' => 'usd', 'amount_received' => 0];

        foreach (range(1, 5) as $run) {
            runGate($order->id, $unpaid);
        }

        $transactions = (new craft\db\Query())->from('{{%commerce_transactions}}')->where(['orderId' => $order->id])->count();

        expect($payments->calls)->toBe(0)
            ->and((int)$transactions)->toBe(1);
    });
});

describe('A refunded or disputed payment', function() {
    it('is left alone, not completed, and not checked again', function(array $charge) {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments(paySuccessfully(...));

        [$result] = runGate($order->id, paidIntent(5000, $charge));

        expect($result->outcome)->toBe(Outcome::RefundedOrDisputed)
            ->and($result->outcome->isTerminal())->toBeTrue()
            ->and($payments->calls)->toBe(0)
            ->and(Order::find()->id($order->id)->status(null)->one()->getIsPaid())->toBeFalse();
    })->with([
        'refunded in full' => [['amount_refunded' => 5000, 'refunded' => true]],
        'refunded in part' => [['amount_refunded' => 100]],
        'disputed' => [['disputed' => true]],
    ]);
});

describe('An order refunded in Commerce', function() {
    it('is not offered for completion, even though it owes money again', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $parent = seedTransaction($order->id, $gatewayId);
        $paid = seedTransaction($order->id, $gatewayId, 'success', 'purchase', null, $parent);
        seedTransaction($order->id, $gatewayId, 'success', 'refund', null, $paid);

        $order = Order::find()->id($order->id)->status(null)->one();
        $order->updateOrderPaidInformation();

        $orderIds = array_map(
            static fn($candidate): int => $candidate->orderId,
            StripeReconciler::$plugin->getDiscovery()->findCandidates(),
        );

        expect($order->isCompleted)->toBeTrue()
            ->and($order->getIsPaid())->toBeFalse()
            ->and($orderIds)->not->toContain($order->id);
    });
});

describe('A payment Stripe is still settling', function() {
    it('leaves a completed order for Stripe to settle, without asking Commerce', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments();

        [$result] = runGate($order->id, ['id' => 'pi_sepa', 'status' => 'processing', 'currency' => 'usd', 'amount' => 5000]);

        expect($result->outcome)->toBe(Outcome::StillProcessing)
            ->and($payments->calls)->toBe(0);
    });

    it('completes a cart but records it as still settling, not paid', function() {
        $gatewayId = stripeGatewayId();
        $cart = cartOrder(total: 50.0);
        seedTransaction($cart->id, $gatewayId);
        $payments = fakePayments(function(Transaction $transaction): void {
            $order = Order::find()->id($transaction->orderId)->status(null)->one();
            $order->isCompleted = true;
            Craft::$app->getElements()->saveElement($order, false);
        });

        [$result] = runGate($cart->id, ['id' => 'pi_sepa_cart', 'status' => 'processing', 'currency' => 'usd', 'amount' => 5000], allowCarts: true);

        expect($payments->calls)->toBe(1)
            ->and($result->outcome)->toBe(Outcome::StillProcessing)
            ->and($result->outcome->isTerminal())->toBeFalse();
    });

    it('is checked against the amount it will settle for', function() {
        $gatewayId = stripeGatewayId();
        $cart = cartOrder(total: 50.0);
        seedTransaction($cart->id, $gatewayId);
        $payments = fakePayments();

        [$result] = runGate($cart->id, ['id' => 'pi_sepa_short', 'status' => 'processing', 'currency' => 'usd', 'amount' => 1000], allowCarts: true);

        expect($result->outcome)->toBe(Outcome::AmountMismatch)
            ->and($payments->calls)->toBe(0);
    });

    it('is never found again through the child transactions Commerce adds', function(string $childStatus) {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $parent = seedTransaction($order->id, $gatewayId);
        seedTransaction($order->id, $gatewayId, $childStatus, 'purchase', null, $parent);
        seedTransaction($order->id, $gatewayId, $childStatus, 'purchase', null, $parent);

        $candidate = discoveredCandidate($order->id);

        expect($candidate->transactionIds)->toBe([$parent]);
    })->with(['processing', 'redirect']);

    it('keeps a bank transfer or voucher open while the customer can still pay', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $transactionId = seedTransaction($order->id, $gatewayId);
        ageTransaction($transactionId, 5);

        [$result] = runGate($order->id, [
            'id' => 'pi_transfer',
            'status' => 'requires_action',
            'currency' => 'usd',
            'amount_received' => 0,
            'next_action' => ['type' => 'display_bank_transfer_instructions'],
        ]);

        expect($result->outcome)->toBe(Outcome::StillProcessing);
    });
});

describe('A paid payment', function() {
    it('is completed, and recorded once the order reads as paid', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments(paySuccessfully(...));

        [$result] = runGate($order->id, paidIntent());

        expect($payments->calls)->toBe(1)
            ->and($result->outcome)->toBe(Outcome::Reconciled)
            ->and(Order::find()->id($order->id)->status(null)->one()->getIsPaid())->toBeTrue();
    });

    it('is reported when Commerce still does not mark the order paid', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        fakePayments();

        [$result] = runGate($order->id, paidIntent());

        expect($result->outcome)->toBe(Outcome::CompletionFailed);
    });

    it('is not handed to Commerce again by an unattended run once Commerce failed to complete it', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $transactionId = seedTransaction($order->id, $gatewayId);
        ageTransaction($transactionId, 1);
        $payments = fakePayments();

        [$first] = runGate($order->id, paidIntent());
        Craft::$app->getDb()->createCommand()->update(
            Reconciliation::tableName(),
            ['dateLastAttempt' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-2 hours'))],
            ['transactionId' => $transactionId],
        )->execute();

        $service = new StubReconciliationService();
        $service->intent = paidIntent();
        $again = $service->reconcile(discoveredCandidate($order->id), false, null, respectBackoff: true);

        expect($first->outcome)->toBe(Outcome::CompletionFailed)
            ->and($again[0]->outcome)->toBe(Outcome::CompletionFailed)
            ->and($payments->calls)->toBe(1);
    });

    it('settles a transaction Commerce left in processing, which it will not complete itself', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $transactionId = seedTransaction($order->id, $gatewayId, 'processing');
        $payments = fakePayments();

        [$result] = runGate($order->id, paidIntent());

        expect($payments->calls)->toBe(0)
            ->and($result->outcome)->toBe(Outcome::Reconciled)
            ->and(Commerce::getInstance()->getTransactions()->getTransactionById($transactionId)->status)->toBe('success');
    });

    it('is compared with what Commerce asked for in the payment currency', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId, 'redirect', 'purchase', null, null, 50.0, 'EUR', 46.0);

        [$result] = runGate($order->id, ['id' => 'pi_eur', 'status' => 'succeeded', 'currency' => 'eur', 'amount_received' => 4600], dryRun: true);

        expect($result->outcome)->toBe(Outcome::DryRun)
            ->and($result->orderTotalMinorUnits)->toBe(4600);
    });

    it('is held when the order changed after the payment started', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(80.0);
        seedTransaction($order->id, $gatewayId);

        [$result] = runGate($order->id, paidIntent(5000), dryRun: true);

        expect($result->outcome)->toBe(Outcome::AmountMismatch)
            ->and($result->message)->toContain('changed');
    });

    it('is refused when Stripe says it belongs to another transaction', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments(paySuccessfully(...));

        $intent = paidIntent();
        $intent['metadata'] = ['transaction_reference' => 'someone-elses-hash'];

        [$result] = runGate($order->id, $intent);

        expect($result->outcome)->toBe(Outcome::Errored)
            ->and($payments->calls)->toBe(0);
    });
});

describe('An extra attempt on an order that is already paid', function() {
    it('is flagged as a possible double charge, never completed, and kept off the list', function() {
        $gatewayId = stripeGatewayId();
        $order = paidOrder();
        seedTransaction($order->id, $gatewayId);
        $payments = fakePayments(paySuccessfully(...));

        $candidate = discoveredCandidate($order->id);
        [$result] = runGate($order->id, paidIntent(0));

        expect($candidate->type)->toBe(CandidateType::ExtraAttempt)
            ->and($candidate->type->isListed())->toBeFalse()
            ->and($result->outcome)->toBe(Outcome::PossibleDoubleCharge)
            ->and($payments->calls)->toBe(0);
    });

    it('is not checked when the order was paid by that same attempt', function() {
        $gatewayId = stripeGatewayId();
        $order = paidOrder();
        $parent = seedTransaction($order->id, $gatewayId);
        seedTransaction($order->id, $gatewayId, 'success', 'purchase', null, $parent);

        expect(StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $order->id))->toBeEmpty();
    });
});

describe('An order that is out of scope', function() {
    it('is left alone once it has been deleted', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);
        Craft::$app->getElements()->deleteElement($order);

        $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $order->id);

        expect($candidates)->toHaveCount(1)
            ->and($candidates[0]->type)->toBe(CandidateType::OrderMissing)
            ->and($candidates[0]->type->isActionable())->toBeFalse();
    });

    it('is left alone while it is authorised and waiting to be captured', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $order->dateAuthorized = new DateTime();
        Craft::$app->getElements()->saveElement($order, false);
        seedTransaction($order->id, $gatewayId, 'redirect', 'authorize');

        $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $order->id);

        expect($candidates[0]->type)->toBe(CandidateType::AwaitingCapture)
            ->and($candidates[0]->type->isActionable())->toBeFalse();
    });
});

describe('A payment Stripe has no record of', function() {
    it('is closed rather than reported as an error on every run', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder();
        seedTransaction($order->id, $gatewayId);

        $service = new StubReconciliationService();
        $service->throwable = InvalidRequestException::factory('No such payment_intent', 404, null, null, null, 'resource_missing');

        $results = $service->reconcile(discoveredCandidate($order->id));

        expect($results[0]->outcome)->toBe(Outcome::MissingAtStripe)
            ->and($results[0]->outcome->isTerminal())->toBeTrue();
    });
});

describe('A Checkout Session the customer never confirmed', function() {
    it('reads as abandoned once it has expired, and as unpaid while it is open', function() {
        $service = new StubReconciliationService();

        expect($service->describeSession(['id' => 'cs_1', 'status' => 'expired', 'payment_intent' => null])['status'])->toBe('canceled')
            ->and($service->describeSession(['id' => 'cs_2', 'status' => 'open', 'payment_intent' => null])['status'])->toBe('requires_payment_method');
    });
});

describe('Unattended runs', function() {
    it('are not held off by a dry run', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $transactionId = seedTransaction($order->id, $gatewayId);
        ageTransaction($transactionId, 1);

        runGate($order->id, paidIntent(), dryRun: true);

        $service = new StubReconciliationService();
        $service->intent = paidIntent();
        fakePayments(paySuccessfully(...));

        $results = $service->reconcile(discoveredCandidate($order->id), false, null, respectBackoff: true);

        expect($service->inspected)->toBeTrue()
            ->and($results[0]->outcome)->toBe(Outcome::Reconciled);
    });

    it('leave a payment alone for its first few minutes', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        seedTransaction($order->id, $gatewayId);

        $service = new StubReconciliationService();
        $service->intent = paidIntent();

        $results = $service->reconcile(discoveredCandidate($order->id), false, null, respectBackoff: true);

        expect($results)->toBeEmpty()
            ->and($service->inspected)->toBeFalse();
    });
});

describe('The notification email', function() {
    it('mentions a payment once, and again only when its outcome changes', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder(50.0);
        $transactionId = seedTransaction($order->id, $gatewayId);
        $candidate = discoveredCandidate($order->id);
        $audit = StripeReconciler::$plugin->getAudit();

        $item = function(Outcome $outcome) use ($candidate, $transactionId, $audit): array {
            $result = new ReconciliationResult(['transactionId' => $transactionId, 'outcome' => $outcome]);
            $audit->record($candidate, $result);

            return ['candidate' => $candidate, 'result' => $result];
        };

        $first = $item(Outcome::AmountMismatch);
        expect($audit->filterUnnotified([$first]))->toHaveCount(1);
        $audit->markNotified([$first]);

        expect($audit->filterUnnotified([$item(Outcome::AmountMismatch)]))->toBeEmpty()
            ->and($audit->filterUnnotified([$item(Outcome::CompletionFailed)]))->toHaveCount(1)
            ->and($audit->filterUnnotified([$item(Outcome::NotPaidAtStripe)]))->toBeEmpty();
    });
});

describe('The audit trail', function() {
    it('keeps no customer email', function() {
        expect(Craft::$app->getDb()->columnExists(Reconciliation::tableName(), 'email'))->toBeFalse();
    });

    it('keeps rows where money was taken, however old', function() {
        $gatewayId = stripeGatewayId();
        $order = completedUnpaidOrder();
        $transactionId = seedTransaction($order->id, $gatewayId);
        $audit = StripeReconciler::$plugin->getAudit();

        $audit->record(discoveredCandidate($order->id), new ReconciliationResult([
            'transactionId' => $transactionId,
            'outcome' => Outcome::AmountMismatch,
        ]));

        Craft::$app->getDb()->createCommand()->update(
            Reconciliation::tableName(),
            ['dateLastAttempt' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-400 days'))],
            ['transactionId' => $transactionId],
        )->execute();

        expect($audit->prune())->toBe(0);
    });
});

describe('The background check', function() {
    it('only checks unless told otherwise, and never completes a cart by default', function() {
        $job = new ReconcileOrders(['orderIds' => [1]]);

        expect($job->dryRun)->toBeTrue()
            ->and($job->allowCarts)->toBeFalse();
    });
});
