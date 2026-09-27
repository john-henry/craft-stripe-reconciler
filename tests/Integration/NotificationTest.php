<?php

use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\StripeReconciler;

/**
 * Builds a notifiable-item pair for the given outcome.
 *
 * @param Outcome $outcome The outcome to wrap.
 * @return array{candidate: Candidate, result: ReconciliationResult}
 */
function digestItem(Outcome $outcome): array
{
    return [
        'candidate' => new Candidate(['orderId' => 1]),
        'result' => new ReconciliationResult(['transactionId' => 1, 'outcome' => $outcome]),
    ];
}

it('says nothing about a checkout where no money was taken', function() {
    expect(Outcome::NotPaidAtStripe->needsNotification())->toBeFalse()
        ->and(Outcome::Abandoned->needsNotification())->toBeFalse();
});

it('speaks up when money is sitting on an order nobody finished', function() {
    expect(Outcome::CartSkipped->needsNotification())->toBeTrue()
        ->and(Outcome::DryRun->needsNotification())->toBeTrue()
        ->and(Outcome::AmountMismatch->needsNotification())->toBeTrue()
        ->and(Outcome::CompletionFailed->needsNotification())->toBeTrue()
        ->and(Outcome::RefundedOrDisputed->needsNotification())->toBeTrue()
        ->and(Outcome::PossibleDoubleCharge->needsNotification())->toBeTrue();
});

it('says nothing about work that sorted itself out', function() {
    expect(Outcome::Reconciled->needsNotification())->toBeFalse()
        ->and(Outcome::StillProcessing->needsNotification())->toBeFalse()
        ->and(Outcome::Errored->needsNotification())->toBeFalse();
});

it('sends nothing when a run turns up nothing worth sending', function() {
    $settings = StripeReconciler::$plugin->getSettings();
    $settings->notificationEmail = 'shop@example.test';

    $notifiable = StripeReconciler::$plugin->getAudit()->filterUnnotified([
        digestItem(Outcome::NotPaidAtStripe),
        digestItem(Outcome::Abandoned),
    ]);

    expect(StripeReconciler::$plugin->getNotification()->sendDigest($notifiable))->toBeFalse();
});

it('sends nothing when no address is configured', function() {
    StripeReconciler::$plugin->getSettings()->notificationEmail = '';

    expect(StripeReconciler::$plugin->getNotification()->sendDigest([digestItem(Outcome::CartSkipped)]))
        ->toBeFalse();
});
