<?php
declare(strict_types=1);

namespace Showcase;

final class PlanException extends \RuntimeException
{
}

final class FreightPlan
{
    public function __construct(
        private readonly string $token,
        private readonly string $contentHash,
        private readonly int $rowCount,
    ) {
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /** @internal The token is an opaque capability, not trusted plan data. */
    public function token(): string
    {
        return $this->token;
    }
}

final class FreightPlanService
{
    /** @var list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> */
    private array $state;
    /** @var array<string, array{predecessor_hash:string, rows:list<array{zone_id:string, service_code:string, charge_units:int, active:bool}>, content_hash:string}> */
    private array $registry = [];
    /** @var list<list<array{zone_id:string, service_code:string, charge_units:int, active:bool}>> */
    private array $backups = [];
    /** @var list<array{mode:string, predecessor_hash:string, content_hash:string, row_count:int}> */
    private array $auditEvents = [];
    private string $serviceId;

    /** @param list<array<string, mixed>> $initialState */
    public function __construct(array $initialState = [])
    {
        $this->state = self::normalizeRows($initialState);
        $this->serviceId = bin2hex(random_bytes(16));
    }

    /**
     * Builds a single-use merge plan from exact normalized rows and predecessor.
     *
     * @param list<array<string, mixed>> $expectedCurrentRows
     * @param list<array<string, mixed>> $candidateRows
     */
    public function preview(array $expectedCurrentRows, array $candidateRows): FreightPlan
    {
        $expected = self::normalizeRows($expectedCurrentRows);
        $candidate = self::normalizeRows($candidateRows);
        $predecessorHash = self::hashRows($expected);
        $contentHash = self::hashRows($candidate);
        $token = $this->serviceId . ':' . bin2hex(random_bytes(24));

        $this->registry[$token] = [
            'predecessor_hash' => $predecessorHash,
            'rows' => $candidate,
            'content_hash' => $contentHash,
        ];

        return new FreightPlan($token, $contentHash, count($candidate));
    }

    /** @return list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> */
    public function apply(FreightPlan $plan): array
    {
        $token = $plan->token();
        $frozen = $this->registry[$token] ?? null;
        if ($frozen === null || !str_starts_with($token, $this->serviceId . ':')) {
            throw new PlanException('Unknown, foreign, or already-consumed plan.');
        }

        if (!hash_equals($frozen['predecessor_hash'], self::hashRows($this->state))) {
            throw new PlanException('Stale plan: current freight state differs from preview predecessor.');
        }

        $next = self::merge($this->state, $frozen['rows']);
        $backup = $this->state;
        $event = [
            'mode' => 'merge',
            'predecessor_hash' => $frozen['predecessor_hash'],
            'content_hash' => $frozen['content_hash'],
            'row_count' => count($frozen['rows']),
        ];

        $this->state = $next;
        $this->backups[] = $backup;
        $this->auditEvents[] = $event;
        unset($this->registry[$token]);

        return $this->state;
    }

    /** @return list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> */
    public function snapshot(): array
    {
        return $this->state;
    }

    /** @return list<list<array{zone_id:string, service_code:string, charge_units:int, active:bool}>> */
    public function backups(): array
    {
        return $this->backups;
    }

    /** @return list<array{mode:string, predecessor_hash:string, content_hash:string, row_count:int}> */
    public function auditEvents(): array
    {
        return $this->auditEvents;
    }

    /** @param list<array<string, mixed>> $state */
    public function replaceStateForTest(array $state): void
    {
        $this->state = self::normalizeRows($state);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{zone_id:string, service_code:string, charge_units:int, active:bool}>
     */
    private static function normalizeRows(array $rows): array
    {
        if (!array_is_list($rows)) {
            throw new ValidationException('Freight rows must be a sequential list.');
        }
        $normalized = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_keys($row) !== ['zone_id', 'service_code', 'charge_units', 'active']) {
                throw new ValidationException("Row {$index} must contain exactly zone_id, service_code, charge_units, active.");
            }
            if (!is_string($row['zone_id']) || !preg_match('/\A[0-9]{1,12}\z/', $row['zone_id'])) {
                throw new ValidationException("Row {$index} has invalid zone_id.");
            }
            if (!is_string($row['service_code']) || !preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/', $row['service_code'])) {
                throw new ValidationException("Row {$index} has invalid service_code.");
            }
            if (!is_int($row['charge_units']) || $row['charge_units'] < 0 || $row['charge_units'] > 100000000) {
                throw new ValidationException("Row {$index} has invalid charge_units.");
            }
            if (!is_bool($row['active'])) {
                throw new ValidationException("Row {$index} has invalid active flag.");
            }
            if (!$row['active'] && $row['charge_units'] !== 0) {
                throw new ValidationException("Row {$index} must use zero charge_units when inactive.");
            }

            $key = self::businessKey($row);
            if (isset($seen[$key])) {
                throw new ValidationException("Duplicate freight business key: {$key}.");
            }
            $seen[$key] = true;
            $normalized[] = [
                'zone_id' => $row['zone_id'],
                'service_code' => $row['service_code'],
                'charge_units' => $row['charge_units'],
                'active' => $row['active'],
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            $zone = strcmp($a['zone_id'], $b['zone_id']);
            return $zone !== 0 ? $zone : strcmp($a['service_code'], $b['service_code']);
        });
        return $normalized;
    }

    /**
     * @param list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> $current
     * @param list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> $incoming
     * @return list<array{zone_id:string, service_code:string, charge_units:int, active:bool}>
     */
    private static function merge(array $current, array $incoming): array
    {
        $indexed = [];
        foreach ($current as $row) {
            $indexed[self::businessKey($row)] = $row;
        }
        foreach ($incoming as $row) {
            $indexed[self::businessKey($row)] = $row;
        }
        $rows = array_values($indexed);
        usort($rows, static function (array $a, array $b): int {
            $zone = strcmp($a['zone_id'], $b['zone_id']);
            return $zone !== 0 ? $zone : strcmp($a['service_code'], $b['service_code']);
        });
        return $rows;
    }

    /** @param array{zone_id:string, service_code:string, charge_units:int, active:bool} $row */
    private static function businessKey(array $row): string
    {
        return strlen($row['zone_id']) . ':' . $row['zone_id'] . '|' . $row['service_code'];
    }

    /** @param list<array{zone_id:string, service_code:string, charge_units:int, active:bool}> $rows */
    private static function hashRows(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }
}
