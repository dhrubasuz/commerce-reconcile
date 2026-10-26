<?php
declare(strict_types=1);

namespace DhrubaPoudel\CommerceReconcile;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Author: Dhruba Poudel.
 * Pure read-only reconciliation. Nothing here updates an order, charges a card or calls a service.
 */
final class Reconciler
{
    public const MAX_ORDERS = 500;
    public const MAX_EVENTS = 1000;
    public const MAX_JSON_BYTES = 262144;
    private const STATUSES = ['pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed'];
    private const ORDER_KEYS = ['order_id', 'reference', 'amount_minor', 'currency', 'status'];
    private const EVENT_KEYS = ['event_id', 'order_id', 'reference', 'amount_minor', 'currency', 'status', 'sequence', 'occurred_at'];

    /**
     * The optional prior ledger is supplied explicitly by a trusted caller. It is not persisted here.
     * Callers may omit it for a snapshot review. A caller-supplied ledger is never persisted here.
     */
    public function analyse(array $orders, array $events, array $prior = []): array
    {
        $this->validateLists($orders, $events);
        [$priorLedger, $priorSequences] = $this->validatePrior($prior);
        if (count($priorLedger) + count($events) > 5000 || count($priorSequences) + count($events) > 5000) {
            throw new InvalidArgumentException('Combined snapshot and prior state exceed the 5000-entry bound.');
        }
        $index = [];
        foreach ($orders as $position => $order) {
            $this->validateOrder($order, "orders[$position]");
            if (isset($index[$order['order_id']])) {
                throw new InvalidArgumentException('Order identifiers must be unique.');
            }
            $index[$order['order_id']] = $order;
        }

        $fingerprints = [];
        $groups = [];
        foreach ($events as $position => $event) {
            $this->validateEvent($event, "events[$position]");
            $fingerprints[$position] = hash('sha256', CanonicalJson::encode($event));
            $groups[$event['event_id']][$fingerprints[$position]] = true;
        }
        // Plan all admissible sequence maxima before evaluating rows. Arrival order cannot win.
        $maxima = $priorSequences;
        $sequenceIds = [];
        foreach ($events as $position => $event) {
            $id = $event['event_id'];
            if (count($groups[$id]) !== 1 || isset($priorLedger[$id])) {
                continue;
            }
            $oid = $event['order_id'];
            $maxima[$oid] = max($maxima[$oid] ?? 0, $event['sequence']);
            $sequenceIds[$oid][$event['sequence']][$id] = true;
        }

        $ledger = $priorLedger;
        $seen = [];
        $rows = [];
        $counts = ['orders' => count($orders), 'events' => count($events), 'matched' => 0, 'exceptions' => 0, 'ignored' => 0];
        foreach ($events as $position => $event) {
            $id = $event['event_id'];
            $oid = $event['order_id'];
            $fingerprint = $fingerprints[$position];
            $order = $index[$oid] ?? null;
            $issues = [];
            $disposition = 'exception';
            if (count($groups[$id]) > 1 || (isset($priorLedger[$id]) && !hash_equals($priorLedger[$id], $fingerprint))) {
                $issues[] = 'event_id_conflict';
            } elseif (isset($priorLedger[$id])) {
                $issues[] = 'event_replay';
                $disposition = 'ignored';
            } elseif (isset($seen[$id])) {
                $issues[] = 'duplicate_event';
                $disposition = 'ignored';
            } elseif ($event['sequence'] < ($maxima[$oid] ?? 0)) {
                $issues[] = 'stale_event';
                $disposition = 'ignored';
                $ledger[$id] = $fingerprint;
            } elseif ($event['sequence'] <= ($priorSequences[$oid] ?? 0)) {
                $issues[] = 'stale_event';
                $disposition = 'ignored';
                $ledger[$id] = $fingerprint;
            } elseif (count($sequenceIds[$oid][$event['sequence']] ?? []) > 1) {
                $issues[] = 'sequence_conflict';
            } else {
                if ($order === null) {
                    $issues[] = 'missing_order';
                } else {
                    foreach (['reference', 'amount_minor', 'currency', 'status'] as $field) {
                        if ($order[$field] !== $event[$field]) {
                            $issues[] = $field === 'amount_minor' ? 'amount_mismatch' : $field . '_mismatch';
                        }
                    }
                }
                $disposition = $issues === [] ? 'matched' : 'exception';
                $ledger[$id] = $fingerprint;
            }
            $seen[$id] = true;
            $counts[$disposition === 'exception' ? 'exceptions' : $disposition]++;
            $rows[] = [
                'event_id' => $id,
                'order_id' => $oid,
                'reference' => $event['reference'],
                'disposition' => $disposition,
                'issues' => $issues,
                'sequence' => $event['sequence'],
                'external' => $event,
                'order' => $order,
            ];
        }
        ksort($ledger, SORT_STRING);
        ksort($maxima, SORT_NUMERIC);
        $report = ['schema_version' => 1, 'summary' => $counts, 'rows' => $rows, 'ledger' => $ledger, 'latest_sequences' => $maxima];
        $report['report_hash'] = hash('sha256', CanonicalJson::encode($report));
        return $report;
    }

