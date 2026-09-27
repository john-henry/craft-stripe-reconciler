<?php

/**
 * Stripe Reconciler plugin for Craft CMS 5.
 *
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler;

use Craft;
use craft\base\Plugin as BasePlugin;
use johnhenry\stripereconciler\base\PluginTrait;
use johnhenry\stripereconciler\models\SettingsModel;
use johnhenry\stripereconciler\services\ServicesTrait;

/**
 * Stripe Reconciler plugin.
 *
 * Recovers Stripe payments that succeeded but were never finalised in Commerce,
 * leaving the order unpaid or still a cart.
 *
 * Every reconciliation re-verifies the PaymentIntent with Stripe before touching
 * Commerce.
 *
 * @property-read SettingsModel $settings
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class StripeReconciler extends BasePlugin
{
    // =========================================================================
    // Traits
    // =========================================================================

    use ServicesTrait;
    use PluginTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Every message the plugin passes through Craft.t() in
     * JavaScript.
     *
     * Craft.t() reads Craft.translations, which is only populated by
     * View::registerTranslations(). Without this list a translated install
     * silently renders these strings in English, because Craft.t() falls back
     * to the source message. Nothing is emitted on an English install:
     * registerTranslations() skips any message whose translation matches the
     * source, so this costs those installs nothing.
     *
     * JsTranslationsTest keeps this in sync with the code: add a Craft.t()
     * call without adding it here and that test fails.
     */
    public const JS_TRANSLATIONS = [
        'Check Stripe',
        'Check them all',
        'Checking Stripe…',
        'Could not reach the server.',
        'Queued {count} order(s) to check. Reload when the queue has run.',
        'Queueing…',
        'Reconcile this order',
        'Reconciling…',
        'This list was loaded before your last action, so it does not include it yet. Reload the page to bring it up to date.',
    ];

    // =========================================================================
    // Static Properties
    // =========================================================================

    /**
     * @var StripeReconciler The plugin instance.
     */
    public static StripeReconciler $plugin;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = false;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = false;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.1.0';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@johnhenry/stripereconciler', __DIR__);

        $this->_registerUtilities();
        $this->_registerPermissions();
        $this->_registerGarbageCollection();
    }
}
