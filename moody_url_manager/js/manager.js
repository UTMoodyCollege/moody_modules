(function (Drupal, once) {
  Drupal.behaviors.moodyUrlSelection = {
    attach(context) {
      once('moody-url-selection', '.url-select-all', context).forEach((button) => {
        button.addEventListener('click', () => {
          const boxes = [...button.form.querySelectorAll('.url-table input[type="checkbox"]:not(:disabled)')];
          const select = boxes.some((box) => !box.checked);
          boxes.forEach((box) => { box.checked = select; box.dispatchEvent(new Event('change', { bubbles: true })); });
          button.textContent = select ? Drupal.t('Clear selection') : Drupal.t('Select all editable pages shown');
        });
      });
    }
  };
})(Drupal, once);
