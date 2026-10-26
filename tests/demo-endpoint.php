<?php
declare(strict_types=1);
/** Author: Dhruba Poudel. Exercises the actual public endpoint in isolated PHP processes. */
$entry = dirname(__DIR__) . '/web/api.php';
$passed = 0;
function callDemo(string $query, string $method = 'GET'): array {
    global $entry;
    $code = 'parse_str(' . var_export($query, true) . ',$_GET);$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';include ' . var_export($entry, true) . ';';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $code], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch endpoint test.');
    $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '') throw new RuntimeException($errors ?: 'Endpoint exited unexpectedly.');
    return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
}
function check(bool $condition, string $message): void { global $passed; if (!$condition) throw new RuntimeException($message); ++$passed; echo "PASS $message\n"; }
$baseline = callDemo('scenario=baseline');
$golden = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/golden-report.json'), true, 32, JSON_THROW_ON_ERROR);
check($baseline['report'] === $golden, 'Public baseline executes the exact PHP golden report');
check(callDemo('scenario=baseline') === $baseline, 'Repeated stateless request is identical');
$corrected = callDemo('scenario=corrected');
check($corrected['report']['summary'] === ['orders'=>9,'events'=>14,'matched'=>7,'exceptions'=>5,'ignored'=>2], 'Four field corrections preserve identity and sequence exceptions');
check($corrected['source_orders_sha256'] === $baseline['source_orders_sha256'], 'Corrections do not alter the source orders');
$replay = callDemo('scenario=replay');
check($replay['report']['summary'] === ['orders'=>9,'events'=>3,'matched'=>0,'exceptions'=>0,'ignored'=>3], 'Exactly three prior matched events are ignored as replays');
check(array_unique(array_column($replay['report']['rows'],'issues'), SORT_REGULAR) === [0=>['event_replay']], 'Every replay has the explicit replay reason');
$conflict = callDemo('scenario=conflict');
check($conflict['report']['summary'] === ['orders'=>9,'events'=>4,'matched'=>2,'exceptions'=>2,'ignored'=>0], 'Both conflicting payload versions remain exceptions');
check($conflict['source_orders_sha256'] === $baseline['source_orders_sha256'], 'Conflict scenario leaves source orders unchanged');
foreach (['scenario=unknown','scenario[]=baseline','scenario=baseline&url=https://example.com'] as $query) check(isset(callDemo($query)['error']), 'Unknown, array or expanded input is rejected: '.$query);
check(isset(callDemo('scenario=baseline','POST')['error']), 'Arbitrary POST processing is unavailable');
check(!isset($baseline['password']) && !isset($baseline['customer']), 'Only fictional non-PII fields are exposed');
echo "$passed endpoint checks passed.\n";
