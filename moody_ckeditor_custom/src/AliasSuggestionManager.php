<?php
namespace Drupal\moody_ckeditor_custom;

use Drupal\linkit\ProfileInterface;
use Drupal\linkit\SuggestionManager;
use Drupal\linkit\Suggestion\DescriptionSuggestion;
use Drupal\linkit\Suggestion\SuggestionCollection;

/** Keep page links bound to their public path, not the replaceable node. */
final class AliasSuggestionManager extends SuggestionManager {
  public function getSuggestions(ProfileInterface $linkitProfile, $search_string) {
    $result = new SuggestionCollection();
    foreach (parent::getSuggestions($linkitProfile, $search_string)->getSuggestions() as $suggestion) {
      $data = $suggestion->jsonSerialize();
      if (($data['entity_type_id'] ?? '') !== 'node') {
        $result->addSuggestion($suggestion);
        continue;
      }
      $path = $suggestion->getPath();
      if (preg_match('@^/node/\d+$@D', $path)) {
        $path = \Drupal::service('path_alias.manager')->getAliasByPath($path);
      }
      if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('@^/node/\d+(?:[/?#]|$)@', $path)) { continue; }
      // No UUID metadata: page launch may replace the node owning this alias.
      $replacement = (new DescriptionSuggestion())->setLabel($suggestion->getLabel())
        ->setGroup($suggestion->getGroup())->setDescription($suggestion->getDescription())->setPath($path);
      $result->addSuggestion($replacement);
    }
    return $result;
  }

  public function addUnscathedSuggestion(SuggestionCollection $suggestionCollection, $search_string) {
    $path = parse_url(trim($search_string), PHP_URL_PATH);
    if (is_string($path) && preg_match('@^/node/\d+(?:[/?#]|$)@', $path)) {
      return $suggestionCollection;
    }
    return parent::addUnscathedSuggestion($suggestionCollection, $search_string);
  }
}
