<?php

namespace Drupal\moody_page_convert;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Converts nodes between supported Moody Layout Builder page types.
 */
class PageConverter {

  /**
   * Page bundles supported by this utility.
   */
  public const SUPPORTED_BUNDLES = [
    'moody_standard_page',
    'moody_landing_page',
    'moody_subsite_page',
    'moody_feature_page',
  ];

  /**
   * Constructs the converter.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Gets installed supported node types.
   *
   * @return \Drupal\node\NodeTypeInterface[]
   *   Supported node types keyed by bundle ID.
   */
  public function getAvailableTypes() {
    $loaded = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple(self::SUPPORTED_BUNDLES);
    $types = [];
    foreach (self::SUPPORTED_BUNDLES as $bundle) {
      if (isset($loaded[$bundle])) {
        $types[$bundle] = $loaded[$bundle];
      }
    }
    return $types;
  }

  /**
   * Validates a requested conversion.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the conversion is not supported.
   */
  public function validateConversion(NodeInterface $node, string $target_bundle): void {
    $types = $this->getAvailableTypes();
    if (!isset($types[$node->bundle()])) {
      throw new \InvalidArgumentException('The selected node is not a supported Moody page type.');
    }
    if (!isset($types[$target_bundle])) {
      throw new \InvalidArgumentException('Select a supported target page type.');
    }
    if ($node->bundle() === $target_bundle) {
      throw new \InvalidArgumentException('Select a page type different from the current type.');
    }

    foreach ([$node->bundle(), $target_bundle] as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      if (!isset($definitions['layout_builder__layout'])) {
        throw new \InvalidArgumentException('Both page types must support Layout Builder overrides.');
      }
    }
  }

  /**
   * Converts a node and returns its new default revision.
   */
  public function convert(NodeInterface $node, string $target_bundle): NodeInterface {
    $this->validateConversion($node, $target_bundle);
    $source_bundle = $node->bundle();
    $values = $this->getSharedFieldValues($node, $target_bundle);
    $storage = $this->entityTypeManager->getStorage('node');
    $transaction = $this->database->startTransaction();

    try {
      $definition = $this->entityTypeManager->getDefinition('node');
      foreach (array_filter([$definition->getBaseTable(), $definition->getDataTable()]) as $table) {
        $this->database->update($table)
          ->fields([$definition->getKey('bundle') => $target_bundle])
          ->condition($definition->getKey('id'), $node->id())
          ->execute();
      }

      $storage->resetCache([$node->id()]);
      $converted = $storage->load($node->id());
      if (!$converted instanceof NodeInterface || $converted->bundle() !== $target_bundle) {
        throw new \RuntimeException('Drupal could not reload the node as the target page type.');
      }

      $this->setSharedFieldValues($converted, $values);
      $this->applyTargetDefaults($converted);
      $this->assertRequiredFields($converted);

      $converted->setNewRevision(TRUE);
      $converted->setRevisionLogMessage(sprintf(
        'Converted from %s to %s with Moody Page Convert.',
        $source_bundle,
        $target_bundle,
      ));
      $converted->setRevisionUserId($this->currentUser->id());
      $converted->setRevisionCreationTime($this->time->getRequestTime());
      $converted->save();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $storage->resetCache([$node->id()]);
      throw $exception;
    }

    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Captures shared configurable field values for each translation.
   */
  protected function getSharedFieldValues(NodeInterface $node, string $target_bundle): array {
    $source_definitions = $this->entityFieldManager
      ->getFieldDefinitions('node', $node->bundle());
    $target_definitions = $this->entityFieldManager
      ->getFieldDefinitions('node', $target_bundle);
    $shared_definitions = array_intersect_key($source_definitions, $target_definitions);
    $default_langcode = $node->language()->getId();
    $values = [];

    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $translation = $node->getTranslation($langcode);
      foreach ($shared_definitions as $field_name => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField() || $definition->isComputed()) {
          continue;
        }
        if (!$target_definitions[$field_name]->isTranslatable() && $langcode !== $default_langcode) {
          continue;
        }
        $values[$langcode][$field_name] = $translation->get($field_name)->getValue();
      }
    }

    return $values;
  }

  /**
   * Restores shared field values on the converted entity.
   */
  protected function setSharedFieldValues(NodeInterface $node, array $values): void {
    foreach ($values as $langcode => $field_values) {
      if (!$node->hasTranslation($langcode)) {
        continue;
      }
      $translation = $node->getTranslation($langcode);
      foreach ($field_values as $field_name => $value) {
        $translation->set($field_name, $value);
      }
    }
  }

  /**
   * Applies configured defaults to empty target fields.
   */
  protected function applyTargetDefaults(NodeInterface $node): void {
    $definitions = $this->entityFieldManager
      ->getFieldDefinitions('node', $node->bundle());
    $default_langcode = $node->language()->getId();

    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $translation = $node->getTranslation($langcode);
      foreach ($definitions as $field_name => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField() || $definition->isComputed()) {
          continue;
        }
        if (!$definition->isTranslatable() && $langcode !== $default_langcode) {
          continue;
        }
        if ($translation->get($field_name)->isEmpty() && $default = $definition->getDefaultValue($translation)) {
          $translation->set($field_name, $default);
        }
      }
    }
  }

  /**
   * Ensures conversion will not create a node missing required target fields.
   */
  protected function assertRequiredFields(NodeInterface $node): void {
    $missing = [];
    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($this->isMissingRequiredField($node, $field_name, $definition)) {
        $missing[] = (string) $definition->getLabel();
      }
    }

    if ($missing) {
      throw new \InvalidArgumentException(sprintf(
        'The target page type requires values for: %s.',
        implode(', ', $missing),
      ));
    }
  }

  /**
   * Determines whether a required field is empty in any translation.
   */
  protected function isMissingRequiredField(NodeInterface $node, string $field_name, FieldDefinitionInterface $definition): bool {
    if (!$definition->isRequired() || $definition->isComputed()) {
      return FALSE;
    }

    if (!$definition->isTranslatable()) {
      return $node->get($field_name)->isEmpty();
    }

    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      if ($node->getTranslation($langcode)->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
