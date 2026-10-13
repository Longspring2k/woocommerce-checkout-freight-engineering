<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CheckoutStateReducer.php';
require_once __DIR__ . '/../src/FreightPlanService.php';

use Showcase\CheckoutStateReducer;
use Showcase\FreightPlanService;
use Showcase\PlanException;

function show(string $label, mixed $value): void
{
    echo $label . ': ' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

echo "== Partial checkout state ==\n";
$session = [
    'delivery_method' => 'ship',
    'delivery_note' => 'Leave at reception',
    'cart_token' => 'synthetic-cart',
];
show('Initial', $session);
show('Omitted delivery fields', CheckoutStateReducer::reduce($session, []));
$session = CheckoutStateReducer::reduce($session, ['delivery_note' => null]);
show('Explicit note clear', $session);
$session = CheckoutStateReducer::reduce($session, ['delivery_method' => 'collect']);
show('Collect transition', $session);

echo "\n== Freight preview and guarded apply ==\n";
$current = [
    ['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 900, 'active' => true],
];
$candidate = [
    ['zone_id' => '12', 'service_code' => 'STANDARD', 'charge_units' => 0, 'active' => false],
    ['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 1299, 'active' => true],
];
$freight = new FreightPlanService($current);
$plan = $freight->preview($current, $candidate);
show('Preview', ['mode' => 'merge', 'rows' => $plan->rowCount(), 'content_hash' => $plan->contentHash()]);
show('Applied state', $freight->apply($plan));
show('Audit', $freight->auditEvents());

try {
    $freight->apply($plan);
} catch (PlanException $error) {
    echo "Caught replay refusal: {$error->getMessage()}\n";
}

$stale = new FreightPlanService($current);
$stalePlan = $stale->preview($current, $candidate);
$stale->replaceStateForTest([
    ['zone_id' => '007', 'service_code' => 'EXPRESS', 'charge_units' => 901, 'active' => true],
]);
try {
    $stale->apply($stalePlan);
} catch (PlanException $error) {
    echo "Caught stale refusal: {$error->getMessage()}\n";
}
show('State after stale refusal', $stale->snapshot());
show('Backups after stale refusal', $stale->backups());
show('Audit after stale refusal', $stale->auditEvents());
