<?php

namespace Drupal\moody_page_convert\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\moody_page_convert\PageConverter;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Moody page conversion form.
 */
class PageConvertForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected PageConverter $converter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('moody_page_convert.converter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moody_page_convert_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $types = $this->converter->getAvailableTypes();
    $type_options = [];
    foreach ($types as $id => $type) {
      $type_options[$id] = $type->label();
    }

    $form['description'] = [
      '#markup' => '<p>' . $this->t('The node ID, URL aliases, shared fields, and Layout Builder layout are preserved. A new revision records the conversion. Field data that does not exist on the target type is left untouched but is not shown on the converted page.') . '</p>',
    ];

    $form['node'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Page'),
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => array_combine(array_keys($types), array_keys($types)),
      ],
      '#required' => TRUE,
    ];

    $form['target_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Convert to'),
      '#options' => $type_options,
      '#empty_option' => $this->t('- Select a page type -'),
      '#required' => TRUE,
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that fields unique to the current page type will not appear on the converted page.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Convert page'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $node = $this->entityTypeManager
      ->getStorage('node')
      ->load($form_state->getValue('node'));

    if (!$node instanceof NodeInterface) {
      $form_state->setErrorByName('node', $this->t('Select a valid page.'));
      return;
    }

    if (!$node->access('update', $this->currentUser())) {
      $form_state->setErrorByName('node', $this->t('You do not have permission to update this page.'));
      return;
    }

    try {
      $this->converter->validateConversion($node, $form_state->getValue('target_type'));
      $form_state->set('moody_page_convert_node', $node);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('target_type', $exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node = $form_state->get('moody_page_convert_node');
    $source_type = $node->bundle();
    $target_type = $form_state->getValue('target_type');
    $types = $this->converter->getAvailableTypes();

    try {
      $converted = $this->converter->convert($node, $target_type);
    }
    catch (\Throwable $exception) {
      $this->getLogger('moody_page_convert')->error(
        'Page @id could not be converted: @message',
        ['@id' => $node->id(), '@message' => $exception->getMessage()],
      );
      $this->messenger()->addError($this->t('The page could not be converted: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return;
    }

    $this->messenger()->addStatus($this->t(
      'Converted %title from %source to %target. Review and save the page-specific fields below.',
      [
        '%title' => $converted->label(),
        '%source' => $types[$source_type]->label(),
        '%target' => $types[$target_type]->label(),
      ],
    ));
    $form_state->setRedirect('entity.node.edit_form', ['node' => $converted->id()]);
  }

}
