<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\services;

use yii\base\InvalidConfigException;

/**
 * Registers and exposes the plugin's service components.
 *
 * @property-read AuditService $audit
 * @property-read DiscoveryService $discovery
 * @property-read NotificationService $notification
 * @property-read ReconciliationService $reconciliation
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ServicesTrait
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin configuration, including registered service components.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'audit' => AuditService::class,
                'discovery' => DiscoveryService::class,
                'notification' => NotificationService::class,
                'reconciliation' => ReconciliationService::class,
            ],
        ];
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the audit service.
     *
     * @return AuditService The audit service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAudit(): AuditService
    {
        $component = $this->get('audit');
        assert($component instanceof AuditService);

        return $component;
    }

    /**
     * Returns the discovery service.
     *
     * @return DiscoveryService The discovery service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDiscovery(): DiscoveryService
    {
        $component = $this->get('discovery');
        assert($component instanceof DiscoveryService);

        return $component;
    }

    /**
     * Returns the notification service.
     *
     * @return NotificationService The notification service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getNotification(): NotificationService
    {
        $component = $this->get('notification');
        assert($component instanceof NotificationService);

        return $component;
    }

    /**
     * Returns the reconciliation service.
     *
     * @return ReconciliationService The reconciliation service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getReconciliation(): ReconciliationService
    {
        $component = $this->get('reconciliation');
        assert($component instanceof ReconciliationService);

        return $component;
    }
}
