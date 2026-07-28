<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\Component;

/**
 * Tells somebody when money is waiting on an order nobody has finished.
 *
 * One digest per run, not one mail per payment. Called by the console command
 * only.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class NotificationService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Sends the digest for a run, if there is anything worth sending.
     *
     * @param array<int, array{candidate: Candidate, result: ReconciliationResult}> $items The notifiable items.
     * @return bool Whether a mail was sent.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function sendDigest(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        $to = trim(App::parseEnv(StripeReconciler::$plugin->getSettings()->notificationEmail));

        if ($to === '') {
            return false;
        }

        try {
            return Craft::$app->getMailer()
                ->compose()
                ->setTo($to)
                ->setSubject($this->_subject(count($items)))
                ->setTextBody($this->_body($items))
                ->send();
        } catch (Throwable $e) {
            // Swallowed: the reconciliation already happened and is recorded, so a
            // mail failure must not fail the run.
            Craft::error('Could not send the reconciliation digest: ' . $e->getMessage(), 'stripe-reconciler');

            return false;
        }
    }

    /**
     * Filters a run's results down to the ones worth an email.
     *
     * @param array<int, array{candidate: Candidate, result: ReconciliationResult}> $items Every result from the run.
     * @return array<int, array{candidate: Candidate, result: ReconciliationResult}> The notifiable ones.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function filterNotifiable(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['result']->outcome->needsNotification(),
        ));
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Builds the subject line.
     *
     * @param int $count How many payments are waiting.
     * @return string The subject.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _subject(int $count): string
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();

        return Craft::t('stripe-reconciler', '{count} Stripe payment(s) need attention on {site}', [
            'count' => $count,
            'site' => $site,
        ]);
    }

    /**
     * Builds the plain text body.
     *
     * Plain text, so it renders in any client.
     *
     * @param array<int, array{candidate: Candidate, result: ReconciliationResult}> $items The notifiable items.
     * @return string The body.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _body(array $items): string
    {
        $lines = [
            Craft::t('stripe-reconciler', 'Stripe has taken money for these orders, but they are not finished in Commerce.'),
            '',
        ];

        foreach ($items as $item) {
            $candidate = $item['candidate'];
            $result = $item['result'];

            $lines[] = $candidate->getLabel() . ' (' . ($candidate->order->email ?? '-') . ')';
            $lines[] = '  ' . $result->outcome->label() . '. ' . $result->message;
            $lines[] = '  ' . UrlHelper::cpUrl('commerce/orders/' . $candidate->orderId);
            $lines[] = '';
        }

        $lines[] = Craft::t('stripe-reconciler', 'Review them here:');
        $lines[] = UrlHelper::cpUrl('utilities/stripe-reconciler');

        return implode(PHP_EOL, $lines);
    }
}
