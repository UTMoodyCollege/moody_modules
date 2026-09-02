(function (Drupal, once) {
  'use strict';

  function renumberRows(table) {
    table.querySelectorAll('tbody > tr.draggable').forEach((row, index) => {
      const weight = row.querySelector('.delta-order select, .delta-order input');
      if (weight) {
        weight.value = index;
      }
    });
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
    },
  };
})(Drupal, once);
