/* Author / Creator / Last Modified By: Dhruba Poudel. GPL-2.0-or-later. */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import test from 'node:test';

// Normal installations resolve jsdom. An already-installed copy may be supplied
// explicitly without copying its source or introducing another dependency install.
const moduleLocation = process.env.CANVAS_JSDOM_MODULE;
const imported = await import(
  moduleLocation ? pathToFileURL(resolve(moduleLocation)).href : 'jsdom'
);
const JSDOM = imported.JSDOM ?? imported.default.JSDOM;
const html = await readFile(new URL('../preview/index.html', import.meta.url), 'utf8');
const script = await readFile(new URL('../assets/main.js', import.meta.url), 'utf8');

function page({ enhance = true, transform = (source) => source } = {}) {
  const dom = new JSDOM(transform(html), {
    url: 'https://canvas.invalid/theme/preview/',
    runScripts: 'outside-only',
  });
  if (enhance) dom.window.eval(script);
  return dom;
}

function visibleCards(document) {
  return [...document.querySelectorAll('[data-product-category]')].filter((card) => !card.hidden);
}

test('the unenhanced catalogue retains six products and native details', () => {
  const dom = page({ enhance: false });
  const document = dom.window.document;
  assert.equal(visibleCards(document).length, 6);
  assert.equal(document.querySelectorAll('details > summary').length, 6);
  assert.equal(document.querySelector('[data-catalogue-filters]').hidden, true);
  assert.equal(document.querySelector('[data-menu-toggle]').hidden, true);
  assert.equal(document.querySelectorAll('[data-canvas-nav] a').length, 3);
  dom.window.close();
});

test('menu state opens and Escape closes it with focus returned to the toggle', () => {
  const dom = page();
  const document = dom.window.document;
  const toggle = document.querySelector('[data-menu-toggle]');
  const navigation = document.querySelector('[data-canvas-nav]');
  assert.equal(toggle.hidden, false);
  assert.equal(toggle.getAttribute('aria-expanded'), 'false');
  toggle.click();
  assert.equal(toggle.getAttribute('aria-expanded'), 'true');
  assert.equal(navigation.getAttribute('data-open'), 'true');
  assert.equal(toggle.textContent, 'Close');
  navigation.querySelector('a').focus();
  navigation.dispatchEvent(
    new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  );
  assert.equal(toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(navigation.getAttribute('data-open'), 'false');
  assert.equal(document.activeElement, toggle);
  dom.window.close();
});

test('choosing a navigation link closes the enhanced menu', () => {
  const dom = page();
  const document = dom.window.document;
  document.querySelector('[data-menu-toggle]').click();
  document.querySelector('[data-canvas-nav] a').click();
  assert.equal(document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded'), 'false');
  assert.equal(document.querySelector('[data-canvas-nav]').getAttribute('data-open'), 'false');
  dom.window.close();
});

test('catalogue filters expose the correct objects, count and one pressed choice', () => {
  const dom = page();
  const document = dom.window.document;
  assert.equal(document.querySelector('[data-catalogue-filters]').hidden, false);
  for (const [category, expectedCount] of [
    ['home', 4],
    ['carry', 1],
    ['paper', 1],
    ['all', 6],
  ]) {
    const button = document.querySelector(`[data-product-filter="${category}"]`);
    button.click();
    assert.equal(visibleCards(document).length, expectedCount);
    assert.equal(
      document.querySelector('[data-product-count]').textContent,
      `${expectedCount} fictional ${expectedCount === 1 ? 'object' : 'objects'}`,
    );
    assert.equal(document.querySelectorAll('[data-product-filter][aria-pressed="true"]').length, 1);
    assert.equal(button.getAttribute('aria-pressed'), 'true');
  }
  dom.window.close();
});

test('filter changes remain consistent after repeated category switches', () => {
  const dom = page();
  const document = dom.window.document;
  const sequence = ['carry', 'paper', 'home', 'carry', 'all', 'home', 'all'];
  for (const category of sequence)
    document.querySelector(`[data-product-filter="${category}"]`).click();
  assert.equal(visibleCards(document).length, 6);
  assert.equal(document.querySelector('[data-product-count]').textContent, '6 fictional objects');
  assert.equal(
    document.querySelector('[data-product-filter="all"]').getAttribute('aria-pressed'),
    'true',
  );
  dom.window.close();
});

test('filter event delegation accepts nested button content and ignores other clicks', () => {
  const dom = page();
  const document = dom.window.document;
  const button = document.querySelector('[data-product-filter="paper"]');
  const label = document.createElement('span');
  label.textContent = 'On paper';
  button.replaceChildren(label);
  label.click();
  assert.equal(visibleCards(document).length, 1);
  assert.equal(visibleCards(document)[0].querySelector('h3').textContent, 'Plain notebook');
  document.querySelector('[data-catalogue-filters]').click();
  assert.equal(visibleCards(document).length, 1);
  dom.window.close();
});

test('a live catalogue without fixture controls keeps its published count', () => {
  const dom = page({
    transform: (source) =>
      source
        .replace(/<div class="cc-filters"[\s\S]*?<\/div>/, '')
        .replace('6 fictional objects', '6 published products'),
  });
  assert.equal(
    dom.window.document.querySelector('[data-product-count]').textContent.trim(),
    '6 published products',
  );
  assert.equal(visibleCards(dom.window.document).length, 6);
  dom.window.close();
});

test('static preview offers no payment, credential or checkout input', () => {
  const dom = page();
  const document = dom.window.document;
  assert.equal(document.querySelectorAll('form,input,textarea,select').length, 0);
  assert.equal(document.querySelectorAll('button:not([type="button"])').length, 0);
  assert.equal(
    document.querySelectorAll(
      'a[href^="http"],script[src^="http"],link[rel="stylesheet"][href^="http"]',
    ).length,
    0,
  );
  assert.match(document.body.textContent, /Fictional catalogue/);
  assert.match(document.body.textContent, /No checkout/);
  dom.window.close();
});
