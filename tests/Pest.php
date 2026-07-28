<?php

/**
 * Integration tests require a running Craft context.
 * Run from the parent Craft project:
 *
 *   composer test:sr
 */

use craft\commerce\elements\conditions\addresses\GatewayAddressCondition;
use craft\commerce\elements\conditions\orders\GatewayOrderCondition;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Gateways;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use johnhenry\stripereconciler\StripeReconciler;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

uses()->beforeEach(function() {
    // RefreshesDatabase wraps each test in a transaction it rolls back on
    // teardown; that binding only engages when the suite is run with
    // --test-directory. If it ever stops binding, no transaction is open here and
    // every test would COMMIT fixture gateways and orders to the dev database, so
    // fail loudly before this test can write.
    if (Craft::$app->getDb()->getTransaction() === null) {
        throw new RuntimeException(
            'No open database transaction: RefreshesDatabase did not bind, so tests would '
            . 'commit to the dev database. Run the suite via `composer test:sr`.'
        );
    }

    // The gateway list is memoised on the Commerce Gateways service, which is a
    // process-level singleton that RefreshesDatabase does not roll back. Swapping
    // in a fresh instance stops one test's fixture gateway leaking into the next.
    Commerce::getInstance()->set('gateways', new Gateways());

    // Yii's FileCache reads entries with a suppressed `@filemtime()`, which is
    // fine in production but PHPUnit's error handler reports suppressed warnings
    // anyway, so every test that saves an order gets flagged over a warning Yii
    // deliberately ignores. An in-memory cache sidesteps it and keeps each test
    // from inheriting cache entries built against rolled-back rows.
    Craft::$app->set('cache', new ArrayCache());

    // Plugin settings are a process-level singleton that RefreshesDatabase does
    // not roll back, so one test's overrides would otherwise leak into the next.
    $settings = StripeReconciler::$plugin->getSettings();
    $settings->enabledGateways = [];
    $settings->reconcileCarts = false;
    $settings->amountToleranceMinorUnits = 0;
    $settings->lookbackDays = 14;
    $settings->settledAfterDays = 1;
    $settings->recheckAfterMinutes = 60;
    $settings->auditRetentionDays = 90;
    $settings->notificationEmail = '';
})->in('Integration');

/**
 * Backdates a transaction so age-dependent behaviour can be tested.
 *
 * @param int $transactionId The transaction to age.
 * @param int $days How many days into the past to move it.
 * @return void
 */
function ageTransaction(int $transactionId, int $days): void
{
    Craft::$app->getDb()->createCommand()->update(
        '{{%commerce_transactions}}',
        ['dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-' . $days . ' days'))],
        ['id' => $transactionId],
    )->execute();
}

/**
 * Restricts discovery to a single gateway for the duration of a test.
 *
 * Any test that asserts on a total (an empty state, a badge count) is otherwise
 * coupled to whatever real orders happen to be sitting in the development
 * database, and will start failing the moment someone puts a genuine stuck
 * payment there. Scoping to the fixture gateway makes those assertions depend
 * only on what the test itself created.
 *
 * @param int $gatewayId The fixture gateway to restrict to.
 * @return void
 */
function onlyGateway(int $gatewayId): void
{
    StripeReconciler::$plugin->getSettings()->enabledGateways = [$gatewayId];
}

/**
 * Inserts a Stripe gateway directly into the gateways table.
 *
 * Commerce's own `saveGateway()` writes to project config, and a project config
 * write auto-commits the surrounding transaction, which would defeat
 * RefreshesDatabase and leave the fixture gateway behind in the dev database. The
 * read path (`getAllGateways()`) is a plain query against this table, so a direct
 * insert is both sufficient and safe to roll back.
 *
 * @param string $handle The gateway handle.
 * @return int The new gateway ID.
 */
