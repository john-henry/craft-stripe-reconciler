<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\utilities;

use Craft;
use craft\base\Utility;
use johnhenry\stripereconciler\assets\ReconcilerAsset;
use johnhenry\stripereconciler\controllers\ReconcileController;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\StripeReconciler;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\View;

/**
 * Control panel utility listing unresolved Stripe payments.
 *
 * Rendering costs a couple of database queries and no Stripe calls. Only the
 * buttons contact Stripe.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReconcilerUtility extends Utility
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How long the badge count is cached for, in seconds.
     */
    public const BADGE_CACHE_DURATION = 60;

    /**
     * @var string Cache key for the badge count.
     */
    public const BADGE_CACHE_KEY = 'stripe-reconciler:badge-count';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The utility's display name.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('stripe-reconciler', 'Stripe Reconciler');
    }

    /**
     * @inheritdoc
     *
     * @return string The utility's ID.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function id(): string
    {
        return 'stripe-reconciler';
    }

    /**
     * @inheritdoc
     *
     * Resolved the same way Craft resolves a plugin's nav icon, falling back to a
     * system icon if the file is missing.
     *
     * @return string|null The utility's icon.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function icon(): ?string
    {
        $path = StripeReconciler::$plugin->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';

        return is_file($path) ? $path : 'arrows-rotate';
    }

    /**
     * @inheritdoc
     *
     * Cached: Craft calls this on every control panel request to render the
     * Utilities nav.
     *
     * @return int The number of actionable candidates.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function badgeCount(): int
    {
        $count = Craft::$app->getCache()->getOrSet(
            self::BADGE_CACHE_KEY,
            static function(): int {
                try {
                    return count(self::_actionableCandidates());
                } catch (Throwable $e) {
                    Craft::error('Could not count reconciliation candidates: ' . $e->getMessage(), 'stripe-reconciler');

                    return 0;
                }
            },
            self::BADGE_CACHE_DURATION,
        );

        return (int)$count;
    }

    /**
     * @inheritdoc
     *
     * @return string The rendered utility.
     * @throws Throwable If the candidates cannot be loaded or the template fails to render.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ReconcilerAsset::class);

        $canReconcile = Craft::$app->getUser()->checkPermission(ReconcileController::PERMISSION_RECONCILE);

        $view->registerJs(
            'window.StripeReconciler = ' . json_encode([
                'canReconcile' => $canReconcile,
            ], JSON_THROW_ON_ERROR) . ';',
            View::POS_HEAD,
        );

        return $view->renderTemplate('stripe-reconciler/_utility', [
            'candidates' => self::_actionableCandidates(),
            'history' => self::_history(),
            'canReconcile' => $canReconcile,
            'settings' => StripeReconciler::$plugin->getSettings(),
            // Distinguishes "nothing outstanding" from "checking nothing at all".
            'hasGateway' => StripeReconciler::$plugin->getDiscovery()->getStripeGateways() !== [],
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Returns recent attempts with the outcome resolved to a label and colour.
     *
     * @return array<int, array<string, mixed>> The rows, newest attempt first.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan
     */
    private static function _history(): array
    {
        $rows = StripeReconciler::$plugin->getAudit()->getRecent(20);

        return array_map(static function(array $row): array {
            $row['outcomeLabel'] = Outcome::labelFor($row['outcome'] ?? null);
            $row['outcomeColour'] = Outcome::statusColourFor($row['outcome'] ?? null);

            return $row;
        }, $rows);
    }

    /**
     * Returns the candidates worth acting on.
     *
     * @return Candidate[] The actionable candidates.
     * @throws InvalidConfigException
     * @since 1.0.0
     * @author John Henry Donovan
     */
    private static function _actionableCandidates(): array
    {
        $candidates = StripeReconciler::$plugin->getDiscovery()->findCandidates();

        return array_values(array_filter(
            $candidates,
            static fn(Candidate $candidate): bool => $candidate->type->isActionable(),
        ));
    }
}
