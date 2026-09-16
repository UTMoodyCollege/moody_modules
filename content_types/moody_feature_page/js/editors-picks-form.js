(function (Drupal, $, once) {
  'use strict';

  // Decode only Drupal's single-label wrapper; keep quotes in the story title.
  function cleanLabel(input) {
    const encoded = input.value.match(/^"((?:[^"]|"")* \(\d+\))"$/);
    if (encoded) input.value = encoded[1].replace(/""/g, '"');
  }

  Drupal.behaviors.moodyEditorsPicksForm = {
    attach(context) {
      once('moody-editors-picks', '.editors-picks-item-form', context).forEach((row) => {
        const input = row.querySelector('.editors-picks-node');
        const recent = row.querySelector('.editors-picks-recent');
        if (!input || !recent) return;
        cleanLabel(input);
        $(input).on('autocompleteclose blur', () => cleanLabel(input));
        input.addEventListener('input', () => { recent.value = ''; });
        recent.addEventListener('change', () => {
          if (!recent.value) return;
          input.value = `${recent.selectedOptions[0].textContent} (${recent.value})`;
          input.dispatchEvent(new Event('change', { bubbles: true }));
          input.focus();
          recent.value = '';
        });
      });
    },
  };
})(Drupal, jQuery, once);
