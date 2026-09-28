<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\base;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\db\Query;
use craft\db\Table;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Console;
use craft\queue\Queue as CraftQueue;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use johnhenry\stripereconciler\controllers\ReconcileController;
use johnhenry\stripereconciler\jobs\ReconcileOrders;
use johnhenry\stripereconciler\models\SettingsModel;
use johnhenry\stripereconciler\utilities\ReconcilerUtility;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Wires the plugin's utility, permissions and lifecycle overrides.
 *
 * No front-end event listeners: reconciliation runs out of band, from the console
 * command or a control panel action. Craft resolves the controller namespaces
 * itself, so neither is wired here.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait PluginTrait
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Narrows the base return type for callers and static analysis.
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSettings(): SettingsModel
    {
        $settings = parent::getSettings();
        assert($settings instanceof SettingsModel);

        return $settings;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Refuses to install without Commerce and the Stripe gateway.
     *
     * Composer only guarantees the packages are on disk. If Commerce is present but
     * not installed, `Commerce::getInstance()` returns null and every service here
     * fatals.
     *
     * @return void
     * @throws InvalidConfigException If Commerce or the Stripe gateway is unavailable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function beforeInstall(): void
    {
        $plugins = Craft::$app->getPlugins();

        $required = [
            'commerce' => 'Craft Commerce',
            'commerce-stripe' => 'Craft Commerce Stripe',
        ];

        foreach ($required as $handle => $name) {
            if (!$plugins->isPluginInstalled($handle)) {
                throw new InvalidConfigException(
                    $name . ' must be installed before Stripe Reconciler. There is nothing this plugin can do without it.',
                );
            }

            if (!$plugins->isPluginEnabled($handle)) {
                throw new InvalidConfigException(
                    $name . ' is installed but disabled. Enable it before installing Stripe Reconciler.',
                );
            }
        }
    }

    /**
     * @inheritdoc
     *
     * Clears what the install migration cannot reach.
     *
     * Dropping the table leaves two things behind: any queued jobs, which would
     * fatal when the runner reached a job class the plugin no longer loads, and the
     * cached badge count.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function afterUninstall(): void
    {
        parent::afterUninstall();

        Craft::$app->getCache()->delete(ReconcilerUtility::BADGE_CACHE_KEY);

        $queue = Craft::$app->getQueue();

        // Only Craft's own database-backed queue stores jobs in a table this can
        // read. A project running a different driver has nothing here to clear.
        if (!$queue instanceof CraftQueue) {
            return;
        }

        // The job column is binary, which Postgres won't match with LIKE, so the
        // serialised jobs are searched here instead.
        $jobs = (new Query())
            ->select(['id', 'job'])
            ->from(Table::QUEUE)
            ->all();

        foreach ($jobs as $job) {
            $payload = is_resource($job['job']) ? stream_get_contents($job['job']) : (string)$job['job'];

            if (str_contains($payload, ReconcileOrders::class)) {
                $queue->release((string)$job['id']);
            }
        }
    }

    /**
     * @inheritdoc
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Registers the control panel utility.
     *
     * Registered in every request context so the type is always resolvable. Craft
     * gates visibility with its own `utility:stripe-reconciler` permission.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = ReconcilerUtility::class;
            },
        );
    }

    /**
     * Registers audit trail pruning with Craft's garbage collection.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function(Event $event): void {
            /** @var Gc $gc */
            $gc = $event->sender;
            $verbose = !$gc->silent && Craft::$app instanceof ConsoleApplication;

            if ($verbose) {
                Console::stdout('    > pruning Stripe Reconciler audit rows ... ');
            }

            $deleted = $this->getAudit()->prune();

            if ($verbose) {
                Console::stdout('done (' . $deleted . ")\n", Console::FG_GREEN);
            }
        });
    }

    /**
     * Registers the plugin's user permissions.
     *
     * Viewing the utility is gated by Craft's own utility permission. This covers
     * acting on a payment, which moves money.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('stripe-reconciler', 'Stripe Reconciler'),
                    'permissions' => [
                        ReconcileController::PERMISSION_RECONCILE => [
                            'label' => Craft::t('stripe-reconciler', 'Reconcile Stripe payments'),
                        ],
                    ],
                ];
            },
        );
    }
}
