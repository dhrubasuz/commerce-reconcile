// Author / Creator / Last Modified By: Dhruba Poudel.
// PHP API + simulated-DOM UI checks. Fetch is mocked; this is not browser or WordPress runtime evidence.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';
import { runInContext } from 'node:vm';
import { setImmediate as nextTurn } from 'node:timers/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const portablePhp = resolve(root, '../../runtime/php-8.5.12/php.exe');
const php = process.env.COMMERCE_PHP_BIN || (existsSync(portablePhp) ? portablePhp : 'php');
let JSDOM;
if (process.env.COMMERCE_JSDOM_MODULE) {
  ({ JSDOM } = await import(pathToFileURL(resolve(process.env.COMMERCE_JSDOM_MODULE)).href));
} else {
  try {
    ({ JSDOM } = await import('jsdom'));
  } catch {
    ({ JSDOM } = await import(
      pathToFileURL(resolve(root, '../exception-studio/node_modules/jsdom/lib/api.js')).href
    ));
  }
}

const html = readFileSync(resolve(root, 'web/index.html'), 'utf8');
const script = readFileSync(resolve(root, 'web/showcase.js'), 'utf8');
const golden = JSON.parse(readFileSync(resolve(root, 'fixtures/golden-report.json'), 'utf8'));

function phpApi(scenario = 'baseline', { method = 'GET', extra = false } = {}) {
  assert.match(scenario, /^[a-z-]+$/);
  assert.match(method, /^[A-Z]+$/);
  const code = `http_response_code(200);register_shutdown_function(static function(){file_put_contents('php://stderr','STATUS:'.http_response_code());});$_SERVER['REQUEST_METHOD']='${method}';$_GET=['scenario'=>'${scenario}'${extra ? ", 'url'=>'https://invalid.test'" : ''}];require 'web/api.php';`;
  const run = spawnSync(php, ['-r', code], { cwd: root, encoding: 'utf8' });
  assert.equal(run.status, 0, `PHP failed: ${run.stderr || run.error || run.stdout}`);
  assert.match(run.stderr, /STATUS:\d{3}$/);
  return { status: Number(run.stderr.match(/STATUS:(\d{3})$/)[1]), body: JSON.parse(run.stdout) };
}

const envelopes = Object.fromEntries(
  ['baseline', 'corrected', 'replay', 'conflict'].map((name) => [name, phpApi(name).body]),
);
const copy = (value) => JSON.parse(JSON.stringify(value));
function freeze(value) {
  if (value && typeof value === 'object' && !Object.isFrozen(value)) {
    Object.freeze(value);
    for (const child of Object.values(value)) freeze(child);
  }
  return value;
}

