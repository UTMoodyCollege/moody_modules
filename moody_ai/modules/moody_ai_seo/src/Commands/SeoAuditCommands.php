<?php

declare(strict_types=1);

namespace Drupal\moody_ai_seo\Commands;

use Drupal\moody_ai_seo\ReadinessAuditor;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Drush command for public agent-readiness verification.
 */
final class SeoAuditCommands extends DrushCommands {

  public function __construct(
    private readonly ReadinessAuditor $auditor,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct();
  }

  /**
   * Audits public agent-readiness endpoints without exposing page content.
   *
   * @command moody-ai-seo:audit
   * @aliases mais:audit
   * @option base-url Absolute HTTPS URL to audit. Defaults to Drush --uri.
   * @usage drush --uri=https://example.edu moody-ai-seo:audit
   */
  public function audit(array $options = ['base-url' => NULL]): int {
    $request = $this->requestStack->getCurrentRequest();
    $base_url = trim((string) ($options['base-url'] ?: $request?->getSchemeAndHttpHost()));

    try {
      $result = $this->auditor->audit($base_url);
      $this->output()->writeln(json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      ));
      return $result['ready'] ? Command::SUCCESS : Command::FAILURE;
    }
    catch (\Throwable $exception) {
      $this->logger()->error('Agent-readiness audit failed: {message}', [
        'message' => $exception->getMessage(),
      ]);
      return Command::FAILURE;
    }
  }

}
