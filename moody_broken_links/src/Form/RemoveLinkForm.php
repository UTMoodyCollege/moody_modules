<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\moody_broken_links\BrokenLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms removal of one anchor while retaining its child markup.
 */
final class RemoveLinkForm extends ConfirmFormBase {

  private int $resultId;
  private array $result = [];

  public function __construct(private readonly BrokenLinksManager $manager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('moody_broken_links.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moody_broken_links_remove';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $result_id = NULL): array {
    $result = $this->manager->getResult((int) $result_id);
    if (!$result || (int) $result['remediated']) {
      throw new \InvalidArgumentException('This result is no longer available.');
    }
    $this->resultId = (int) $result_id;
    $this->result = $result;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Remove the link to @url from @page?', [
      '@url' => $this->result['href'] ?? '',
      '@page' => $this->result['title'] ?? '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('The anchor will be removed, but all linked text and nested markup will remain. A new Drupal revision will be created.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Remove link');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('moody_broken_links.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->manager->remediate($this->resultId, 'remove');
      $this->messenger()->addStatus($this->t('The link was removed in node @nid revision @revision. Its linked content was retained.', [
        '@nid' => $result['nid'],
        '@revision' => $result['revision_id'],
      ]));
      $form_state->setRedirect('moody_broken_links.dashboard');
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