function stripeGatewayId(string $handle = 'stripeFixture'): int
{
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%commerce_gateways}}', [
        'type' => PaymentIntents::class,
        'name' => 'Stripe Fixture',
        'handle' => $handle,
        // A syntactically valid but non-functional key. Discovery never calls
        // Stripe, and any test that would needs to be explicit about it.
        'settings' => Json::encode(['apiKey' => 'sk_test_fixture', 'publishableKey' => 'pk_test_fixture']),
        'paymentType' => 'purchase',
        'isFrontendEnabled' => '1',
        'isArchived' => 0,
        'sortOrder' => 99,
        // Nullable in the schema, but Commerce's gateway setters reject null when
        // hydrating the model, so these have to be present.
        'orderCondition' => Json::encode(['class' => GatewayOrderCondition::class, 'conditionRules' => []]),
        'billingAddressCondition' => Json::encode(['class' => GatewayAddressCondition::class, 'conditionRules' => []]),
        'shippingAddressCondition' => Json::encode(['class' => GatewayAddressCondition::class, 'conditionRules' => []]),
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $id = (int)$db->getLastInsertID();

    Commerce::getInstance()->set('gateways', new Gateways());

    return $id;
}

/**
 * Returns the ID of the site's non-Stripe Dummy gateway.
 *
 * @return int|null The gateway ID, or null if none exists.
 */
function dummyGatewayId(): ?int
{
    $id = (new craft\db\Query())
        ->select(['id'])
        ->from('{{%commerce_gateways}}')
        ->where(['handle' => 'dummy'])
        ->scalar();

    return $id === false ? null : (int)$id;
}

/**
 * Creates a cart: an order that was never completed.
 *
 * @param string $email The customer email.
 * @return Order The saved cart.
 */
function cartOrder(string $email = 'cart@example.test'): Order
{
    $order = new Order();
    $order->email = $email;
    // Commerce assigns this when a cart is created through its cart service, not
    // on save, so a fixture built straight from the element has to set it. Without
    // it the order has no short number and does not behave like a real cart.
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    Craft::$app->getElements()->saveElement($order, false);

    return $order;
}

/**
 * Creates a completed order with an outstanding balance and no payment against it.
 *
 * The total is built from an adjustment rather than a line item, since
 * `getTotalPrice()` is derived from the item subtotal plus adjustments and an
 * adjustment needs no purchasable to exist.
 *
 * @param float $total The order total.
 * @param string $email The customer email.
 * @return Order The saved order.
 */
function completedUnpaidOrder(float $total = 50.0, string $email = 'unpaid@example.test'): Order
{
    $order = new Order();
    $order->email = $email;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    Craft::$app->getElements()->saveElement($order, false);

    $adjustment = new OrderAdjustment();
    $adjustment->name = 'Fixture';
    $adjustment->type = 'tax';
    $adjustment->amount = $total;
    $adjustment->sourceSnapshot = [];
    $adjustment->setOrder($order);

    $order->setAdjustments([$adjustment]);
    $order->isCompleted = true;
    $order->dateOrdered = new DateTime();
    // A completed order carries a reference, which is what Commerce identifies it
    // by. markAsComplete() assigns it in production; a fixture stands one in.
    $order->reference = substr($order->number, 0, 7);

    Craft::$app->getElements()->saveElement($order, false);

    return $order;
}

/**
 * Creates a completed order with nothing outstanding, so Commerce reads it as paid.
 *
 * @param string $email The customer email.
 * @return Order The saved order.
 */
function paidOrder(string $email = 'paid@example.test'): Order
{
    $order = new Order();
    $order->email = $email;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);
    $order->isCompleted = true;
    $order->dateOrdered = new DateTime();
    $order->reference = substr($order->number, 0, 7);

    Craft::$app->getElements()->saveElement($order, false);

    return $order;
}

/**
 * Inserts a transaction row against an order.
 *
 * @param int $orderId The order.
 * @param int $gatewayId The gateway.
 * @param string $status One of the commerce_transactions status values.
 * @param string $type One of the commerce_transactions type values.
 * @param array<string, mixed>|null $response The stored gateway response.
 * @return int The new transaction ID.
 */
function seedTransaction(
    int $orderId,
    int $gatewayId,
    string $status = 'redirect',
    string $type = 'purchase',
    ?array $response = null,
): int {
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%commerce_transactions}}', [
        'orderId' => $orderId,
        'gatewayId' => $gatewayId,
        'hash' => substr(md5((string)mt_rand()), 0, 32),
        'type' => $type,
        'status' => $status,
        'amount' => 50.0,
        'paymentAmount' => 50.0,
        'currency' => 'USD',
        'paymentCurrency' => 'USD',
        'paymentRate' => 1,
        'response' => Json::encode($response ?? ['id' => 'pi_fixture_' . mt_rand(), 'object' => 'payment_intent']),
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int)$db->getLastInsertID();
}
