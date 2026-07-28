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
 * @author John Henry Donovan
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
    public string $schemaVersion = '1.0.0';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan
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
