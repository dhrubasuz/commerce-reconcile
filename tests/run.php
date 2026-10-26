<?php
declare(strict_types=1);
// Author: Dhruba Poudel. Dependency-free domain and WordPress-adapter contract checks.
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/CanonicalJson.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/Reconciler.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/WooCommerceAdapter.php';
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../plugin/commerce-reconcile/includes/Plugin.php';

use DhrubaPoudel\CommerceReconcile\CanonicalJson;
use DhrubaPoudel\CommerceReconcile\Reconciler;
use DhrubaPoudel\CommerceReconcile\WooCommerceAdapter;
use DhrubaPoudel\CommerceReconcile\Plugin;

$tests = [];
function test(string $name, callable $run): void { global $tests; $tests[$name] = $run; }
function eq(mixed $actual, mixed $expected): void {
    if ($actual !== $expected) throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
}
function rejects(callable $run, string $class = InvalidArgumentException::class): void {
    try { $run(); } catch (Throwable $error) { if ($error instanceof $class) return; throw $error; }
    throw new RuntimeException("Expected $class");
}
function fixture(): array { return json_decode(file_get_contents(__DIR__ . '/../fixtures/scenario.json'), true, 20, JSON_THROW_ON_ERROR); }
function one(): array { $f = fixture(); return [$f['orders'][0], $f['events'][0]]; }
function request(array|string $payload, string $nonce = 'valid-nonce', string $contentType = 'application/json'): WP_REST_Request {
    return new WP_REST_Request(is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR), ['x-wp-nonce' => $nonce, 'content-type' => $contentType]);
}
function plugin(): Plugin { return new Plugin(__DIR__ . '/../plugin/commerce-reconcile/commerce-reconcile.php'); }

