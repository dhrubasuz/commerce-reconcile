/* Author / Creator / Last Modified By: Dhruba Poudel. GPL-2.0-or-later. */
(() => {
  'use strict';
  const header = document.querySelector('[data-canvas-header]');
  const toggle = document.querySelector('[data-menu-toggle]');
  const navigation = document.querySelector('[data-canvas-nav]');
  if (header && toggle && navigation) {
    header.setAttribute('data-navigation-ready', '');
    toggle.hidden = false;
    navigation.setAttribute('data-open', 'false');
    const close = () => {
      toggle.setAttribute('aria-expanded', 'false');
      navigation.setAttribute('data-open', 'false');
      toggle.textContent = 'Menu';
    };
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      navigation.setAttribute('data-open', String(!expanded));
      toggle.textContent = expanded ? 'Menu' : 'Close';
    });
    navigation.addEventListener('click', (event) => {
      if (event.target.closest('a')) close();
    });
    header.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        close();
        toggle.focus();
      }
    });
  }
  const controls = document.querySelector('[data-catalogue-filters]');
  const cards = [...document.querySelectorAll('[data-product-category]')];
  const count = document.querySelector('[data-product-count]');
  if (controls && cards.length && count) {
    controls.hidden = false;
    controls.addEventListener('click', (event) => {
      const selected = event.target.closest('[data-product-filter]');
      if (!selected || !controls.contains(selected)) return;
      const category = selected.getAttribute('data-product-filter');
      let shown = 0;
      for (const card of cards) {
        const visible =
          category === 'all' || card.getAttribute('data-product-category') === category;
        card.hidden = !visible;
        if (visible) shown += 1;
      }
      for (const button of controls.querySelectorAll('[data-product-filter]')) {
        button.setAttribute('aria-pressed', String(button === selected));
      }
      count.textContent = `${shown} fictional ${shown === 1 ? 'object' : 'objects'}`;
    });
  }
})();
