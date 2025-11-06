<?php

namespace Drupal\feature_page_redirects\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\redirect\RedirectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Event subscriber for feature page redirects.
 */
class FeaturePageRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The redirect repository.
   *
   * @var \Drupal\redirect\RedirectRepository
   */
  protected $redirectRepository;

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $pathAliasManager;

  /**
   * Constructs a new FeaturePageRedirectSubscriber.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\redirect\RedirectRepository $redirect_repository
   *   The redirect repository.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\path_alias\AliasManagerInterface $path_alias_manager
   *   The path alias manager.
   */
  public function __construct(
    RouteMatchInterface $route_match,
    RedirectRepository $redirect_repository,
    PathValidatorInterface $path_validator,
    EntityTypeManagerInterface $entity_type_manager,
    $path_alias_manager
  ) {
    $this->routeMatch = $route_match;
    $this->redirectRepository = $redirect_repository;
    $this->pathValidator = $path_validator;
    $this->entityTypeManager = $entity_type_manager;
    $this->pathAliasManager = $path_alias_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Subscribe to the controller event which happens after routing is resolved.
    $events[KernelEvents::CONTROLLER][] = ['onController', 0];
    return $events;
  }

  /**
   * Handles the controller event.
   *
   * @param \Symfony\Component\HttpKernel\Event\ControllerEvent $event
   *   The controller event.
   */
  public function onController(ControllerEvent $event) {
    // Only process the main request.
    if (!$event->isMainRequest()) {
      return;
    }

    // Get the current route name.
    $route_name = $this->routeMatch->getRouteName();

    // Only process canonical node view routes.
    if ($route_name !== 'entity.node.canonical') {
      return;
    }

    // Get the node from the route.
    $node = $this->routeMatch->getParameter('node');

    // Ensure we have a node object and it's a moody_feature_page.
    if (!$node || !is_object($node) || $node->bundle() !== 'moody_feature_page') {
      return;
    }

    // Get the current path for the node.
    $current_path = '/node/' . $node->id();

    // Try to get the alias if it exists.
    try {
      $alias = $this->pathAliasManager->getAliasByPath($current_path);
      if ($alias && $alias !== $current_path) {
        $current_path = $alias;
      }
    }
    catch (\Exception $e) {
      // If we can't get the alias, continue with the node path.
    }

    // Look for a redirect matching this path.
    $redirects = $this->redirectRepository->findBySourcePath(ltrim($current_path, '/'));

    if (!empty($redirects)) {
      // Get the first matching redirect.
      $redirect = reset($redirects);

      // Get the redirect destination.
      $destination = $redirect->getRedirect();

      if (!empty($destination['uri'])) {
        $url = $destination['uri'];

        // If it's an internal path, convert it to a full URL.
        if (strpos($url, 'internal:') === 0) {
          $validated_url = $this->pathValidator->getUrlIfValid(substr($url, 9));
          if ($validated_url) {
            $url = $validated_url->toString();
          }
        }
        elseif (strpos($url, 'entity:') === 0) {
          // Handle entity: URIs.
          try {
            // Parse the entity URI and generate the URL.
            $entity_prefix = 'entity:';
            $parts = explode('/', substr($destination['uri'], strlen($entity_prefix)));
            if (count($parts) >= 2) {
              $entity_type = $parts[0];
              $entity_id = $parts[1];
              $loaded_entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
              if ($loaded_entity && $loaded_entity->hasLinkTemplate('canonical')) {
                $url = $loaded_entity->toUrl()->toString();
              }
            }
          }
          catch (\Exception $e) {
            // If we can't generate the URL, don't redirect.
            return;
          }
        }

        // Get the status code (default to 301).
        $status_code = $redirect->getStatusCode() ?: 301;

        // Create and set the redirect response.
        $response = new RedirectResponse($url, $status_code);
        $event->setResponse($response);
      }
    }
  }

}
