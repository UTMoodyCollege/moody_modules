<?php

declare(strict_types=1);

namespace Drupal\moody_broken_links\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_broken_links\BrokenLinksManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Revises one scanned link occurrence.
 */
final class ReviseLinkForm extends FormBase {

  private int $resultId;

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
    return 'moody_broken_links_revise';
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
    $form['source'] = [
      '#type' => 'item',
      '#title' => $this->t('Source'),
      '#markup' => $this->t('@page — @field', [
        '@page' => $result['title'],
        '@field' => $result['source_label'],
      ]),
    ];
    $form['current'] = [
      '#type' => 'item',
      '#title' => $this->t('Current URL'),
      '#plain_text' => $result['href'],
    ];
    $form['replacement'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New URL'),
      '#required' => TRUE,
      '#maxlength' => 2048,
      '#description' => $this->t('Use an absolute HTTP(S) URL or a site-relative path beginning with /.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Revise link'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('moody_broken_links.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $form_state->setValue('replacement', $this->manager->validateReplacement((string) $form_state->getValue('replacement')));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('replacement', $exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->manager->remediate($this->resultId, 'revise', (string) $form_state->getValue('replacement'));
      $this->messenger()->addStatus($this->t('The link was revised in node @nid revision @revision.', [
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