function harness({ honourAbort = true } = {}) {
  const dom = new JSDOM(html, {
    url: 'https://commerce.example.test/',
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  const requests = [],
    downloads = [],
    urls = new Map(),
    revoked = [],
    timers = new Map();
  let timerId = 0,
    now = 0,
    urlId = 0;
  window.Blob = globalThis.Blob;
  window.setTimeout = (callback, delay = 0) => {
    const id = ++timerId;
    timers.set(id, { at: now + delay, callback });
    return id;
  };
  window.clearTimeout = (id) => timers.delete(id);
  window.URL.createObjectURL = (blob) => {
    const url = `blob:review-${++urlId}`;
    urls.set(url, blob);
    return url;
  };
  window.URL.revokeObjectURL = (url) => {
    revoked.push(url);
    urls.delete(url);
  };
  window.HTMLAnchorElement.prototype.click = function () {
    downloads.push({ href: this.href, filename: this.download, blob: urls.get(this.href) });
  };
  window.fetch = (url, options = {}) =>
    new Promise((resolveFetch, rejectFetch) => {
      const request = { url, options, aborted: false, settled: false };
      request.succeed = (body = envelopes.baseline, { status = 200, jsonError = false } = {}) => {
        request.settled = true;
        resolveFetch({
          ok: status >= 200 && status < 300,
          status,
          json: async () => {
            if (jsonError) throw new SyntaxError('Invalid JSON');
            return freeze(copy(body));
          },
        });
      };
      request.fail = (error) => {
        request.settled = true;
        rejectFetch(error);
      };
      options.signal?.addEventListener(
        'abort',
        () => {
          request.aborted = true;
          if (honourAbort && !request.settled)
            request.fail(new window.DOMException('Aborted', 'AbortError'));
        },
        { once: true },
      );
      requests.push(request);
    });
  runInContext(script, dom.getInternalVMContext(), { filename: 'showcase.js' });
  const $ = (selector) => window.document.querySelector(selector);
  const click = (selector) => {
    const item = $(selector);
    assert.ok(item, selector);
    item.click();
  };
  const input = (value) => {
    $('#search').value = value;
    $('#search').dispatchEvent(new window.Event('input', { bubbles: true }));
  };
  return {
    dom,
    window,
    $,
    requests,
    downloads,
    revoked,
    click,
    input,
    async settle() {
      await nextTurn();
    },
    async baseline() {
      assert.equal(requests.length, 1);
      requests[0].succeed(envelopes.baseline);
      await nextTurn();
    },
    async advance(milliseconds) {
      now += milliseconds;
      for (;;) {
        const due = [...timers]
          .filter(([, timer]) => timer.at <= now)
          .sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) break;
        timers.delete(due[0]);
        due[1].callback();
        await nextTurn();
      }
    },
    close() {
      window.close();
    },
  };
}

test('the real PHP baseline envelope agrees with the committed golden report', () => {
  const actual = phpApi();
  assert.equal(actual.status, 200);
  assert.deepEqual(actual.body.report, golden);
  assert.equal(actual.body.author, 'Dhruba Poudel');
  assert.equal(actual.body.schema_version, 1);
  assert.equal(actual.body.scenario, 'baseline');
  assert.match(actual.body.source_orders_sha256, /^[a-f0-9]{64}$/);
  assert.match(actual.body.scope, /fictional records only/);
});

test('all four bundled PHP scenarios preserve source orders and explain their different outcomes', () => {
  const expected = {
    baseline: { orders: 9, events: 14, matched: 3, exceptions: 9, ignored: 2 },
    corrected: { orders: 9, events: 14, matched: 7, exceptions: 5, ignored: 2 },
    replay: { orders: 9, events: 3, matched: 0, exceptions: 0, ignored: 3 },
    conflict: { orders: 9, events: 4, matched: 2, exceptions: 2, ignored: 0 },
  };
  for (const [name, summary] of Object.entries(expected)) {
    assert.deepEqual(envelopes[name].report.summary, summary);
    assert.equal(envelopes[name].report.rows.length, summary.events);
    assert.equal(envelopes[name].source_orders_sha256, envelopes.baseline.source_orders_sha256);
    assert.equal(envelopes[name].scenario, name);
  }
  assert.ok(
    envelopes.replay.report.rows.every(
      (row) => row.issues.length === 1 && row.issues[0] === 'event_replay',
    ),
  );
  assert.equal(
    envelopes.conflict.report.rows.filter((row) => row.issues.includes('event_id_conflict')).length,
    2,
  );
  assert.deepEqual(phpApi().body, envelopes.baseline);
});

test('the PHP boundary rejects unsupported scenarios, extra parameters and non-GET methods', () => {
  assert.equal(phpApi('not-a-scenario').status, 400);
  assert.equal(phpApi('baseline', { extra: true }).status, 400);
  assert.equal(phpApi('baseline', { method: 'POST' }).status, 405);
});

test('the interface renders real PHP results and exposes labelled controls and row selections', async (t) => {
  const h = harness();
  t.after(() => h.close());
  assert.equal(h.$('#run').disabled, true);
  await h.baseline();
  for (const [key, value] of Object.entries(envelopes.baseline.report.summary))
    assert.equal(h.$(`[data-metric="${key}"]`).textContent, String(value));
  assert.equal(h.$('#event-rows').children.length, 14);
  assert.equal(h.$('#register-count').textContent, '14 of 14 event rows shown');
  assert.equal(h.$('#status').getAttribute('role'), 'status');
  assert.equal(h.$('#status').getAttribute('aria-live'), 'polite');
  assert.equal(h.$('label[for="scenario"]').textContent, 'Explore a scenario');
  assert.ok(h.$('.search-label').textContent.includes('Search event or order'));
  const selected = h.$('.row-select[aria-pressed="true"]');
  assert.ok(selected.getAttribute('aria-label').includes('evt-amount'));
  assert.equal(h.$('#inspector-title').textContent, 'DEMO-8102');
  assert.match(h.$('#issue-explanation').textContent, /integer minor units/);
  assert.equal(h.$('#review-results').getAttribute('aria-busy'), 'false');
});

test('outcome filters, text search, the empty state and clear operate together', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.baseline();
  h.click('[data-filter="exception"]');
  assert.equal(h.$('#event-rows').children.length, 9);
  assert.equal(h.$('[data-filter="exception"]').getAttribute('aria-pressed'), 'true');
  assert.equal(h.$('[data-filter="all"]').getAttribute('aria-pressed'), 'false');
  h.input('8103');
  assert.equal(h.$('#event-rows').children.length, 1);
  assert.match(h.$('#event-rows').textContent, /Currency mismatch/);
  assert.equal(h.$('#inspector-title').textContent, 'DEMO-8103');
  assert.equal(h.$('.inspector').hidden, false);
  assert.match(h.$('.row-select[aria-pressed="true"]').getAttribute('aria-label'), /evt-currency/);
  h.input('no-such-fictional-order');
  assert.equal(h.$('#event-rows').children.length, 0);
  assert.equal(h.$('#empty').hidden, false);
  assert.equal(
    h.$('.inspector').hidden,
    true,
    'An empty view must not show details from a hidden row.',
  );
  h.click('#clear');
  assert.equal(h.$('#search').value, '');
  assert.equal(h.$('#empty').hidden, true);
  assert.equal(h.$('#event-rows').children.length, 14);
  assert.equal(h.$('.inspector').hidden, false);
  assert.equal(h.$('[data-filter="all"]').getAttribute('aria-pressed'), 'true');
  h.click('[data-filter="matched"]');
  assert.equal(h.$('#event-rows').children.length, 3);
  assert.equal(h.$('#selected-outcome').textContent, 'Matched');
  assert.ok(h.$('.row-select[aria-pressed="true"]'));
  h.click('[data-filter="ignored"]');
  assert.equal(h.$('#event-rows').children.length, 2);
  assert.equal(h.$('#selected-outcome').textContent, 'Ignored');
  assert.ok(h.$('.row-select[aria-pressed="true"]'));
});

