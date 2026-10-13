<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CheckoutStateReducer.php';
require_once __DIR__ . '/../src/FreightPlanService.php';

use Showcase\CheckoutStateReducer;
use Showcase\FreightPlanService;
use Showcase\ValidationException;
use Showcase\PlanException;

final class TestRunner
{
    private array $tests = [];

    public function test(string $name, callable $test): void
    {
        $this->tests[$name] = $test;
    }

    public function run(?string $filter): int
    {
        $selected = array_filter(
            $this->tests,
            static fn (string $name): bool => $filter === null || str_contains($name, $filter),
            ARRAY_FILTER_USE_KEY
        );

        if ($selected === []) {
            fwrite(STDERR, "No tests matched filter: {$filter}\n");
            return 2;
        }

        $failures = 0;
        foreach ($selected as $name => $test) {
            try {
                $test();
                echo "PASS {$name}\n";
            } catch (Throwable $error) {
                $failures++;
                echo "FAIL {$name}: {$error->getMessage()}\n";
            }
        }

        echo sprintf("%d tests, %d failures\n", count($selected), $failures);
        return $failures === 0 ? 0 : 1;
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            "Expected %s, got %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function truth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(callable $operation, string $class): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new RuntimeException("Expected {$class}, got " . $error::class);
        }
        return $error;
    }
    throw new RuntimeException("Expected {$class} to be thrown");
}

$runner = new TestRunner();

$runner->test('checkout omitted fields preserve owned and unrelated state', function (): void {
    $state = ['delivery_method' => 'ship', 'delivery_note' => 'Side door', 'cart_token' => 'safe'];
    same($state, CheckoutStateReducer::reduce($state, []));
});

$runner->test('checkout explicit clear removes only nullable owned field', function (): void {
    $state = ['delivery_method' => 'ship', 'delivery_note' => 'Side door', 'cart_token' => 'safe'];
    same(
        ['delivery_method' => 'ship', 'cart_token' => 'safe'],
        CheckoutStateReducer::reduce($state, ['delivery_note' => null])
    );
});

$runner->test('checkout method transition cleans owned dependent state only', function (): void {
    $state = ['delivery_method' => 'ship', 'delivery_note' => 'Side door', 'cart_token' => 'safe'];
    same(
        ['delivery_method' => 'collect', 'cart_token' => 'safe'],
        CheckoutStateReducer::reduce($state, ['delivery_method' => 'collect'])
    );
});

$runner->test('checkout invalid patch is atomic', function (): void {
    $state = ['delivery_method' => 'ship', 'delivery_note' => 'Side door', 'cart_token' => 'safe'];
    throws(fn () => CheckoutStateReducer::reduce($state, ['delivery_method' => 'teleport']), ValidationException::class);
    same(['delivery_method' => 'ship', 'delivery_note' => 'Side door', 'cart_token' => 'safe'], $state);
});

$runner->test('checkout rejects unknown fields without schema bypass', function (): void {
    throws(fn () => CheckoutStateReducer::reduce([], ['coupon' => null]), ValidationException::class);
});

$runner->test('checkout rejects invalid later field before applying valid earlier field', function (): void {
    $state = ['delivery_method' => 'collect', 'cart_token' => 'safe'];
    throws(
        fn () => CheckoutStateReducer::reduce(
            $state,
            ['delivery_method' => 'ship', 'delivery_note' => str_repeat('x', 121)]
        ),
        ValidationException::class
    );
    same(['delivery_method' => 'collect', 'cart_token' => 'safe'], $state);
});

