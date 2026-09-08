<?php

declare(strict_types=1);

// Standalone update-hook regression checks; no Drupal bootstrap or writes.
namespace Drupal\Core\Utility {
  class UpdateException extends \RuntimeException {}
}

namespace {
  final class CatalogConfig {
    public int $saves = 0;
    public function __construct(public ?array $data) {}
    public function isNew(): bool { return $this->data === NULL; }
    public function get(string $key): mixed { return $this->data[$key] ?? NULL; }
    public function set(string $key, mixed $value): self {
      $this->data[$key] = $value;
      return $this;
    }
    public function save(bool $trusted): void { $this->saves++; }
  }
  final class CatalogFactory {
    public function __construct(public CatalogConfig $entry, public CatalogConfig $category) {}
    public function getEditable(string $name): CatalogConfig {
      if ($name !== 'layout_builder_browser.layout_builder_browser_block.moody_hero_builder') {
        throw new \RuntimeException('Unexpected editable config');
      }
      return $this->entry;
    }
    public function get(string $name): CatalogConfig {
      if ($name !== 'layout_builder_browser.layout_builder_browser_blockcat.specialty_blocks') {
        throw new \RuntimeException('Unexpected category');
      }
      return $this->category;
    }
  }
  final class Drupal {
    public static CatalogFactory $factory;
    public static function configFactory(): CatalogFactory { return self::$factory; }
  }
  require dirname(__DIR__) . '/moody_hero_builder.install';
  $checks = 0;
  $check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new \RuntimeException($message); }
    $checks++;
  };
  $original = [
    'uuid' => 'preserved', 'block_id' => 'moody_hero_builder',
    'label' => 'Custom label', 'status' => FALSE, 'image_path' => '/custom.jpg',
    'category' => 'moody_site_building', 'weight' => -110,
    'dependencies' => ['module' => ['moody_hero_builder']],
  ];
  $entry = new CatalogConfig($original);
  Drupal::$factory = new CatalogFactory($entry, new CatalogConfig(['id' => 'specialty_blocks']));
  moody_hero_builder_update_9001();
  $check($entry->data === array_replace($original, ['category' => 'specialty_blocks', 'weight' => 0]), 'Only category and weight may change');
  moody_hero_builder_update_9001();
  $check($entry->saves === 1, 'A repeated update must not save again');

  Drupal::$factory = new CatalogFactory(new CatalogConfig(NULL), new CatalogConfig(NULL));
  moody_hero_builder_update_9001();
  $check(Drupal::$factory->entry->saves === 0 && Drupal::$factory->entry->isNew(), 'A site without the browser must remain unchanged');

  foreach ([[$original, NULL], [array_replace($original, ['block_id' => 'another_block']), ['id' => 'specialty_blocks']]] as [$data, $category]) {
    Drupal::$factory = new CatalogFactory(new CatalogConfig($data), new CatalogConfig($category));
    try {
      moody_hero_builder_update_9001();
      throw new \RuntimeException('Unsafe update was accepted');
    }
    catch (\Drupal\Core\Utility\UpdateException) {
      $check(Drupal::$factory->entry->data === $data && Drupal::$factory->entry->saves === 0, 'Failed preflight must preserve config');
    }
  }
  $defaults = file_get_contents(dirname(__DIR__) . '/config/optional/layout_builder_browser.layout_builder_browser_block.moody_hero_builder.yml');
  $check((bool) preg_match('/^category: specialty_blocks$/m', $defaults), 'New installs must use Specialty Blocks');
  $check((bool) preg_match('/^weight: 0$/m', $defaults), 'New installs must not be forced to the top');
  $check(str_contains($defaults, '- layout_builder_browser.layout_builder_browser_blockcat.specialty_blocks'), 'The category must be an install dependency');
  $plugin = file_get_contents(dirname(__DIR__) . '/src/Plugin/Block/MoodyHeroBuilderBlock.php');
  $check(str_contains($plugin, 'category = @Translation("Specialty Blocks")'), 'The native block browser must use the same category');
  print "Hero catalog update: {$checks} checks passed.\n";
}