test('selecting a missing order shows absence and exact external values without invented source fields', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.baseline();
  const button = [...h.window.document.querySelectorAll('.row-select')].find((item) =>
    item.getAttribute('aria-label').includes('evt-missing'),
  );
  button.click();
  assert.equal(h.$('#inspector-title').textContent, 'DEMO-8109');
  assert.equal(h.$('#selected-outcome').textContent, 'Needs review');
  assert.equal(
    [...h.window.document.querySelectorAll('#comparison .comparison-row strong')].filter(
      (item) => item.textContent === 'Not found',
    ).length,
    4,
  );
  assert.deepEqual(
    JSON.parse(h.$('#event-payload').textContent),
    envelopes.baseline.report.rows.find((row) => row.event_id === 'evt-missing').external,
  );
  assert.equal(
    h.$('.row-select[aria-pressed="true"]').getAttribute('aria-label'),
    button.getAttribute('aria-label'),
  );
});

test('conflicting copies remain separately inspectable with unique accessible row names', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.baseline();
  const copies = [...h.window.document.querySelectorAll('.row-select')].filter((item) =>
    item.getAttribute('aria-label').includes('evt-conflict'),
  );
  assert.equal(copies.length, 2);
  assert.notEqual(copies[0].getAttribute('aria-label'), copies[1].getAttribute('aria-label'));
  copies[1].click();
  assert.equal(JSON.parse(h.$('#event-payload').textContent).amount_minor, 6600);
  assert.match(h.$('#issue-explanation').textContent, /Both conflicting versions are held/);
});

