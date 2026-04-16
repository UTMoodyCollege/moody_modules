<?php

declare(strict_types=1);

namespace Drupal\moody_charts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Moody Charts block powered by Chart.js.
 *
 * Accepts CSV (pasted or uploaded) or XLSX (uploaded) data and renders an
 * interactive Chart.js visualization with UT-branded colour options.
 *
 * @Block(
 *   id = "moody_charts_block",
 *   admin_label = @Translation("Moody Chart"),
 *   category = @Translation("Moody"),
 * )
 */
final class MoodyChartsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * UT brand colour palettes available to chart editors.
   */
  const COLOR_SCHEMES = [
    'burnt_orange' => 'UT Burnt Orange',
    'charcoal'     => 'UT Charcoal',
    'bluebonnet'   => 'UT Bluebonnet',
    'mixed_ut'     => 'Mixed UT Palette',
  ];

  /**
   * Chart types supported by Chart.js.
   */
  const CHART_TYPES = [
    'bar'       => 'Bar',
    'line'      => 'Line',
    'pie'       => 'Pie',
    'doughnut'  => 'Doughnut',
    'radar'     => 'Radar',
    'polarArea' => 'Polar Area',
  ];

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('stream_wrapper_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'chart_type'    => 'bar',
      'chart_title'   => '',
      'chart_data'    => '',
      'color_scheme'  => 'mixed_ut',
      'show_legend'   => TRUE,
      'show_grid'     => TRUE,
      'aspect_ratio'  => '2',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['chart_type'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Chart type'),
      '#options'       => self::CHART_TYPES,
      '#default_value' => $this->configuration['chart_type'],
      '#required'      => TRUE,
    ];

    $form['chart_title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Chart title'),
      '#description'   => $this->t('Optional title displayed above the chart.'),
      '#default_value' => $this->configuration['chart_title'],
    ];

    $form['data_source'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Data source'),
    ];

    $form['data_source']['csv_paste'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Paste CSV data'),
      '#description'   => $this->t(
        'Paste comma-separated values. The first row contains dataset names (first cell is ignored). Each subsequent row starts with an x-axis label followed by values. Example:<br><code>Label,Sales,Returns<br>Jan,10,2<br>Feb,20,4<br>Mar,30,6</code>'
      ),
      '#rows'          => 8,
      '#default_value' => '',
    ];

    $form['data_source']['csv_file'] = [
      '#type'               => 'managed_file',
      '#title'              => $this->t('Upload CSV or XLSX file'),
      '#description'        => $this->t('Upload a .csv or .xlsx file. Uploaded data will be parsed and replace any pasted CSV above.'),
      '#upload_location'    => 'public://moody_charts/',
      '#upload_validators'  => [
        'file_validate_extensions' => ['csv xlsx'],
        'file_validate_size'       => [2 * 1024 * 1024],
      ],
      '#default_value'      => NULL,
    ];

    $form['color_scheme'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Colour scheme'),
      '#options'       => self::COLOR_SCHEMES,
      '#default_value' => $this->configuration['color_scheme'],
    ];

    $form['show_legend'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Show legend'),
      '#default_value' => $this->configuration['show_legend'],
    ];

    $form['show_grid'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Show grid lines'),
      '#default_value' => $this->configuration['show_grid'],
    ];

    $form['aspect_ratio'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Aspect ratio'),
      '#options'       => [
        '1'    => '1:1 (Square)',
        '1.5'  => '3:2',
        '2'    => '2:1 (Default)',
        '2.5'  => '5:2 (Wide)',
        '3'    => '3:1 (Extra wide)',
      ],
      '#default_value' => $this->configuration['aspect_ratio'],
    ];

    // Show currently stored data as read-only info.
    if (!empty($this->configuration['chart_data'])) {
      $stored = json_decode($this->configuration['chart_data'], TRUE);
      if (!empty($stored['labels'])) {
        $form['current_data_notice'] = [
          '#type'   => 'item',
          '#markup' => $this->t(
            '<strong>Current data:</strong> %count label(s) — %datasets dataset(s). Re-submit with new CSV/file to replace.',
            [
              '%count'    => count($stored['labels']),
              '%datasets' => count($stored['datasets'] ?? []),
            ]
          ),
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['chart_type']   = $form_state->getValue('chart_type');
    $this->configuration['chart_title']  = $form_state->getValue('chart_title');
    $this->configuration['color_scheme'] = $form_state->getValue('color_scheme');
    $this->configuration['show_legend']  = (bool) $form_state->getValue('show_legend');
    $this->configuration['show_grid']    = (bool) $form_state->getValue('show_grid');
    $this->configuration['aspect_ratio'] = $form_state->getValue('aspect_ratio');

    $data_source = $form_state->getValue('data_source');
    $file_ids    = $data_source['csv_file'] ?? [];
    $csv_paste   = trim($data_source['csv_paste'] ?? '');

    // A newly uploaded file takes priority over pasted CSV.
    if (!empty($file_ids)) {
      $fid  = reset($file_ids);
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        $uri      = $file->getFileUri();
        $realpath = $this->fileSystem->realpath($uri);
        $ext      = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

        $parsed = $ext === 'xlsx'
          ? $this->parseXlsx($realpath)
          : $this->parseCsvString(file_get_contents($realpath));

        if ($parsed !== NULL) {
          $this->configuration['chart_data'] = json_encode($parsed);
        }
      }
    }
    elseif ($csv_paste !== '') {
      $parsed = $this->parseCsvString($csv_paste);
      if ($parsed !== NULL) {
        $this->configuration['chart_data'] = json_encode($parsed);
      }
    }
    // If neither supplied, preserve existing chart_data.
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $chart_data = $this->configuration['chart_data'] ?? '';
    if (empty($chart_data)) {
      return [
        '#markup' => $this->t('<p class="moody-chart-empty">No chart data configured yet. Edit this block to add CSV or XLSX data.</p>'),
      ];
    }

    $chart_id = 'moody-chart-' . substr(md5(uniqid('', TRUE)), 0, 8);

    $options = [
      'aspectRatio' => (float) ($this->configuration['aspect_ratio'] ?? 2),
      'plugins'     => [
        'legend' => ['display' => (bool) $this->configuration['show_legend']],
        'title'  => [
          'display' => !empty($this->configuration['chart_title']),
          'text'    => $this->configuration['chart_title'] ?? '',
        ],
      ],
      'scales' => [
        'x' => ['display' => (bool) $this->configuration['show_grid'], 'grid' => ['display' => (bool) $this->configuration['show_grid']]],
        'y' => ['display' => (bool) $this->configuration['show_grid'], 'grid' => ['display' => (bool) $this->configuration['show_grid']]],
      ],
    ];

    $decoded_data = json_decode($chart_data, TRUE);
    $colored_data = $this->applyColorScheme($decoded_data, $this->configuration['color_scheme'] ?? 'mixed_ut');

    $build = [
      '#theme'       => 'moody_charts',
      '#chart_id'    => $chart_id,
      '#chart_type'  => $this->configuration['chart_type'],
      '#chart_data'  => json_encode($colored_data),
      '#chart_title' => $this->configuration['chart_title'] ?? '',
      '#options'     => json_encode($options),
      '#attached'    => ['library' => ['moody_charts/moody_charts']],
    ];

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Parses a raw CSV string into a Chart.js data structure.
   *
   * Expected format:
   *   Row 0 → header: first cell is ignored, remaining cells are dataset labels.
   *   Rows 1-N → data: first cell is the x-axis label, remaining cells are values.
   *
   * @param string $csv
   *   Raw CSV text.
   *
   * @return array|null
   *   Chart.js compatible data array or NULL on parse failure.
   */
  protected function parseCsvString(string $csv): ?array {
    // Normalise line endings.
    $csv   = str_replace(["\r\n", "\r"], "\n", trim($csv));
    $lines = array_filter(explode("\n", $csv));
    if (count($lines) < 2) {
      return NULL;
    }

    $rows = array_map('str_getcsv', $lines);

    // First row: dataset names (skip first cell).
    $header       = array_shift($rows);
    $dataset_names = array_slice($header, 1);

    $labels   = [];
    $datasets = [];

    foreach ($dataset_names as $name) {
      $datasets[] = ['label' => $name, 'data' => []];
    }

    foreach ($rows as $row) {
      $labels[] = $row[0] ?? '';
      foreach ($dataset_names as $idx => $name) {
        $raw = $row[$idx + 1] ?? '';
        $datasets[$idx]['data'][] = is_numeric($raw) ? (float) $raw : 0;
      }
    }

    return ['labels' => $labels, 'datasets' => $datasets];
  }

  /**
   * Parses an XLSX file into a Chart.js data structure.
   *
   * Uses ZipArchive + SimpleXML — no additional PHP extensions required beyond
   * what Drupal itself already depends on.
   *
   * @param string $filepath
   *   Absolute path to the .xlsx file.
   *
   * @return array|null
   *   Chart.js compatible data array or NULL on parse failure.
   */
  protected function parseXlsx(string $filepath): ?array {
    if (!class_exists('ZipArchive')) {
      \Drupal::logger('moody_charts')->error('ZipArchive extension is required to parse XLSX files.');
      return NULL;
    }

    $zip = new \ZipArchive();
    if ($zip->open($filepath) !== TRUE) {
      return NULL;
    }

    // Read shared strings (optional but present in most xlsx files).
    $shared_strings = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml !== FALSE) {
      $ss = simplexml_load_string($ss_xml);
      if ($ss) {
        foreach ($ss->si as $si) {
          // Concatenate all <t> elements within the <si> entry.
          $text = '';
          foreach ($si->r as $r) {
            $text .= (string) $r->t;
          }
          if ($text === '') {
            $text = (string) $si->t;
          }
          $shared_strings[] = $text;
        }
      }
    }

    // Read the first worksheet.
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheet_xml === FALSE) {
      return NULL;
    }

    $sheet = simplexml_load_string($sheet_xml);
    if (!$sheet) {
      return NULL;
    }

    // Build a row-indexed array of cells.
    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
      $row_idx = (int) $row['r'] - 1;
      foreach ($row->c as $cell) {
        $ref     = (string) $cell['r'];
        $col_idx = $this->xlsxColIndex($ref);
        $type    = (string) $cell['t'];
        $value   = (string) $cell->v;

        if ($type === 's') {
          $value = $shared_strings[(int) $value] ?? $value;
        }
        $grid[$row_idx][$col_idx] = $value;
      }
    }

    if (empty($grid)) {
      return NULL;
    }

    // Convert grid to rows array ordered by row index.
    ksort($grid);
    $rows = [];
    foreach ($grid as $row_data) {
      ksort($row_data);
      $rows[] = array_values($row_data);
    }

    return $this->parseCsvString($this->rowsToCsv($rows));
  }

  /**
   * Converts a column reference (e.g. "A", "B", "AA") to a zero-based index.
   */
  protected function xlsxColIndex(string $cell_ref): int {
    preg_match('/^([A-Z]+)/', strtoupper($cell_ref), $matches);
    $col   = $matches[1] ?? 'A';
    $index = 0;
    for ($i = 0; $i < strlen($col); $i++) {
      $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $index - 1;
  }

  /**
   * Serialises a 2-D array of rows back to a CSV string for parseCsvString().
   *
   * @param array $rows
   *   2-D array of rows.
   *
   * @return string
   *   CSV text.
   */
  protected function rowsToCsv(array $rows): string {
    $lines = [];
    foreach ($rows as $row) {
      $cells = array_map(function ($cell) {
        // Wrap cells containing commas or quotes in double-quotes.
        if (str_contains((string) $cell, ',') || str_contains((string) $cell, '"')) {
          return '"' . str_replace('"', '""', (string) $cell) . '"';
        }
        return (string) $cell;
      }, $row);
      $lines[] = implode(',', $cells);
    }
    return implode("\n", $lines);
  }

  /**
   * Applies UT-branded colours to Chart.js dataset objects.
   *
   * @param array $data
   *   Chart.js data array with a 'datasets' key.
   * @param string $scheme
   *   One of the keys in self::COLOR_SCHEMES.
   *
   * @return array
   *   Data array with backgroundColor / borderColor added to each dataset.
   */
  protected function applyColorScheme(array $data, string $scheme): array {
    $palettes = [
      'burnt_orange' => ['#bf5700', '#d46a00', '#e87e00', '#f99300', '#ffa726'],
      'charcoal'     => ['#333f48', '#4a5a67', '#617485', '#7990a3', '#92acc1'],
      'bluebonnet'   => ['#005f86', '#00779e', '#0090b7', '#00aad0', '#00c5ea'],
      'mixed_ut'     => ['#bf5700', '#333f48', '#005f86', '#579d42', '#f8971f', '#9cadb7', '#d6d2c4'],
    ];

    $colors = $palettes[$scheme] ?? $palettes['mixed_ut'];

    foreach ($data['datasets'] as $idx => &$dataset) {
      $color = $colors[$idx % count($colors)];
      // Pie/doughnut/polarArea use arrays of colours (one per label).
      if (!isset($dataset['backgroundColor'])) {
        $dataset['backgroundColor'] = $color;
        $dataset['borderColor']     = $color;
        $dataset['borderWidth']     = 2;
      }
    }

    return $data;
  }

}
