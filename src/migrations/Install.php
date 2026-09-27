<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\stripereconciler\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use johnhenry\stripereconciler\records\Reconciliation;

/**
 * Installation migration.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Install extends Migration
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration succeeded.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        $table = Reconciliation::tableName();

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->null(),
            'transactionId' => $this->integer()->notNull(),
            'gatewayId' => $this->integer()->null(),
            'paymentIntentId' => $this->string()->null(),
            'orderReference' => $this->string()->null(),
            'orderShortNumber' => $this->string(16)->null(),
            'candidateType' => $this->string(64)->notNull(),
            'outcome' => $this->string(64)->notNull(),
            'stripeStatus' => $this->string(64)->null(),
            'stripeAmountReceived' => $this->bigInteger()->null(),
            'orderTotalMinorUnits' => $this->bigInteger()->null(),
            'currency' => $this->string(12)->null(),
            'attempts' => $this->integer()->notNull()->defaultValue(1),
            'message' => $this->text()->null(),
            'notifiedOutcome' => $this->string(64)->null(),
            'dateLastAttempt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per transaction. Makes reconciliation idempotent.
        $this->createIndex(null, $table, ['transactionId'], true);
        $this->createIndex(null, $table, ['orderId'], false);
        $this->createIndex(null, $table, ['paymentIntentId'], false);
        $this->createIndex(null, $table, ['outcome'], false);
        $this->createIndex(null, $table, ['dateLastAttempt'], false);

        // SET NULL so the audit row outlives the order.
        $this->addForeignKey(null, $table, ['orderId'], CommerceTable::ORDERS, ['id'], 'SET NULL', null);

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration succeeded.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Reconciliation::tableName());

        return true;
    }
}
