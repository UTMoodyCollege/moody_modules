(function (Drupal, once, drupalSettings) {
  'use strict';

  function renumberRows(table) {
    table.querySelectorAll('tbody > tr.draggable').forEach((row, index) => {
      const weight = row.querySelector('.delta-order select, .delta-order input');
      if (weight) {
        weight.value = index;
      }
    });
  }

  function fields(row) {
    return {
      title: row.querySelector('input[name$="[title]"]'),
      url: row.querySelector('input[name$="[link]"]'),
    };
  }

  function setStatus(form, message) {
    const status = form.querySelector('.js-moody-subsite-related-pages-status');
    if (status) {
      status.textContent = message;
    }
  }

  function setBusy(form, busy) {
    form.querySelectorAll('.js-moody-subsite-related-pages').forEach((button) => {
      button.disabled = busy;
    });
  }

  function blankRow(form) {
    return [...form.querySelectorAll('.moody-subsite-menu-table tbody > tr.draggable')]
      .find((row) => {
        const inputs = fields(row);
        return inputs.title && inputs.url && !inputs.title.value.trim() && !inputs.url.value.trim();
      });
  }

  function addRow(form) {
    return new Promise((resolve, reject) => {
      const add = form.querySelector('[data-drupal-selector="edit-subsite-nav-add-more"]');
      if (!add) {
        reject(new Error(Drupal.t('The Add navigation link button is unavailable.')));
        return;
      }

      const rowCount = form.querySelectorAll('.moody-subsite-menu-table tbody > tr.draggable').length;
      const observer = new MutationObserver(() => {
        const rows = form.querySelectorAll('.moody-subsite-menu-table tbody > tr.draggable');
        const row = blankRow(form);
        if (rows.length > rowCount && row) {
          clearTimeout(timeout);
          observer.disconnect();
          resolve(row);
        }
      });
      const timeout = setTimeout(() => {
        observer.disconnect();
        reject(new Error(Drupal.t('A new navigation row could not be added.')));
      }, 10000);

      observer.observe(form, {childList: true, subtree: true});
      add.dispatchEvent(new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        view: window,
      }));
    });
  }

  function updateInput(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
  }

  async function addRelatedPages(button) {
    const form = button.closest('form');
    const pages = drupalSettings.moodySubsite?.relatedPages || [];
    if (!form || !pages.length) {
      return;
    }

    const titles = new Set();
    const urls = new Set();
    form.querySelectorAll('.moody-subsite-menu-table tbody > tr.draggable').forEach((row) => {
      const inputs = fields(row);
      if (inputs.title?.value.trim()) {
        titles.add(inputs.title.value.trim().toLowerCase());
      }
      if (inputs.url?.value.trim()) {
        urls.add(inputs.url.value.trim().replace(/\/$/, ''));
      }
    });
    const missing = pages.filter((page) =>
      !titles.has(page.title.trim().toLowerCase()) && !urls.has(page.url.replace(/\/$/, '')),
    );

    if (!missing.length) {
      setStatus(form, Drupal.t('All related pages are already in the navigation.'));
      return;
    }

    setBusy(form, true);
    try {
      let added = 0;
      for (const page of missing) {
        let row = blankRow(form);
        if (!row) {
          setStatus(form, Drupal.t('Adding another navigation row…'));
          row = await addRow(form);
          setBusy(form, true);
        }
        const inputs = fields(row);
        updateInput(inputs.title, page.title);
        updateInput(inputs.url, page.url);
        added++;
      }
      const message = Drupal.formatPlural(
        added,
        'Added 1 related page. Review and save the form.',
        'Added @count related pages. Review and save the form.',
      );
      setStatus(form, message);
    }
    catch (error) {
      setStatus(form, error.message);
    }
    finally {
      setBusy(form, false);
    }
  }

  Drupal.behaviors.moodySubsiteMenuEditor = {
    attach(context) {
      once('moody-subsite-menu-editor', '.moody-subsite-menu-table', context).forEach((table) => {
        const tableDrag = Drupal.tableDrag && Drupal.tableDrag[table.id];
        if (!tableDrag) {
          return;
        }

        const originalOnDrop = tableDrag.onDrop;
        tableDrag.onDrop = function () {
          originalOnDrop.call(this);
          renumberRows(table);
        };
      });
      once('moody-subsite-related-pages', '.js-moody-subsite-related-pages', context).forEach((button) => {
        button.addEventListener('click', () => addRelatedPages(button));
      });
    },
  };
})(Drupal, once, drupalSettings);
