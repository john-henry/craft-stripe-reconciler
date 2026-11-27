<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\models\ReconciliationResult;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

/**
 * Reconciles Stripe payments that never finished in Commerce.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ReconcileController extends Controller
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Name of the lock that stops two runs overlapping.
     */
    public const LOCK_NAME = 'stripe-reconciler:run';

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
     * @var int|null Restrict to a single gateway ID. With forget, only rows recorded against it.
     */
    public ?int $gateway = null;

    /**
     * @var int|null Restrict to a single order ID. Reconcile and report ignore the lookback window for it.
     */
    public ?int $orderId = null;

    /**
     * @var int|null Stop after this many candidates.
     */
    public ?int $limit = null;

    /**
     * @var int|null Forget the audit row for this Commerce transaction ID.
     */
    public ?int $transactionId = null;

    /**
     * @var string|null Forget audit rows with this stored outcome, such as abandoned or missingAtStripe.
     */
    public ?string $outcome = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param string $actionID The action being executed.
     * @return string[] The supported options.
     * @author John Henry Donovan <info@johnhenry.ie>
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

        if ($actionID === 'forget') {
            $options[] = 'orderId';
            $options[] = 'transactionId';
            $options[] = 'outcome';
            $options[] = 'gateway';
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): int
    {
        if (!$this->_hasStripeGateway()) {
            return ExitCode::CONFIG;
        }

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::LOCK_NAME)) {
            $this->stdout('Another run is still going, so this one is stopping.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        try {
            return $this->_run();
        } finally {
            $mutex->release(self::LOCK_NAME);
        }
    }

    /**
     * Lists candidate orders without contacting Stripe or changing anything.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionHistory(): int
    {
        $rows = StripeReconciler::$plugin->getAudit()->getRecent($this->limit ?? 25);

        if ($rows === []) {
            $this->stdout('No reconciliation attempts recorded yet.' . PHP_EOL);

            return ExitCode::OK;
        }

        $formatter = Craft::$app->getFormatter();

        $table = array_map(static fn(array $row): array => [
            (string)($row['orderReference'] ?? $row['orderShortNumber'] ?? ('#' . ($row['orderId'] ?? '?'))),
            (string)($row['paymentIntentId'] ?? '-'),
            Outcome::labelFor($row['outcome'] ?? null),
            (string)($row['stripeStatus'] ?? '-'),
            (string)$row['attempts'],
            $formatter->asDatetime(DateTimeHelper::toDateTime($row['dateLastAttempt']), 'short'),
        ], $rows);

        $this->_writeTable(['Order', 'Payment', 'What happened', 'Stripe said', 'Times checked', 'Last checked'], $table);

        return ExitCode::OK;
    }

    /**
     * Deletes stored audit rows so the next run looks at those payments again.
     *
     * Only the plugin's own audit rows are deleted. Commerce orders and
     * transactions are never touched. At least one selector is required, and the
     * matching rows are shown and confirmed before anything is deleted.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionForget(): int
    {
        if ($this->orderId === null && $this->transactionId === null && $this->outcome === null && $this->gateway === null) {
            $this->stderr(
                'Name the rows to forget with at least one of --orderId, --transactionId, --outcome or --gateway.' . PHP_EOL,
                Console::FG_RED,
            );

            return ExitCode::USAGE;
        }

        if ($this->outcome !== null && Outcome::tryFrom($this->outcome) === null) {
            $this->stderr(sprintf(
                '"%s" is not a stored outcome. Use one of: %s.' . PHP_EOL,
                $this->outcome,
                implode(', ', array_map(static fn(Outcome $case): string => $case->value, Outcome::cases())),
            ), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $audit = StripeReconciler::$plugin->getAudit();
        $rows = $audit->findMatching(
            orderId: $this->orderId,
            transactionId: $this->transactionId,
            outcome: $this->outcome,
            gatewayId: $this->gateway,
        );

        if ($rows === []) {
            $this->stdout('No audit rows match, so there is nothing to forget.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $formatter = Craft::$app->getFormatter();

        $table = array_map(static fn(array $row): array => [
            (string)($row['orderReference'] ?? $row['orderShortNumber'] ?? ('#' . ($row['orderId'] ?? '?'))),
            (string)$row['transactionId'],
            Outcome::labelFor($row['outcome'] ?? null) . ' (' . $row['outcome'] . ')',
            (string)($row['stripeStatus'] ?? '-'),
            $formatter->asDatetime(DateTimeHelper::toDateTime($row['dateLastAttempt']), 'short'),
        ], $rows);

        $this->stdout(count($rows) . ' audit row(s) match:' . PHP_EOL . PHP_EOL);
        $this->_writeTable(['Order', 'Transaction', 'What happened', 'Stripe said', 'Last checked'], $table);
        $this->stdout(
            PHP_EOL . 'The next run looks at these payments again, and any of them can be emailed about again.'
            . ' Your orders and transactions are not changed.' . PHP_EOL . PHP_EOL,
        );

        if (!$this->confirm('Forget ' . count($rows) . ' audit row(s)?')) {
            $this->stdout('Nothing forgotten.' . PHP_EOL);

            return ExitCode::OK;
        }

        $deleted = $audit->forget($rows);

        $this->stdout('Forgot ' . $deleted . ' audit row(s).' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Runs one reconciliation pass over every candidate.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _run(): int
    {
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
        $audit = StripeReconciler::$plugin->getAudit();
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

            // A payment skipped by the backoff still counts while its last answer
            // needs a person, so the exit code holds between checks.
            if (!$this->_resolvesOrder($results)) {
                $attention += $audit->countNeedingAttention(array_values(array_diff(
                    $candidate->transactionIds,
                    array_map(static fn(ReconciliationResult $result): int => $result->transactionId, $results),
                )));
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

                $notifiable[] = ['candidate' => $candidate, 'result' => $result];

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
        // A dry run notifies too: a refund or double charge it finds is final, so
        // no later run would mention it.
        $notifiable = $audit->filterUnnotified($notifiable);

        if (StripeReconciler::$plugin->getNotification()->sendDigest($notifiable)) {
            $audit->markNotified($notifiable);
            $this->stdout(PHP_EOL . 'Emailed ' . count($notifiable) . ' payment(s) needing attention.' . PHP_EOL, Console::FG_GREY);
        }

        if ($attention > 0) {
            $this->stdout(PHP_EOL . $attention . ' item(s) need a human to look at them.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Reports whether any Stripe gateway is available to check.
     *
     * Distinguishes "nothing outstanding" from "checking nothing at all", which
     * would otherwise produce identical output.
     *
     * @return bool True if at least one Stripe gateway is in scope.
     * @throws InvalidConfigException If a plugin service cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Returns whether any of a candidate's results reconciled the order.
     *
     * @param ReconciliationResult[] $results The results for one candidate.
     * @return bool True if the order is now settled.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _resolvesOrder(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->outcome === Outcome::Reconciled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the console colour for an outcome.
     *
     * @param Outcome $outcome The outcome.
     * @return int The Console colour constant.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
