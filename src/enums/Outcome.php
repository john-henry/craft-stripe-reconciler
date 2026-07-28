<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\enums;

use Craft;

/**
 * The result of attempting to reconcile a single candidate.
 *
 * Recorded against every attempt.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum Outcome: string
{
    /**
     * Stripe confirmed the payment and Commerce now reports the order as paid.
     */
    case Reconciled = 'reconciled';

    /**
     * Stripe was reached and had not been paid. Not an error: this is the expected
     * result for an abandoned checkout. Re-checked on later runs.
     */
    case NotPaidAtStripe = 'notPaidAtStripe';

    /**
     * Cancelled at Stripe, or unpaid for longer than `settledAfterDays`. Terminal,
     * so it stops being re-checked.
     */
    case Abandoned = 'abandoned';

    /**
     * Stripe reported a successful payment whose amount does not match the order
     * total. Held back for manual review rather than completed automatically.
     */
    case AmountMismatch = 'amountMismatch';

    /**
     * The payment is still settling at Stripe. Worth retrying on a later run.
     */
    case StillProcessing = 'stillProcessing';

    /**
     * Stripe confirmed payment on an order that is still a cart, but cart
     * reconciliation is disabled. Reported for visibility, not acted on.
     */
    case CartSkipped = 'cartSkipped';

    /**
     * Commerce accepted the completion attempt but the order is still not paid.
     */
    case CompletionFailed = 'completionFailed';

    /**
     * An error was thrown while reconciling. Details are in the message column.
     */
    case Errored = 'errored';

    /**
     * The candidate was inspected under a dry run. Nothing was changed.
     */
    case DryRun = 'dryRun';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Returns the stored values of every outcome that is final.
     *
     * @return string[] The terminal outcome values.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn(self $case): string => $case->value,
            array_filter(self::cases(), static fn(self $case): bool => $case->isTerminal()),
        ));
    }

    /**
     * Returns the label for a stored value.
     *
     * @param string|null $value The stored outcome value.
     * @return string The label, or the raw value if it is not one we know.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function labelFor(?string $value): string
    {
        if ($value === null) {
            return '-';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }

    /**
     * Returns the Craft status colour for a stored value.
     *
     * @param string|null $value The stored outcome value.
     * @return string A Craft `status` colour class.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function statusColourFor(?string $value): string
    {
        if ($value === null) {
            return 'grey';
        }

        return self::tryFrom($value)?->statusColour() ?? 'grey';
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns whether this outcome is final, meaning the payment should not be
     * looked at again by a later run.
     *
     * Terminal payments are never rediscovered. Everything else is re-checked,
     * since it may still change.
     *
     * @return bool True if the payment is resolved for good.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Reconciled, self::Abandoned => true,
            default => false,
        };
    }

    /**
     * Returns a plain description of what happened.
     *
     * The stored values are developer strings; this is what is shown in the control
     * panel and console.
     *
     * @return string The label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function label(): string
    {
        return Craft::t('stripe-reconciler', match ($this) {
            self::Reconciled => 'Paid, order completed',
            self::NotPaidAtStripe => 'No money taken',
            self::Abandoned => 'No money taken, closed',
            self::AmountMismatch => 'Amount does not match',
            self::StillProcessing => 'Still settling',
            self::CartSkipped => 'Paid, waiting on you',
            self::CompletionFailed => 'Could not complete',
            self::Errored => 'Something went wrong',
            self::DryRun => 'Paid, ready to complete',
        });
    }

    /**
     * Returns the Craft status colour that goes with this outcome.
     *
     * green: paid and finished. orange: money there, awaiting action. red: needs
     * a person. grey: nothing to do.
     *
     * Not matched to Commerce order status colours, since these describe a payment
     * rather than an order.
     *
     * @return string A Craft `status` colour class.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function statusColour(): string
    {
        return match ($this) {
            self::Reconciled => 'green',
            self::CartSkipped, self::DryRun => 'orange',
            self::AmountMismatch, self::CompletionFailed, self::Errored => 'red',
            self::NotPaidAtStripe, self::Abandoned, self::StillProcessing => 'grey',
        };
    }

    /**
     * Returns whether this outcome is worth putting in somebody's inbox.
     *
     * True only where money is at Stripe against an unfinished order.
     *
     * Excludes payments never taken, payments still settling, orders already
     * reconciled, and errors. Errors are carried by the command's exit code and the
     * logs instead, so a Stripe outage does not generate mail.
     *
     * @return bool True if somebody should be told about it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function needsNotification(): bool
    {
        return match ($this) {
            self::CartSkipped, self::DryRun, self::AmountMismatch, self::CompletionFailed => true,
            default => false,
        };
    }

    /**
     * Returns whether this outcome warrants a human looking at it.
     *
     * @return bool True if the outcome should be highlighted in console output.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::AmountMismatch, self::CompletionFailed, self::Errored => true,
            default => false,
        };
    }
}
