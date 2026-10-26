/* Author / Creator: Dhruba Poudel. No source or order writes. */
'use strict';
const $ = (selector) => document.querySelector(selector);
const state = {
  response: null,
  filter: 'all',
  query: '',
  selected: -1,
  request: 0,
  controller: null,
};
const reasonNames = {
  amount_mismatch: 'Amount mismatch',
  currency_mismatch: 'Currency mismatch',
  reference_mismatch: 'Reference mismatch',
  status_mismatch: 'Status mismatch',
  missing_order: 'Order not found',
  duplicate_event: 'Exact duplicate',
  stale_event: 'Older sequence',
  event_id_conflict: 'Conflicting event ID',
  sequence_conflict: 'Ambiguous sequence',
  event_replay: 'Exact replay',
};
const reasonDescriptions = {
  amount_mismatch:
    'The incoming amount differs from the source order. Amounts are compared as exact integer minor units, with no floating-point rounding.',
  currency_mismatch:
    'The incoming currency differs from the source order. Matching numeric amounts do not make different currencies equivalent.',
  reference_mismatch:
    'The event points to the order ID, but its reference does not agree with the source snapshot.',
  status_mismatch:
    'The incoming status differs from the order snapshot. The review makes the difference visible without changing either record.',
  missing_order:
    'No order with this exact ID exists in the supplied snapshot. The engine does not search for a similar reference or invent an order.',
  duplicate_event:
    'This exact event ID and payload already appeared in the same input batch. The additional delivery is explained and ignored.',
  stale_event:
    'A newer admissible sequence for this order is already present. Arrival order cannot cause this older event to replace it.',
  event_id_conflict:
    'The same event ID has different payloads. Both conflicting versions are held. An earlier arrival cannot silently become the accepted version.',
  sequence_conflict:
    'Different event IDs claim the same highest sequence for this order. Both remain exceptions until the source resolves the ambiguity.',
  event_replay:
    'The event ID and canonical payload fingerprint agree with the explicitly supplied prior ledger. This is an exact replay, not a new application.',
};
function node(tag, text, className) {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = String(text);
  if (className) element.className = className;
  return element;
}
function formatAmount(minor, currency) {
  return (
    new Intl.NumberFormat('en-AU', { style: 'currency', currency }).format(minor / 100) +
    ' ' +
    currency
  );
}
function outcomeLabel(value) {
  return { matched: 'Matched', exception: 'Needs review', ignored: 'Ignored' }[value] || value;
}
function announce(text) {
  $('#status').textContent = text;
}
function filteredRows() {
  return state.response.report.rows
    .map((row, index) => ({ row, index }))
    .filter(
      ({ row }) =>
        (state.filter === 'all' || row.disposition === state.filter) &&
        (!state.query ||
          [row.reference, row.order_id, row.event_id, ...row.issues]
            .join(' ')
            .toLowerCase()
            .includes(state.query)),
    );
}
function renderRegister() {
  const entries = filteredRows();
  const body = $('#event-rows');
  body.replaceChildren();
  if (!entries.some((entry) => entry.index === state.selected))
    state.selected = entries[0]?.index ?? -1;
  $('#register-count').textContent =
    `${entries.length} of ${state.response.report.rows.length} event rows shown`;
  $('#empty').hidden = entries.length !== 0;
  for (const { row, index } of entries) {
    const tr = node('tr');
    if (index === state.selected) tr.classList.add('selected');
    const idCell = node('td');
    const button = node('button', row.reference, 'row-select');
    button.type = 'button';
    button.setAttribute(
      'aria-label',
      `Inspect ${row.reference}, ${row.event_id}, row ${index + 1}`,
    );
    button.setAttribute('aria-pressed', String(index === state.selected));
    button.append(node('small', row.event_id));
    button.addEventListener('click', () => {
      state.selected = index;
      renderRegister();
      renderInspector();
    });
    idCell.append(button);
    tr.append(idCell, node('td', row.sequence));
    const status = node('td');
    status.append(node('span', outcomeLabel(row.disposition), `pill ${row.disposition}`));
    tr.append(
      status,
      node(
        'td',
        row.issues.length
          ? row.issues.map((issue) => reasonNames[issue] || issue).join(' · ')
          : 'All fields agree',
      ),
    );
    body.append(tr);
  }
  $('.inspector').hidden = entries.length === 0;
  if (entries.length) renderInspector();
}
function renderInspector() {
  const row = state.response?.report.rows[state.selected];
  if (!row) return;
  $('#inspector-title').textContent = row.reference;
  $('#inspector-subtitle').textContent =
    `${row.event_id} · Order #${row.order_id} · Sequence ${row.sequence}`;
  const pill = $('#selected-outcome');
  pill.textContent = outcomeLabel(row.disposition);
  pill.className = `pill ${row.disposition}`;
  $('#issue-explanation').textContent = row.issues.length
    ? row.issues.map((issue) => reasonDescriptions[issue] || issue).join(' ')
    : 'Reference, amount, currency and status all agree with the exact order ID in this snapshot. No order is changed.';
  const comparison = $('#comparison');
  comparison.replaceChildren();
  const header = node('div', undefined, 'comparison-header');
  header.append(node('span', 'Order snapshot'), node('span', 'Incoming event'));
  comparison.append(header);
  for (const [field, label] of [
    ['reference', 'Reference'],
    ['amount_minor', 'Amount'],
    ['currency', 'Currency'],
    ['status', 'Status'],
  ]) {
    const section = node('div', undefined, 'comparison-row');
    if (!row.order || row.order[field] !== row.external[field]) section.classList.add('different');
    section.append(node('span', label));
    const values = node('div');
    const source = row.order
      ? field === 'amount_minor'
        ? formatAmount(row.order[field], row.order.currency)
        : row.order[field]
      : 'Not found';
    const incoming =
      field === 'amount_minor'
        ? formatAmount(row.external[field], row.external.currency)
        : row.external[field];
    values.append(node('strong', source), node('strong', incoming));
    section.append(values);
    comparison.append(section);
  }
  $('#event-payload').textContent = JSON.stringify(row.external, null, 2);
}
function renderReview() {
  const response = state.response;
  for (const element of document.querySelectorAll('[data-metric]'))
    element.textContent = response.report.summary[element.dataset.metric];
  $('#scenario-explanation').textContent = response.explanation;
  $('#report-hash').textContent = response.report.report_hash;
  $('#source-hash').textContent = response.source_orders_sha256;
  state.selected = response.report.rows.findIndex((row) => row.disposition === 'exception');
  if (state.selected < 0) state.selected = 0;
  renderRegister();
  renderInspector();
}
async function runReview() {
  const request = ++state.request;
  state.controller?.abort();
  state.controller = new AbortController();
  const scenario = $('#scenario').value;
  const controller = state.controller;
  let timedOut = false;
  const deadline = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, 15000);
  $('#run').disabled = true;
  $('#download').disabled = true;
  $('#error-panel').hidden = true;
  $('#review-results').setAttribute('aria-busy', 'true');
  announce('Running the PHP reconciliation…');
  try {
    const response = await fetch(`api.php?scenario=${encodeURIComponent(scenario)}`, {
      signal: state.controller.signal,
      credentials: 'omit',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('The PHP endpoint did not return a successful response.');
    const result = await response.json();
    if (request !== state.request) return;
    if (
      result.scenario !== scenario ||
      !result.report?.summary ||
      !Array.isArray(result.report.rows) ||
      !Number.isInteger(result.report.summary.events) ||
      result.report.rows.length !== result.report.summary.events ||
      !/^[a-f0-9]{64}$/.test(result.report.report_hash) ||
      !/^[a-f0-9]{64}$/.test(result.source_orders_sha256)
    )
      throw new Error('The review response was incomplete.');
    state.response = result;
    renderReview();
    $('#review-results').hidden = false;
    $('#download').disabled = false;
    announce('Review complete · Fictional snapshot · No order writes');
  } catch (error) {
    if (request !== state.request || (error.name === 'AbortError' && !timedOut)) return;
    state.response = null;
    $('#review-results').hidden = true;
    $('#error-panel').hidden = false;
    $('#error-message').textContent = timedOut
      ? 'The review timed out after 15 seconds. Please retry.'
      : 'Please retry. The demonstration only processes its bundled fictional scenarios.';
    announce('Review failed. No result is being shown.');
  } finally {
    clearTimeout(deadline);
    if (request === state.request) {
      $('#run').disabled = false;
      $('#review-results').setAttribute('aria-busy', 'false');
    }
  }
}
for (const button of document.querySelectorAll('[data-filter]'))
  button.addEventListener('click', () => {
    state.filter = button.dataset.filter;
    for (const item of document.querySelectorAll('[data-filter]'))
      item.setAttribute('aria-pressed', String(item === button));
    if (state.response) renderRegister();
  });
$('#search').addEventListener('input', (event) => {
  state.query = event.target.value.trim().toLowerCase();
  if (state.response) renderRegister();
});
$('#clear').addEventListener('click', () => {
  $('#search').value = '';
  state.query = '';
  state.filter = 'all';
  for (const item of document.querySelectorAll('[data-filter]'))
    item.setAttribute('aria-pressed', String(item.dataset.filter === 'all'));
  if (state.response) renderRegister();
});
$('#run').addEventListener('click', runReview);
$('#retry').addEventListener('click', runReview);
$('#download').addEventListener('click', () => {
  if (!state.response) return;
  const blob = new Blob([JSON.stringify(state.response, null, 2) + '\n'], {
    type: 'application/json',
  });
  const url = URL.createObjectURL(blob);
  const a = node('a');
  a.href = url;
  a.download = `commerce-reconcile-${state.response.scenario}.json`;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  announce('Fictional review JSON exported.');
});
runReview();
