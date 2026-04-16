/**
 * @file
 * Initializes Chart.js instances for every Moody Chart canvas on the page.
 *
 * Each canvas carries its configuration as data attributes set server-side:
 *   data-chart-type    — Chart.js type string (bar, line, pie, …)
 *   data-chart-data    — JSON-encoded Chart.js data object
 *   data-chart-options — JSON-encoded Chart.js options object
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.moodyCharts = {
    attach: function (context) {
      once('moody-charts', '.moody-chart-canvas', context).forEach(function (canvas) {
        var type    = canvas.dataset.chartType    || 'bar';
        var rawData = canvas.dataset.chartData    || '{}';
        var rawOpts = canvas.dataset.chartOptions || '{}';

        var data, options;
        try {
          data    = JSON.parse(rawData);
          options = JSON.parse(rawOpts);
        }
        catch (e) {
          console.error('Moody Charts: failed to parse chart data for #' + canvas.id, e);
          return;
        }

        // For pie / doughnut / polarArea charts, spread the colour palette
        // across individual data points rather than per-dataset.
        var pieLike = ['pie', 'doughnut', 'polarArea'];
        if (pieLike.indexOf(type) !== -1 && data.datasets) {
          data.datasets.forEach(function (dataset) {
            if (!Array.isArray(dataset.backgroundColor)) {
              dataset.backgroundColor = Drupal.moodyCharts.expandColor(
                dataset.backgroundColor,
                data.labels ? data.labels.length : 0
              );
              dataset.borderColor = '#ffffff';
            }
          });
        }

        // Remove scale options that don't apply to pie-like charts.
        if (pieLike.indexOf(type) !== -1 && options.scales) {
          delete options.scales;
        }

        new Chart(canvas, {
          type:    type,
          data:    data,
          options: options,
        });
      });
    },
  };

  /**
   * Utility namespace for colour helpers.
   */
  Drupal.moodyCharts = Drupal.moodyCharts || {};

  /**
   * Expands a single colour into a palette array for pie-like charts.
   *
   * Generates a sequence of shades derived from the base colour so each
   * segment is visually distinct while staying on-brand.
   *
   * @param {string} baseColor - CSS colour string, e.g. "#bf5700".
   * @param {number} count     - Number of colours to generate.
   * @returns {string[]}       - Array of CSS colour strings.
   */
  Drupal.moodyCharts.expandColor = function (baseColor, count) {
    var utPalette = [
      '#bf5700', '#333f48', '#005f86', '#579d42',
      '#f8971f', '#9cadb7', '#d6d2c4', '#cabfad',
    ];
    if (count <= utPalette.length) {
      return utPalette.slice(0, count);
    }
    // Cycle through palette if more colours are needed.
    var result = [];
    for (var i = 0; i < count; i++) {
      result.push(utPalette[i % utPalette.length]);
    }
    return result;
  };

}(Drupal, once));
