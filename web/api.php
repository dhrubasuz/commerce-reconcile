<?php
declare(strict_types=1);

/** Author / Creator: Dhruba Poudel. Public, stateless fictional-fixture demonstration. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Only GET is supported.']);
    exit;
}
$scenario = $_GET['scenario'] ?? 'baseline';
if (!is_string($scenario) || !in_array($scenario, ['baseline', 'corrected', 'replay', 'conflict'], true) || count($_GET) > 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose one of the four bundled fictional scenarios.']);
    exit;
}
$root = dirname(__DIR__);
$engine = is_file($root . '/plugin/commerce-reconcile/includes/Reconciler.php')
    ? $root . '/plugin/commerce-reconcile/includes' : __DIR__ . '/engine';
$fixturePath = is_file($root . '/fixtures/scenario.json') ? $root . '/fixtures/scenario.json' : __DIR__ . '/scenario.json';
require_once $engine . '/CanonicalJson.php';
require_once $engine . '/Reconciler.php';

try {
    $fixture = json_decode(file_get_contents($fixturePath), true, 32, JSON_THROW_ON_ERROR);
    $orders = $fixture['orders'];
    $events = $fixture['events'];
    $prior = [];
    $explanation = 'A mixed fictional feed: field mismatches, a duplicate, out-of-order delivery, conflicting IDs and equal-sequence events.';
    $reconciler = new DhrubaPoudel\CommerceReconcile\Reconciler();
    if ($scenario === 'corrected') {
        $byId = array_column($orders, null, 'order_id');
        foreach ($events as &$event) {
            if (in_array($event['event_id'], ['evt-amount', 'evt-currency', 'evt-reference', 'evt-status'], true)) {
                foreach (['amount_minor', 'currency', 'reference', 'status'] as $field) {
                    $event[$field] = $byId[$event['order_id']][$field];
                }
            }
        }
        unset($event);
        $explanation = 'Four fictional incoming records now match the order snapshot. Identity conflicts, ambiguous sequences and a missing order still need review. No source order is changed.';
    } elseif ($scenario === 'replay') {
        $events = array_values(array_filter($events, fn(array $event): bool => in_array($event['event_id'], ['evt-match', 'evt-duplicate', 'evt-new'], true)));
        // The fixture contains two exact duplicates. Replay one copy of each matched event.
        $unique = [];
        foreach ($events as $event) {
            $unique[$event['event_id']] = $event;
        }
        $events = array_values($unique);
        $first = $reconciler->analyse($orders, $events);
        $prior = ['ledger' => $first['ledger'], 'latest_sequences' => $first['latest_sequences']];
        $explanation = 'The same three matched event payloads are checked against an explicitly supplied prior ledger. All three are identified as exact replays. This demo does not persist a ledger or apply orders.';
    } elseif ($scenario === 'conflict') {
        $events = array_values(array_filter($events, fn(array $event): bool => in_array($event['event_id'], ['evt-match', 'evt-duplicate', 'evt-new'], true)));
        $unique = [];
        foreach ($events as $event) {
            $unique[$event['event_id']] = $event;
        }
        $events = array_values($unique);
        $changed = $events[0];
        $changed['amount_minor'] += 100;
        $events[] = $changed;
        $explanation = 'One event ID arrives twice with different payloads. Both versions are held as conflicts; arrival order cannot silently select a winner.';
    }
    $report = $reconciler->analyse($orders, $events, $prior);
    echo json_encode([
        'author' => 'Dhruba Poudel', 'schema_version' => 1,
        'scenario' => $scenario, 'snapshot_at' => $fixture['snapshot_at'],
        'explanation' => $explanation,
        'scope' => 'Same PHP domain engine as the WordPress plugin. Hosted independently of WordPress. Bundled fictional records only; no external API, live store, order writes or payments.',
        'source_orders_sha256' => hash('sha256', DhrubaPoudel\CommerceReconcile\CanonicalJson::encode($orders)),
        'report' => $report,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'The fictional review could not be prepared. Please try again.']);
}
