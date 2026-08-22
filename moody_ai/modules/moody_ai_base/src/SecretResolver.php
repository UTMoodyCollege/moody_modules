<?php

declare(strict_types=1);

namespace Drupal\moody_ai_base;

/**
 * Resolves AI credentials without storing them in Drupal configuration.
 */
final class SecretResolver {

  /**
   * Returns a Pantheon runtime secret or its local environment equivalent.
   */
  public function get(string $secret_name): ?string {
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $secret_name)) {
      return NULL;
    }

    if (function_exists('pantheon_get_secret')) {
      try {
        $value = pantheon_get_secret($secret_name);
        if (is_string($value) && trim($value) !== '') {
          return trim($value);
        }
      }
      catch (\Throwable) {
        // A local environment or an unconfigured Pantheon environment may not
        // provide the requested secret. Fall through to the environment value.
      }
    }

    $environment_name = strtoupper($secret_name);
    $value = getenv($environment_name);
    return is_string($value) && trim($value) !== '' ? trim($value) : NULL;
  }

}
