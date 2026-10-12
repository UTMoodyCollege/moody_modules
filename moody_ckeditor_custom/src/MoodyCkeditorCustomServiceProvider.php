<?php
namespace Drupal\moody_ckeditor_custom;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/** Uses stable public paths when Linkit is installed. */
final class MoodyCkeditorCustomServiceProvider extends ServiceProviderBase {
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('linkit.suggestion_manager')) {
      $container->getDefinition('linkit.suggestion_manager')->setClass(\Drupal\moody_ckeditor_custom\AliasSuggestionManager::class);
    }
  }
}
