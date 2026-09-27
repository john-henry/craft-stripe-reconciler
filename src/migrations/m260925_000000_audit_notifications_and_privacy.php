<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\migrations;

use craft\db\Migration;
use johnhenry\stripereconciler\records\Reconciliation;

/**
 * Drops the customer email the audit trail kept but never used, records which
 * outcome was last emailed so each is sent once, and widens the amount columns
 * for currencies with large minor-unit totals.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.1.0
 */
class m260925_000000_audit_notifications_and_privacy extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration succeeded.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function safeUp(): bool
    {
        $table = Reconciliation::tableName();

        if ($this->db->columnExists($table, 'email')) {
            $this->dropColumn($table, 'email');
        }

        if (!$this->db->columnExists($table, 'notifiedOutcome')) {
            $this->addColumn($table, 'notifiedOutcome', $this->string(64)->null()->after('message'));
        }

        $this->alterColumn($table, 'stripeAmountReceived', $this->bigInteger()->null());
        $this->alterColumn($table, 'orderTotalMinorUnits', $this->bigInteger()->null());

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration was reverted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function safeDown(): bool
    {
        echo "m260925_000000_audit_notifications_and_privacy cannot be reverted.\n";

        return false;
    }
}