$validRows = [
    ['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 1299, 'active' => true],
    ['zone_id' => '12', 'service_code' => 'STANDARD', 'charge_units' => 0, 'active' => false],
];

$runner->test('freight preview rejects malformed rows and duplicates', function () use ($validRows): void {
    $service = new FreightPlanService();
    $bad = $validRows;
    $bad[0]['charge_units'] = 12.99;
    throws(fn () => $service->preview([], $bad), ValidationException::class);

    $duplicate = [$validRows[0], $validRows[0]];
    throws(fn () => $service->preview([], $duplicate), ValidationException::class);

    $crossField = $validRows;
    $crossField[1]['charge_units'] = 1;
    throws(fn () => $service->preview([], $crossField), ValidationException::class);
});

$runner->test('freight hash is stable under input reordering', function () use ($validRows): void {
    $a = new FreightPlanService();
    $b = new FreightPlanService();
    same(
        $a->preview([], $validRows)->contentHash(),
        $b->preview([], array_reverse($validRows))->contentHash()
    );
});

$runner->test('freight successful merge preserves unrelated rows and records evidence once', function () use ($validRows): void {
    $existing = [
        ['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 999, 'active' => true],
        ['zone_id' => '88', 'service_code' => 'ECONOMY', 'charge_units' => 250, 'active' => true],
    ];
    $service = new FreightPlanService($existing);
    $plan = $service->preview($existing, $validRows);
    $result = $service->apply($plan);

    same([$validRows[0], $validRows[1], $existing[1]], $result);
    same([$existing], $service->backups());
    same(1, count($service->auditEvents()));
    same('merge', $service->auditEvents()[0]['mode']);
    same($plan->contentHash(), $service->auditEvents()[0]['content_hash']);
});

$runner->test('freight malformed expected predecessor fails without evidence mutation', function () use ($validRows): void {
    $service = new FreightPlanService();
    throws(
        fn () => $service->preview(
            [['zone_id' => 7, 'service_code' => 'EXPRESS', 'charge_units' => 100, 'active' => true]],
            $validRows
        ),
        ValidationException::class
    );
    same([], $service->snapshot());
    same([], $service->backups());
    same([], $service->auditEvents());
});

$runner->test('freight stale plan fails without mutation', function () use ($validRows): void {
    $existing = [['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 999, 'active' => true]];
    $service = new FreightPlanService($existing);
    $plan = $service->preview($existing, $validRows);
    $service->replaceStateForTest([['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 1000, 'active' => true]]);
    $before = $service->snapshot();

    throws(fn () => $service->apply($plan), PlanException::class);
    same($before, $service->snapshot());
    same([], $service->backups());
    same([], $service->auditEvents());
});

$runner->test('freight replayed and foreign plans fail unchanged', function () use ($validRows): void {
    $service = new FreightPlanService();
    $plan = $service->preview([], $validRows);
    $service->apply($plan);
    $before = $service->snapshot();
    $backupCount = count($service->backups());
    $auditCount = count($service->auditEvents());
    throws(fn () => $service->apply($plan), PlanException::class);

    $other = new FreightPlanService($before);
    $foreign = $other->preview($before, [$validRows[0]]);
    throws(fn () => $service->apply($foreign), PlanException::class);
    same($before, $service->snapshot());
    same($backupCount, count($service->backups()));
    same($auditCount, count($service->auditEvents()));
});

$runner->test('checkout whitespace-only note is an explicit clear after normalization', function (): void {
    $state = ['delivery_method' => 'ship', 'delivery_note' => 'Old', 'cart_token' => 'safe'];
    same(['delivery_method' => 'ship', 'cart_token' => 'safe'], CheckoutStateReducer::reduce($state, ['delivery_note' => " \t "]));
});

$runner->test('freight rejects newline identifiers and non-list row collections', function () use ($validRows): void {
    $service = new FreightPlanService();
    foreach (['zone_id', 'service_code'] as $field) {
        $bad = $validRows;
        $bad[0][$field] .= "\n";
        throws(fn () => $service->preview([], $bad), ValidationException::class);
    }
    throws(fn () => $service->preview([], ['named-row' => $validRows[0]]), ValidationException::class);
    same([], $service->snapshot());
    same([], $service->backups());
    same([], $service->auditEvents());
});

$runner->test('freight applies registry content rather than forged display fields or caller edits', function () use ($validRows): void {
    $service = new FreightPlanService();
    $candidate = $validRows;
    $plan = $service->preview([], $candidate);
    $candidate[0]['charge_units'] = 1;
    $displayOnly = new Showcase\FreightPlan($plan->token(), 'not-the-frozen-hash', 999);
    same($validRows, $service->apply($displayOnly));
    same($plan->contentHash(), $service->auditEvents()[0]['content_hash']);
    same(count($validRows), $service->auditEvents()[0]['row_count']);
});

$filter = $argv[1] ?? null;
exit($runner->run($filter));