    public function decode(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('JSON exceeds the 256 KiB limit.');
        }
        // Preserve JSON container kinds long enough to distinguish {} from [].
        // Associative decoding alone would collapse both empty containers to PHP [].
        $shape = json_decode($json, false, 20, JSON_THROW_ON_ERROR);
        if (!$shape instanceof \stdClass) {
            throw new InvalidArgumentException('Payload must be a JSON object.');
        }
        foreach (['orders', 'events'] as $field) {
            if (property_exists($shape, $field) && !is_array($shape->{$field})) {
                throw new InvalidArgumentException("$field must be a JSON array, not an object or scalar.");
            }
        }
        return json_decode($json, true, 20, JSON_THROW_ON_ERROR);
    }

    private function validateLists(array $orders, array $events): void
    {
        if (!array_is_list($orders) || !array_is_list($events) || count($orders) > self::MAX_ORDERS || count($events) > self::MAX_EVENTS) {
            throw new InvalidArgumentException('Use lists of at most 500 orders and 1000 events.');
        }
    }

    private function validateOrder(mixed $row, string $name): void
    {
        $this->keys($row, self::ORDER_KEYS, $name);
        $this->integer($row['order_id'], 1, 2147483647, "$name.order_id");
        $this->token($row['reference'], "$name.reference");
        $this->integer($row['amount_minor'], 0, 100000000, "$name.amount_minor");
        if (!is_string($row['currency']) || !preg_match('/^[A-Z]{3}$/D', $row['currency'])) {
            throw new InvalidArgumentException("$name.currency must be three uppercase letters.");
        }
        if (!is_string($row['status']) || !in_array($row['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException("$name.status is not supported.");
        }
    }

    private function validateEvent(mixed $row, string $name): void
    {
        $this->keys($row, self::EVENT_KEYS, $name);
        $this->token($row['event_id'], "$name.event_id");
        if (!preg_match('/^[A-Za-z]/D', $row['event_id'])) {
            throw new InvalidArgumentException("$name.event_id must start with a letter.");
        }
        $this->validateOrder(array_intersect_key($row, array_flip(self::ORDER_KEYS)), $name);
        $this->integer($row['sequence'], 1, 1000000, "$name.sequence");
        if (!is_string($row['occurred_at'])) {
            throw new InvalidArgumentException("$name.occurred_at must be a UTC timestamp.");
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $row['occurred_at'], new DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $row['occurred_at']) {
            throw new InvalidArgumentException("$name.occurred_at must be a valid UTC timestamp.");
        }
    }

    private function validatePrior(array $prior): array
    {
        if (array_diff(array_keys($prior), ['ledger', 'latest_sequences'])) {
            throw new InvalidArgumentException('Unknown prior-state field.');
        }
        $ledger = $prior['ledger'] ?? [];
        $sequences = $prior['latest_sequences'] ?? [];
        if (!is_array($ledger) || !is_array($sequences) || count($ledger) > 5000 || count($sequences) > 5000) {
            throw new InvalidArgumentException('Prior state exceeds its limits.');
        }
        foreach ($ledger as $id => $fingerprint) {
            $this->token((string) $id, 'prior ledger event_id');
            if (!preg_match('/^[A-Za-z]/D', (string) $id)) {
                throw new InvalidArgumentException('Prior ledger identifiers must start with a letter.');
            }
            if (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new InvalidArgumentException('Invalid prior ledger fingerprint.');
            }
        }
        foreach ($sequences as $id => $sequence) {
            if (!ctype_digit((string) $id) || (string) ((int) $id) !== (string) $id || (int) $id < 1 || (int) $id > 2147483647) {
                throw new InvalidArgumentException('Invalid prior order identifier.');
            }
            $this->integer($sequence, 1, 1000000, 'prior sequence');
        }
        return [$ledger, $sequences];
    }

    private function keys(mixed $row, array $expected, string $name): void
    {
        if (!is_array($row) || array_is_list($row) || array_diff($expected, array_keys($row)) || array_diff(array_keys($row), $expected)) {
            throw new InvalidArgumentException("$name must contain exactly the documented fields.");
        }
    }

    private function token(mixed $value, string $name): void
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $value)) {
            throw new InvalidArgumentException("$name must be an ASCII identifier of 1–64 characters.");
        }
    }

    private function integer(mixed $value, int $min, int $max, string $name): void
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("$name must be an integer between $min and $max.");
        }
    }
}
