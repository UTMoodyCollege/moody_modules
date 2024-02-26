<?php

namespace Drupal\faculty_bio_content_type\Feeds\Target;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\feeds\FieldTargetDefinition;
use Drupal\feeds\Exception\EmptyFeedException;
use Drupal\feeds\Exception\TargetValidationException;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;
use Drupal\Component\Utility\Html;

/**
 * @FeedsTarget(
 *   id = "faculty_bio_subordinate_target",
 *   field_types = {"faculty_bio_subordinate"},
 *   title = @Translation("Faculty Bio Subordinate")
 * )
 */
class FacultyBioSubordinateTarget extends FieldTargetBase
{

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition)
  {
    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('data');
  }
  /**
   * {@inheritdoc}
   */
  protected function prepareValues(array $values)
  {
    $return = [];
    foreach ($values as $delta => $value) {
      try {
        if (isset($value) && !empty($value['data'])) {
          $massaged_data = [];
          $assistantsData = explode(':', $value['data']);
    
          foreach ($assistantsData as $assistantDelta => $assistantData) {
            preg_match_all('/\[(.*?)\]/', $assistantData, $matches);
            $assistantValues = [];
    
            foreach ($matches[1] as $match) {
              parse_str(str_replace('&', '&', Html::decodeEntities($match)), $parsed);
    
              // Set each property of the field
              $massaged_data[] = [
                'name' => $parsed['name'] ?? '',
                'title' => $parsed['title'] ?? '',
                'email' => $parsed['email'] ?? ''
              ];
            }
          }
        }
      } catch (EmptyFeedException $e) {
        // Continue to the next value.
      } catch (TargetValidationException $e) {
        \Drupal::messenger()->addError($e->getMessage());
      }
    }

    return $massaged_data;
  }
}
