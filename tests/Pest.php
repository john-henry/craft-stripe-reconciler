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
use craft\commerce\models\Transaction;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Gateways;
use craft\commerce\services\Payments;
use craft\commerce\stripe\gateways\PaymentIntents;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use johnhenry\stripereconciler\models\Candidate;
use johnhenry\stripereconciler\services\ReconciliationService;
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

    // The transaction only covers what it can roll back; MySQL commits
    // implicitly on ALTER TABLE, which a Field factory triggers. So the database
    // itself has to be the test one. phpunit.xml.dist pins it, and craft-pest
    // reads that file from the working directory, so running from anywhere but
    // the repo root leaves the pin unapplied and Craft on the dev database.
    $database = Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar();

    if ($database !== 'db_test') {
        throw new RuntimeException(sprintf(
            'Refusing to run: connected to database "%s", expected "db_test". Run the suite '
            . 'from the repo root via `composer test:sr`.',
            $database,
        ));
    }

    // The gateway list is memoised on the Commerce Gateways service, which is a
    // process-level singleton that RefreshesDatabase does not roll back. Swapping
    // in a fresh instance stops one test's fixture gateway leaking into the next.
    Commerce::getInstance()->set('gateways', new Gateways());

    // Tests that fake Commerce's payment completion swap this service out; the
    // real one goes back for every test.
    Commerce::getInstance()->set('payments', new Payments());
    StripeReconciler::$plugin->set('reconciliation', new ReconciliationService());

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
 * @param float $total The cart total, or 0 for an empty one.
 * @return Order The saved cart.
 */
function cartOrder(string $email = 'cart@example.test', float $total = 0.0): Order
{
    $order = new Order();
    $order->email = $email;
    // Commerce assigns this when a cart is created through its cart service, not
    // on save, so a fixture built straight from the element has to set it. Without
    // it the order has no short number and does not behave like a real cart.
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    Craft::$app->getElements()->saveElement($order, false);

    if ($total > 0) {
        $adjustment = new OrderAdjustment();
        $adjustment->name = 'Fixture';
        $adjustment->type = 'tax';
        $adjustment->amount = $total;
        $adjustment->sourceSnapshot = [];
        $adjustment->setOrder($order);
        $order->setAdjustments([$adjustment]);

        Craft::$app->getElements()->saveElement($order, false);
    }

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
 * @param int|null $parentId The parent transaction, for a child.
 * @param float $amount The amount, in the order currency.
 * @param string $paymentCurrency The currency the payment was taken in.
 * @param float|null $paymentAmount The amount in the payment currency, or null for the same as `$amount`.
 * @return int The new transaction ID.
 */
function seedTransaction(
    int $orderId,
    int $gatewayId,
    string $status = 'redirect',
    string $type = 'purchase',
    ?array $response = null,
    ?int $parentId = null,
    float $amount = 50.0,
    string $paymentCurrency = 'USD',
    ?float $paymentAmount = null,
): int {
    $db = Craft::$app->getDb();

    $db->createCommand()->insert('{{%commerce_transactions}}', [
        'orderId' => $orderId,
        'gatewayId' => $gatewayId,
        'parentId' => $parentId,
        'hash' => substr(md5((string)mt_rand()), 0, 32),
        'type' => $type,
        'status' => $status,
        'amount' => $amount,
        'paymentAmount' => $paymentAmount ?? $amount,
        'currency' => 'USD',
        'paymentCurrency' => $paymentCurrency,
        'paymentRate' => 1,
        'response' => Json::encode($response ?? ['id' => 'pi_fixture_' . mt_rand(), 'object' => 'payment_intent']),
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    return (int)$db->getLastInsertID();
}

/**
 * A reconciliation service with a canned Stripe response.
 *
 * `inspect()` is the only point where the service talks to Stripe, so overriding
 * it exercises the real pre-flight decision logic against known PaymentIntent
 * payloads without needing live API credentials.
 */
class StubReconciliationService extends ReconciliationService
{
    /**
     * @var array<string, mixed>|null The PaymentIntent to return.
     */
    public ?array $intent = null;

    /**
     * @var array<int, array<string, mixed>> PaymentIntents to return for particular
     *                                        transactions, keyed by transaction ID.
     */
    public array $intents = [];

    /**
     * @var bool Whether inspect() was reached.
     */
    public bool $inspected = false;

    /**
     * @var string|null An error to throw instead of returning an intent, standing
     *                  in for Stripe refusing the lookup.
     */
    public ?string $throw = null;

    /**
     * @var Throwable|null A specific exception to throw instead of returning an intent.
     */
    public ?Throwable $throwable = null;

    /**
     * @inheritdoc
     */
    public function inspect(Transaction $transaction): ?array
    {
        $this->inspected = true;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        if ($this->throw !== null) {
            throw new RuntimeException($this->throw);
        }

        return $this->intents[$transaction->id] ?? $this->intent;
    }

    /**
     * Exposes how an unconfirmed Checkout Session is read.
     *
     * @param array<string, mixed> $session The session.
     * @return array<string, mixed>
     */
    public function describeSession(array $session): array
    {
        return $this->sessionAsIntent($session);
    }
}

/**
 * Builds a candidate from a freshly discovered order.
 *
 * @param int $orderId The order ID.
 * @return Candidate The candidate.
 */
function discoveredCandidate(int $orderId): Candidate
{
    // Lookup by ID bypasses the re-check backoff, so the gate can run twice.
    foreach (StripeReconciler::$plugin->getDiscovery()->findCandidates(orderId: $orderId) as $candidate) {
        if ($candidate->orderId === $orderId) {
            return $candidate;
        }
    }

    throw new RuntimeException('Order ' . $orderId . ' was not discovered as a candidate.');
}

/**
 * Runs the stub service over an order and returns the single result.
 *
 * @param int $orderId The order ID.
 * @param array<string, mixed>|null $intent The canned PaymentIntent.
 * @param bool $dryRun Whether to run as a dry run.
 * @param bool|null $allowCarts Whether completing a cart is permitted, or null to use the setting.
 * @return array{0: johnhenry\stripereconciler\models\ReconciliationResult, 1: StubReconciliationService}
 */
function runGate(int $orderId, ?array $intent, bool $dryRun = false, ?bool $allowCarts = null): array
{
    $service = new StubReconciliationService();
    $service->intent = $intent;

    $results = $service->reconcile(discoveredCandidate($orderId), $dryRun, $allowCarts);

    expect($results)->toHaveCount(1);

    return [$results[0], $service];
}