test('fictional fixture reconciles to the independently reviewed expected counts', function () {
    $f = fixture();
    eq((new Reconciler())->analyse($f['orders'], $f['events'])['summary'], ['orders'=>9,'events'=>14,'matched'=>3,'exceptions'=>9,'ignored'=>2]);
});
test('report exactly matches the committed golden fixture', function () {
    $f = fixture(); $report = (new Reconciler())->analyse($f['orders'], $f['events']);
    eq($report, json_decode(file_get_contents(__DIR__ . '/../fixtures/golden-report.json'), true, 20, JSON_THROW_ON_ERROR));
});
test('a repeated read-only analysis is byte stable and does not mutate inputs', function () {
    $f = fixture(); $before = serialize($f); $engine = new Reconciler();
    eq(CanonicalJson::encode($engine->analyse($f['orders'],$f['events'])),CanonicalJson::encode($engine->analyse($f['orders'],$f['events'])));
    eq(serialize($f),$before);
});
test('hash commits the full report and changes when a source field changes', function () {
    [$order,$event]=one(); $engine=new Reconciler(); $a=$engine->analyse([$order],[$event]);
    $event['amount_minor']++; $b=$engine->analyse([$order],[$event]);
    if($a['report_hash']===$b['report_hash'])throw new RuntimeException('Hash failed to change');
    $hash=$a['report_hash']; unset($a['report_hash']); eq(hash('sha256',CanonicalJson::encode($a)),$hash);
});
test('amount, currency, reference and status differences remain separate issues', function () {
    [$order,$event]=one(); $event['amount_minor']++; $event['currency']='USD'; $event['reference']='OTHER'; $event['status']='completed';
    eq((new Reconciler())->analyse([$order],[$event])['rows'][0]['issues'],['reference_mismatch','amount_mismatch','currency_mismatch','status_mismatch']);
});
test('missing orders are visible exceptions rather than silently dropped', function () {
    [, $event]=one(); eq((new Reconciler())->analyse([],[$event])['rows'][0]['issues'],['missing_order']);
});
test('an exact duplicate is ignored once and never counted twice as matched', function () {
    [$order,$event]=one(); $r=(new Reconciler())->analyse([$order],[$event,$event]);
    eq($r['summary']['matched'],1); eq($r['rows'][1]['issues'],['duplicate_event']);
});
test('all versions of a conflicting event ID are held regardless of arrival order', function () {
    [$order,$event]=one(); $changed=$event; $changed['amount_minor']++;
    foreach([[$event,$changed],[$changed,$event]] as $events) {
        $r=(new Reconciler())->analyse([$order],$events); eq($r['summary']['exceptions'],2);
        eq($r['rows'][0]['issues'],['event_id_conflict']); eq($r['rows'][1]['issues'],['event_id_conflict']); eq($r['ledger'],[]);
    }
});
test('sequence maxima make old events stale regardless of arrival order', function () {
    [$order,$old]=one(); $new=$old; $new['event_id']='evt-newer'; $new['sequence']=2;
    foreach([[$old,$new],[$new,$old]] as $events) {
        $r=(new Reconciler())->analyse([$order],$events); eq($r['summary']['matched'],1);eq($r['summary']['ignored'],1);
        $stale=array_values(array_filter($r['rows'],fn($x)=>$x['event_id']===$old['event_id']))[0];eq($stale['issues'],['stale_event']);
    }
});
test('equal maximum sequences with different event IDs are held, not guessed', function () {
    [$order,$a]=one(); $b=$a;$b['event_id']='evt-another';
    $r=(new Reconciler())->analyse([$order],[$a,$b]);eq($r['summary']['exceptions'],2);eq($r['rows'][0]['issues'],['sequence_conflict']);
});
test('a trusted prior fingerprint identifies replay while preserving the ledger', function () {
    [$order,$event]=one();$engine=new Reconciler();$a=$engine->analyse([$order],[$event]);
    $b=$engine->analyse([$order],[$event],['ledger'=>$a['ledger'],'latest_sequences'=>$a['latest_sequences']]);
    eq($b['rows'][0]['issues'],['event_replay']);eq($b['ledger'],$a['ledger']);eq($b['latest_sequences'],$a['latest_sequences']);
});
test('a changed payload cannot reuse a prior event identifier', function () {
    [$order,$event]=one();$engine=new Reconciler();$a=$engine->analyse([$order],[$event]);$event['amount_minor']++;
    $b=$engine->analyse([$order],[$event],['ledger'=>$a['ledger']]);eq($b['rows'][0]['issues'],['event_id_conflict']);eq($b['ledger'],$a['ledger']);
});
test('a previously observed sequence cannot be replaced with another event', function () {
    [$order,$event]=one();eq((new Reconciler())->analyse([$order],[$event],['latest_sequences'=>[$order['order_id']=>1]])['rows'][0]['issues'],['stale_event']);
});
test('malformed UTC dates and overflowed dates are rejected atomically', function () {
    [$order,$event]=one(); foreach(['2026-02-30T00:00:00Z','2026-10-27','2026-10-27T00:00:00+00:00']as$value){$event['occurred_at']=$value;rejects(fn()=>(new Reconciler())->analyse([$order],[$event]));}
});
test('floats, negative amounts and string numeric identifiers are rejected', function () {
    [$order,$event]=one();foreach([['amount_minor',12.3],['amount_minor',-1],['order_id','8101'],['sequence',0],['sequence',1.0]]as[$field,$value]){$bad=$event;$bad[$field]=$value;rejects(fn()=>(new Reconciler())->analyse([$order],[$bad]));}
});
test('unknown fields and markup-like references are rejected rather than echoed', function () {
    [$order,$event]=one();$bad=$event;$bad['customer_email']='private@example.test';rejects(fn()=>(new Reconciler())->analyse([$order],[$bad]));
    $event['reference']='<script>alert(1)</script>';rejects(fn()=>(new Reconciler())->analyse([$order],[$event]));
});
test('duplicate order IDs cannot make a last-writer-wins comparison', function () {
    [$order,$event]=one();rejects(fn()=>(new Reconciler())->analyse([$order,$order],[$event]));
});
test('row caps, byte caps and JSON nesting caps fail before analysis', function () {
    [$order,$event]=one();rejects(fn()=>(new Reconciler())->analyse(array_fill(0,501,$order),[]));rejects(fn()=>(new Reconciler())->analyse([],array_fill(0,1001,$event)));
    rejects(fn()=>(new Reconciler())->decode(str_repeat(' ',262145)));rejects(fn()=>(new Reconciler())->decode(str_repeat('[',25).str_repeat(']',25)),JsonException::class);
});
test('numeric event identifiers and noncanonical prior order keys are rejected', function () {
    [$order,$event]=one();$event['event_id']='123';rejects(fn()=>(new Reconciler())->analyse([$order],[$event]));rejects(fn()=>(new Reconciler())->analyse([],[],['latest_sequences'=>['08101'=>1]]));
});
test('decimal conversion is exact and refuses silent rounding or exponent notation', function () {
    eq(WooCommerceAdapter::decimalToMinor('129.90',2),12990);eq(WooCommerceAdapter::decimalToMinor('1.1',2),110);eq(WooCommerceAdapter::decimalToMinor('1.0000',2),100);eq(WooCommerceAdapter::decimalToMinor('12',0),12);
    foreach(['1e2','-1.00','1.001','01.00']as$value)rejects(fn()=>WooCommerceAdapter::decimalToMinor($value,2));
});
test('WooCommerce adapter reads exact unique IDs and returns only approved fields', function () {
    [$order]=one();$GLOBALS['cr_test_orders']=[$order['order_id']=>new ReadOnlyOrderDouble($order)];$GLOBALS['cr_test_order_reads']=[];
    eq((new WooCommerceAdapter())->load([$order['order_id'],$order['order_id'],9999]),[$order]);eq($GLOBALS['cr_test_order_reads'],[8101,9999]);
});
test('WooCommerce adapter never treats refunds as orders and enforces its ID cap', function () {
    [$order]=one();$GLOBALS['cr_test_orders']=[$order['order_id']=>new ReadOnlyOrderDouble($order,'shop_order_refund')];eq((new WooCommerceAdapter())->load([8101]),[]);
    rejects(fn()=>(new WooCommerceAdapter())->load(range(1,101)));rejects(fn()=>(new WooCommerceAdapter())->load(['8101']));
});
test('anonymous callers and callers without the capability are denied', function () {
    $GLOBALS['cr_test_user']=false;eq(plugin()->restPermission(request([]))->code,'cr_forbidden');$GLOBALS['cr_test_user']=true;
    $GLOBALS['cr_test_caps']['manage_woocommerce']=false;eq(plugin()->restPermission(request([]))->code,'cr_forbidden');$GLOBALS['cr_test_caps']['manage_woocommerce']=true;
});
test('a nonce alone never authorises a caller, and a capability alone does not pass CSRF checks', function () {
    eq(plugin()->restPermission(request([],'bad-nonce'))->code,'cr_nonce');eq(plugin()->restPermission(request([],''))->code,'cr_nonce');eq(plugin()->restPermission(request([])),true);
});
test('REST fixture analysis matches pure PHP report and marks the response private', function () {
    $f=fixture();$payload=['mode'=>'fixture','orders'=>$f['orders'],'events'=>$f['events']];$r=plugin()->restAnalyse(request($payload));
    eq($r->status,200);eq($r->data,(new Reconciler())->analyse($f['orders'],$f['events']));eq($r->headers['Cache-Control'],'no-store, private');
});
test('REST rejects content type, malformed payload and unexpected URL fields', function () {
    eq(plugin()->restAnalyse(request([],contentType:'text/plain'))->data['status'],415);eq(plugin()->restAnalyse(request('{'))->data['status'],400);
    eq(plugin()->restAnalyse(request(['mode'=>'fixture','events'=>[],'orders'=>[],'url'=>'https://example.test']))->data['status'],400);
});
test('JSON container validation preserves the difference between objects and lists', function () {
    $engine = new Reconciler();
    foreach (['[]', 'null', '1', '{"orders":{},"events":[]}', '{"orders":[],"events":{}}', '{"events":{"0":{}}}'] as $json) {
        rejects(fn() => $engine->decode($json));
    }
    eq($engine->decode('{"orders":[],"events":[]}'), ['orders' => [], 'events' => []]);
});
test('REST rejects object-shaped order and event containers before any WooCommerce read', function () {
    $GLOBALS['cr_test_order_reads'] = [];
    foreach (['{"mode":"fixture","orders":{},"events":{}}', '{"mode":"fixture","orders":{},"events":[]}', '{"mode":"fixture","orders":[],"events":{}}', '{"mode":"woocommerce","events":{}}'] as $json) {
        $response = plugin()->restAnalyse(request($json));
        eq($response instanceof WP_Error, true);
        eq($response->data['status'], 400);
    }
    eq($GLOBALS['cr_test_order_reads'], []);
});
test('an isolated PHP process verifies the genuinely absent WooCommerce fallback', function () {
    $process = proc_open([PHP_BINARY, __DIR__ . '/no-woocommerce.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start isolated PHP fallback check.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('No-Woo fallback failed: ' . $stderr);
    $result = json_decode($stdout, true, 10, JSON_THROW_ON_ERROR);
    eq($result['status'], 'PASS');
    eq($result['woocommerce_function_available'], false);
    eq($result['checks'], 6);
});
test('invalid external events are rejected before any WooCommerce read', function () {
    $GLOBALS['cr_test_order_reads']=[];eq(plugin()->restAnalyse(request(['mode'=>'woocommerce','events'=>[['order_id'=>8101]]]))->data['status'],400);eq($GLOBALS['cr_test_order_reads'],[]);
});
test('REST WooCommerce mode compares the validated event IDs without accepting caller orders', function () {
    [$order,$event]=one();$GLOBALS['cr_test_orders']=[8101=>new ReadOnlyOrderDouble($order)];$GLOBALS['cr_test_order_reads']=[];
    $r=plugin()->restAnalyse(request(['mode'=>'woocommerce','events'=>[$event]]));eq($r->status,200);eq($r->data['summary']['matched'],1);eq($GLOBALS['cr_test_order_reads'],[8101]);
    eq(plugin()->restAnalyse(request(['mode'=>'woocommerce','orders'=>[$order],'events'=>[$event]]))->data['status'],400);
});

$failed=0;
foreach($tests as$name=>$run){try{$run();echo"PASS $name\n";}catch(Throwable$error){$failed++;echo"FAIL $name: ".$error->getMessage()."\n";}}
echo count($tests)-$failed.'/'.count($tests)." checks passed.\n";
exit($failed===0?0:1);
