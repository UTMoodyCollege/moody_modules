(function (Drupal, once) {
  Drupal.behaviors.moodyLayoutBuilderBlockTypeLabels = {
    attach(context) {
      once(
        'moody-layout-builder-block-type-labels',
        '#moody-layout-builder-block-type-labels',
        context,
      ).forEach((toggle) => {
        const layoutBuilder = document.getElementById('layout-builder');
        if (!layoutBuilder) {
          return;
        }

        const storageId = toggle.dataset.blockTypeLabelsId;
        const setLabelsVisible = (visible) => {
          toggle.checked = visible;
          layoutBuilder.classList.toggle(
            'layout-builder--block-type-labels-disabled',
            !visible,
          );
        };

        setLabelsVisible(
          JSON.parse(localStorage.getItem(storageId)) !== false,
        );
        toggle.addEventListener('change', () => {
          localStorage.setItem(storageId, JSON.stringify(toggle.checked));
          setLabelsVisible(toggle.checked);
          Drupal.announce(
            toggle.checked
              ? Drupal.t('Block type labels are visible.')
              : Drupal.t('Block type labels are hidden.'),
          );
        });
      });
    },
  };
})(Drupal, once);
