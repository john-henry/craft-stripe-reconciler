<?php

/**
 * The forget command: which audit rows it deletes, when it refuses, and that it
 * leaves Commerce's orders and transactions alone.
 */

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\stripereconciler\console\controllers\ReconcileController;
use johnhenry\stripereconciler\enums\Outcome;
use johnhenry\stripereconciler\records\Reconciliation;
use yii\console\ExitCode;

/**
 * The console controller with its options preset and its confirmation prompt
 * answered, since craft-pest calls the action method directly with no options.
 */
class ForgetTestController extends ReconcileController
{
    /**
     * @var array<string, mixed> Options to set on the next instance.
     */
    public static array $preset = [];

    /**
     * @var bool|null The answer to give the prompt, or null to use the real one.
     */
    public static ?bool $answer = null;

    /**
     * @var int How many times the prompt was shown.
     */
    public static int $asked = 0;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        foreach (self::$preset as $name => $value) {
            $this->$name = $value;
        }
    }

    /**
     * @inheritdoc
     */
    public function confirm($message, $default = false): bool
    {
        if (self::$answer === null) {
            return (bool)parent::confirm($message, $default);
        }

        self::$asked++;

        return self::$answer;
    }
}

/**
 * Inserts an audit row directly.
 *
 * @param int $orderId The order.
 * @param int $transactionId The transaction.
 * @param int $gatewayId The gateway.
 * @param Outcome $outcome The stored outcome.
 * @return void
 */
function auditRow(int $orderId, int $transactionId, int $gatewayId, Outcome $outcome): void
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%stripereconciler_reconciliations}}', [
        'orderId' => $orderId,
        'transactionId' => $transactionId,
        'gatewayId' => $gatewayId,
        'candidateType' => 'unpaidCompletedOrder',
        'outcome' => $outcome->value,
        'stripeStatus' => 'canceled',
        'attempts' => 1,
        'notifiedOutcome' => $outcome->value,
        'dateLastAttempt' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
}

/**
 * Returns the transaction IDs that still have an audit row.
 *
 * @return int[]
 */
function auditedTransactionIds(): array
{
    return array_map('intval', Reconciliation::find()->select(['transactionId'])->column());
}

beforeEach(function() {
    ForgetTestController::$preset = [];
    ForgetTestController::$answer = null;
    ForgetTestController::$asked = 0;
    emptyAuditTable();
});

it('refuses a bare call and deletes nothing', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    auditRow($order->id, $transactionId, $gatewayId, Outcome::Abandoned);

    ForgetTestController::$preset = ['interactive' => false];
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::USAGE)
        ->and(auditedTransactionIds())->toBe([$transactionId]);
});

it('refuses an outcome that is not a stored value', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    auditRow($order->id, $transactionId, $gatewayId, Outcome::Abandoned);

    ForgetTestController::$preset = ['interactive' => false, 'outcome' => 'No money taken, closed'];
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::USAGE)
        ->and(auditedTransactionIds())->toBe([$transactionId]);
});

it('exits cleanly when nothing matches', function() {
    ForgetTestController::$preset = ['interactive' => false, 'transactionId' => 999999999];
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::OK);
});

it('asks before forgetting, and keeps the rows when told no', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    auditRow($order->id, $transactionId, $gatewayId, Outcome::Abandoned);

    ForgetTestController::$preset = ['orderId' => $order->id];
    ForgetTestController::$answer = false;
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::OK)
        ->and(ForgetTestController::$asked)->toBe(1)
        ->and(auditedTransactionIds())->toBe([$transactionId]);
});

it('forgets only the rows matching every selector', function() {
    $gatewayId = stripeGatewayId();
    $otherGatewayId = stripeGatewayId('stripeFixtureOther');
    $order = completedUnpaidOrder();
    $other = completedUnpaidOrder();

    $target = seedTransaction($order->id, $gatewayId);
    $sameOrderOtherOutcome = seedTransaction($order->id, $gatewayId);
    $otherOrder = seedTransaction($other->id, $gatewayId);
    $otherGateway = seedTransaction($order->id, $otherGatewayId);

    auditRow($order->id, $target, $gatewayId, Outcome::MissingAtStripe);
    auditRow($order->id, $sameOrderOtherOutcome, $gatewayId, Outcome::NotPaidAtStripe);
    auditRow($other->id, $otherOrder, $gatewayId, Outcome::MissingAtStripe);
    auditRow($order->id, $otherGateway, $otherGatewayId, Outcome::MissingAtStripe);

    ForgetTestController::$preset = [
        'orderId' => $order->id,
        'outcome' => Outcome::MissingAtStripe->value,
        'gateway' => $gatewayId,
    ];
    ForgetTestController::$answer = true;
    $response = $this->console(ForgetTestController::class, 'forget');

    $remaining = auditedTransactionIds();

    expect($response->exitCode)->toBe(ExitCode::OK)
        ->and($remaining)->not->toContain($target)
        ->and($remaining)->toContain($sameOrderOtherOutcome)
        ->and($remaining)->toContain($otherOrder)
        ->and($remaining)->toContain($otherGateway);
});

it('forgets a single transaction without asking when not interactive', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $forgotten = seedTransaction($order->id, $gatewayId);
    $kept = seedTransaction($order->id, $gatewayId);
    auditRow($order->id, $forgotten, $gatewayId, Outcome::Abandoned);
    auditRow($order->id, $kept, $gatewayId, Outcome::Abandoned);

    ForgetTestController::$preset = ['interactive' => false, 'transactionId' => $forgotten];
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::OK)
        ->and(auditedTransactionIds())->toBe([$kept]);
});

it('leaves Commerce orders and transactions untouched', function() {
    $gatewayId = stripeGatewayId();
    $order = completedUnpaidOrder();
    $transactionId = seedTransaction($order->id, $gatewayId);
    auditRow($order->id, $transactionId, $gatewayId, Outcome::Abandoned);

    $snapshot = fn(): array => [
        (new Query())->from('{{%commerce_orders}}')->where(['id' => $order->id])->one(),
        (new Query())->from('{{%commerce_transactions}}')->where(['orderId' => $order->id])->all(),
    ];
    $before = $snapshot();

    ForgetTestController::$preset = ['interactive' => false, 'orderId' => $order->id];
    $response = $this->console(ForgetTestController::class, 'forget');

    expect($response->exitCode)->toBe(ExitCode::OK)
        ->and(auditedTransactionIds())->toBe([])
        ->and($snapshot())->toBe($before);
});