test('export downloads the complete PHP evidence envelope and revokes the temporary object URL', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.baseline();
  h.click('[data-filter="matched"]');
  h.click('#download');
  assert.equal(h.downloads.length, 1);
  assert.equal(h.downloads[0].filename, 'commerce-reconcile-baseline.json');
  assert.equal(h.downloads[0].blob.type, 'application/json');
  assert.deepEqual(JSON.parse(await h.downloads[0].blob.text()), envelopes.baseline);
  await h.advance(1000);
  assert.deepEqual(h.revoked, ['blob:review-1']);
  assert.match(h.$('#status').textContent, /JSON exported/);
});

test('an HTTP failure hides the previous report and retry restores a current successful result', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.baseline();
  h.$('#scenario').value = 'corrected';
  h.click('#run');
  assert.equal(h.$('#download').disabled, true);
  h.requests[1].succeed({ error: 'Unavailable' }, { status: 503 });
  await h.settle();
  assert.equal(h.$('#review-results').hidden, true);
  assert.equal(h.$('#error-panel').hidden, false);
  assert.equal(h.$('#run').disabled, false);
  assert.match(h.$('#status').textContent, /No result is being shown/);
  h.click('#retry');
  h.requests[2].succeed(envelopes.corrected);
  await h.settle();
  assert.equal(h.$('#error-panel').hidden, true);
  assert.equal(h.$('#review-results').hidden, false);
  assert.equal(h.$('[data-metric="matched"]').textContent, '7');
  assert.equal(h.$('#report-hash').textContent, envelopes.corrected.report.report_hash);
});

test('wrong-scenario, incomplete and malformed JSON responses cannot replace a review', async (t) => {
  for (const kind of ['wrong-scenario', 'incomplete', 'invalid-json']) {
    const h = harness();
    t.after(() => h.close());
    await h.baseline();
    h.$('#scenario').value = 'replay';
    h.click('#run');
    if (kind === 'wrong-scenario') h.requests[1].succeed(envelopes.baseline);
    if (kind === 'incomplete')
      h.requests[1].succeed({ scenario: 'replay', report: { rows: [], summary: { events: 3 } } });
    if (kind === 'invalid-json') h.requests[1].succeed({}, { jsonError: true });
    await h.settle();
    assert.equal(h.$('#review-results').hidden, true, kind);
    assert.equal(h.$('#download').disabled, true, kind);
    assert.equal(h.$('#error-panel').hidden, false, kind);
    assert.equal(h.$('#run').disabled, false, kind);
  }
});

test('a superseded result is ignored even if transport delivers it after abort', async (t) => {
  const h = harness({ honourAbort: false });
  t.after(() => h.close());
  h.$('#scenario').value = 'replay';
  const latest = h.window.runReview();
  assert.equal(h.requests.length, 2);
  assert.equal(h.requests[0].aborted, true);
  h.requests[1].succeed(envelopes.replay);
  await latest;
  await h.settle();
  const hash = h.$('#report-hash').textContent;
  h.requests[0].succeed(envelopes.baseline);
  await h.settle();
  assert.equal(h.$('#report-hash').textContent, hash);
  assert.equal(hash, envelopes.replay.report.report_hash);
  assert.equal(h.$('[data-metric="events"]').textContent, '3');
  assert.equal(h.$('#run').disabled, false);
});

test('an old aborted request cannot clear the newest request busy state', async (t) => {
  const h = harness();
  t.after(() => h.close());
  h.$('#scenario').value = 'conflict';
  const latest = h.window.runReview();
  await h.settle();
  assert.equal(h.requests[0].aborted, true);
  assert.equal(h.$('#run').disabled, true);
  assert.equal(h.$('#review-results').getAttribute('aria-busy'), 'true');
  assert.equal(h.$('#error-panel').hidden, true);
  h.requests[1].succeed(envelopes.conflict);
  await latest;
  await h.settle();
  assert.equal(h.$('[data-metric="exceptions"]').textContent, '2');
  assert.equal(h.$('#run').disabled, false);
});

test('a request that never responds has a bounded timeout and exposes a usable retry', async (t) => {
  const h = harness();
  t.after(() => h.close());
  await h.advance(20000);
  await h.settle();
  assert.equal(h.requests[0].aborted, true, 'A hung request must be aborted within 20 seconds.');
  assert.equal(h.$('#run').disabled, false, 'The user must not remain locked out of review.');
  assert.equal(h.$('#review-results').hidden, true);
  assert.equal(h.$('#error-panel').hidden, false);
});
