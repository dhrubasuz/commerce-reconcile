/* Author: Dhruba Poudel. Same-origin admin REST adapter; no external provider requests. */
(() => {
  'use strict';
  const payload = document.querySelector('#cr-payload');
  const analyse = document.querySelector('#cr-analyse');
  const reset = document.querySelector('#cr-reset');
  if (!payload || !analyse || !reset || !window.CommerceReconcileConfig) return;
  const sample = payload.value;
  const status = document.querySelector('#cr-status');
  const results = document.querySelector('#cr-results');
  const config = window.CommerceReconcileConfig;
  reset.addEventListener('click', () => {
    payload.value = sample;
    results.hidden = true;
    status.textContent = 'Fictional sample restored. No order has been changed.';
  });
  analyse.addEventListener('click', async () => {
    analyse.disabled = true;
    reset.disabled = true;
    results.hidden = true;
    status.textContent = 'Analysing the snapshot…';
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      if (new TextEncoder().encode(payload.value).length > config.maxBytes) throw new Error('Payload exceeds 256 KiB.');
      if (new URL(config.endpoint, location.href).origin !== location.origin) throw new Error('The review endpoint must use this WordPress origin.');
      const body = JSON.stringify(JSON.parse(payload.value));
      const response = await fetch(config.endpoint, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body,
      });
      const report = await response.json();
      if (!response.ok) throw new Error(report.message || 'The review could not be completed.');
      const summary = document.querySelector('#cr-summary');
      summary.replaceChildren();
      for (const [key, value] of Object.entries(report.summary)) {
        const item = document.createElement('span');
        item.className = 'cr-summary-item';
        item.textContent = `${key}: ${value}`;
        summary.append(item);
      }
      const rows = document.querySelector('#cr-rows');
      rows.replaceChildren();
      for (const row of report.rows) {
        const tr = document.createElement('tr');
        for (const value of [row.event_id, row.order_id, row.disposition, row.issues.join(', ') || 'All compared fields agree']) {
          const td = document.createElement('td');
          td.textContent = String(value);
          tr.append(td);
        }
        rows.append(tr);
      }
      document.querySelector('#cr-hash').textContent = `SHA-256: ${report.report_hash}`;
      results.hidden = false;
      status.textContent = 'Review complete. Source orders and external records are unchanged.';
    } catch (error) {
      status.textContent = error.name === 'AbortError'
        ? 'The request timed out. No order-changing operation was attempted. Re-analyse to retrieve a fresh report.'
        : `Review stopped: ${error.message}`;
    } finally {
      clearTimeout(timer);
      analyse.disabled = false;
      reset.disabled = false;
    }
  });
})();
