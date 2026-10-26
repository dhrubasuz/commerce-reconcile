<?php
declare(strict_types=1);
// Author: Dhruba Poudel. Rebuild only when the reviewed domain rules intentionally change.
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/CanonicalJson.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/Reconciler.php';
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/scenario.json'), true, 20, JSON_THROW_ON_ERROR);
$report = (new DhrubaPoudel\CommerceReconcile\Reconciler())->analyse($fixture['orders'], $fixture['events']);
file_put_contents(__DIR__ . '/../fixtures/golden-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(['summary' => $report['summary'], 'report_hash' => $report['report_hash']], JSON_THROW_ON_ERROR) . "\n";
