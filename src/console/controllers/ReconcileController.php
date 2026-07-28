<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

/**
 * Reconciles Stripe payments that never finished in Commerce.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconcileController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var bool Inspect Stripe and report what would change, without changing it.
     */
    public bool $dryRun = false;

    /**
     * @var int|null How many days back to search. Defaults to the configured value.
     */
    public ?int $lookbackDays = null;

    /**
     * @var int|null Restrict to a single gateway ID.
     */
    public ?int $gateway = null;

    /**
     * @var int|null Restrict to a single order ID, ignoring the lookback window.
     */
    public ?int $orderId = null;

    /**
     * @var int|null Stop after this many candidates.
     */
    public ?int $limit = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param string $actionID The action being executed.
     * @return string[] The supported options.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'index' || $actionID === 'report') {
            $options[] = 'lookbackDays';
            $options[] = 'gateway';
            $options[] = 'orderId';
            $options[] = 'limit';
        }

        if ($actionID === 'index') {
            $options[] = 'dryRun';
        }

        if ($actionID === 'history') {
            $options[] = 'limit';
        }

        return $options;
    }

    /**
     * Reconciles unresolved Stripe payments.
     *
     * Every candidate is verified against Stripe before Commerce is touched.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        if (!$this->_hasStripeGateway()) {
            return ExitCode::CONFIG;
        }

        $candidates = $this->_findCandidates();

        if ($candidates === []) {
            $this->stdout('Nothing to reconcile.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $actionable = array_filter($candidates, static fn(Candidate $c): bool => $c->type->isActionable());

        $this->stdout(sprintf(
            '%d candidate(s) found, %d actionable.%s' . PHP_EOL,
            count($candidates),
            count($actionable),
            $this->dryRun ? ' Dry run, nothing will be changed.' : '',
        ));

        $reconciliation = StripeReconciler::$plugin->getReconciliation();
        $tally = [];
        $attention = 0;
        $backedOff = 0;
        $notifiable = [];

        foreach ($actionable as $candidate) {
            try {
                // Treated as unattended unless a specific order was named.
                $results = $reconciliation->reconcile(
                    $candidate,
                    $this->dryRun,
                    respectBackoff: $this->orderId === null,
                );
            } catch (Throwable $e) {
                $this->stderr('  ' . $candidate->getLabel() . ': ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
                $attention++;
                continue;
            }

            // Empty means every payment on this order was skipped by the backoff.
            if ($results === []) {
                $backedOff++;
                continue;
            }

            foreach ($results as $result) {
                $tally[$result->outcome->label()] = ($tally[$result->outcome->label()] ?? 0) + 1;

                if ($result->outcome->needsAttention()) {
                    $attention++;
                }

                if ($result->outcome->needsNotification()) {
                    $notifiable[] = ['candidate' => $candidate, 'result' => $result];
                }

                $this->stdout('  ' . $candidate->getLabel() . ': ', Console::FG_GREY);
                $this->stdout($result->outcome->label(), $this->_colourFor($result->outcome));
                $this->stdout(' - ' . $result->message . PHP_EOL);
            }
        }

        $this->stdout(PHP_EOL . 'Summary:' . PHP_EOL);

        foreach ($tally as $outcome => $count) {
            $this->stdout(sprintf('  %-26s %d' . PHP_EOL, $outcome, $count));
        }

        if ($backedOff > 0) {
            $this->stdout(sprintf(
                '  %-26s %d (checked within the last %d minute(s))' . PHP_EOL,
                'Skipped, checked recently',
                $backedOff,
                StripeReconciler::$plugin->getSettings()->recheckAfterMinutes,
            ), Console::FG_GREY);
        }

        // Only the console notifies; control panel actions show the answer inline.
        if (StripeReconciler::$plugin->getNotification()->sendDigest($notifiable)) {
            $this->stdout(PHP_EOL . 'Emailed ' . count($notifiable) . ' payment(s) needing attention.' . PHP_EOL, Console::FG_GREY);
        }

        if ($attention > 0) {
            $this->stdout(PHP_EOL . $attention . ' item(s) need a human to look at them.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Lists candidate orders without contacting Stripe or changing anything.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionReport(): int
    {
        if (!$this->_hasStripeGateway()) {
            return ExitCode::CONFIG;
        }

        $candidates = $this->_findCandidates();

        if ($candidates === []) {
            $this->stdout('No unresolved Stripe transactions found.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $rows = array_map(static fn(Candidate $c): array => [
            $c->getLabel(),
            $c->type->label(),
            (string)count($c->transactionIds),
            $c->order->email ?? '-',
        ], $candidates);

        $this->stdout(count($candidates) . ' candidate(s):' . PHP_EOL . PHP_EOL);
        $this->_writeTable(['Order', 'Classification', 'Txns', 'Email'], $rows);

        return ExitCode::OK;
    }

    /**
     * Shows recent reconciliation attempts from the audit trail.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionHistory(): int
    {
        $rows = StripeReconciler::$plugin->getAudit()->getRecent($this->limit ?? 25);

        if ($rows === []) {
            $this->stdout('No reconciliation attempts recorded yet.' . PHP_EOL);

            return ExitCode::OK;
        }

        $table = array_map(static fn(array $row): array => [
            (string)($row['orderReference'] ?? $row['orderShortNumber'] ?? ('#' . ($row['orderId'] ?? '?'))),
            (string)($row['paymentIntentId'] ?? '-'),
            Outcome::labelFor($row['outcome'] ?? null),
            (string)($row['stripeStatus'] ?? '-'),
            (string)$row['attempts'],
            (string)$row['dateLastAttempt'],
        ], $rows);

        $this->_writeTable(['Order', 'Payment', 'What happened', 'Stripe said', 'Times checked', 'Last checked'], $table);

        return ExitCode::OK;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Reports whether any Stripe gateway is available to check.
     *
     * Distinguishes "nothing outstanding" from "checking nothing at all", which
     * would otherwise produce identical output.
     *
     * @return bool True if at least one Stripe gateway is in scope.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _hasStripeGateway(): bool
    {
        if (StripeReconciler::$plugin->getDiscovery()->getStripeGateways() !== []) {
            return true;
        }

        $this->stderr(
            'No Stripe gateway is available to check. Add one in Commerce, or widen the '
            . 'enabledGateways setting if it is restricting things.' . PHP_EOL,
            Console::FG_RED,
        );

        return false;
    }

    /**
     * Runs discovery using the command's options.
     *
     * @return Candidate[] The candidates.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _findCandidates(): array
    {
        $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates(
            lookbackDays: $this->lookbackDays,
            gatewayId: $this->gateway,
            orderId: $this->orderId,
        );

        if ($this->limit !== null && $this->limit > 0) {
            $candidates = array_slice($candidates, 0, $this->limit);
        }

        return $candidates;
    }

    /**
     * Returns the console colour for an outcome.
     *
     * @param Outcome $outcome The outcome.
     * @return int The Console colour constant.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _colourFor(Outcome $outcome): int
    {
        if ($outcome === Outcome::Reconciled) {
            return Console::FG_GREEN;
        }

        if ($outcome->needsAttention()) {
            return Console::FG_RED;
        }

        return Console::FG_YELLOW;
    }

    /**
     * Writes a simple aligned table.
     *
     * @param string[] $headers The column headers.
     * @param array<int, string[]> $rows The rows.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _writeTable(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }

        $line = static function(array $cells) use ($widths): string {
            $parts = [];

            foreach (array_values($cells) as $i => $cell) {
                $parts[] = str_pad($cell, $widths[$i]);
            }

            return '  ' . implode('  ', $parts) . PHP_EOL;
        };

        $this->stdout($line($headers), Console::BOLD);

        foreach ($rows as $row) {
            $this->stdout($line($row));
        }
    }
}
