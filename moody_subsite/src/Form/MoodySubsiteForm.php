<?php

namespace Drupal\moody_subsite\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Moody subsite edit forms.
 *
 * @ingroup moody_subsite
 */
class MoodySubsiteForm extends ContentEntityForm {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->account = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var \Drupal\moody_subsite\Entity\MoodySubsite $entity */
    $form = parent::buildForm($form, $form_state);

    $menu_only = $this->getRequest()->query->get('section') === 'menu';
    $form['#attached']['library'][] = 'moody_subsite/subsite-edit-form';
    $form['subsite_edit_intro'] = [
      '#type' => 'container',
      '#weight' => -30,
      '#attributes' => ['class' => ['moody-subsite-edit-intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Edit subsite'),
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Update the visitor-facing settings for this subsite. Changes take effect when you save the form.') . '</p>',
      ],
    ];
    $form['subsite_sections'] = [
      '#type' => 'vertical_tabs',
      '#weight' => -20,
    ];

    $sections = [
      'basics' => [
        'title' => $this->t('Basics'),
        'description' => $this->t('Set the public name, homepage, and page-title behavior.'),
        'fields' => ['display_name', 'base_url', 'title_display_option'],
        'open' => !$menu_only,
      ],
      'navigation' => [
        'title' => $this->t('Navigation'),
        'description' => $this->t('Drag links to reorder them. Indent a link once to place it beneath the nearest top-level link.'),
        'fields' => ['subsite_nav'],
        'open' => $menu_only,
        'attributes' => ['id' => 'subsite-navigation'],
      ],
      'branding' => [
        'title' => $this->t('Branding'),
        'description' => $this->t('Configure the default hero treatment and subsite logo.'),
        'fields' => ['hero', 'subsite_home_hero', 'custom_logo'],
      ],
      'header_footer' => [
        'title' => $this->t('Header and footer'),
        'description' => $this->t('Manage information bars, social links, giving link, and footer text.'),
        'fields' => ['subsite_info_bars', 'subsite_social_links', 'hide_all_social_accounts', 'give_link', 'subsite_footer_text'],
      ],
      'administration' => [
        'title' => $this->t('Administration'),
        'description' => $this->t('Administrative settings used to organize and manage the subsite.'),
        'fields' => ['status', 'name', 'user_id', 'directory_structure'],
      ],
    ];

    if (isset($form['subsite_nav']['widget']['add_more'])) {
      $form['subsite_nav']['widget']['add_more']['#value'] = $this->t('Add navigation link');
      foreach ($form['subsite_nav']['widget'] as $delta => &$item) {
        if (is_int($delta) && isset($item['_actions']['delete'])) {
          $item['_actions']['delete']['#value'] = $this->t('Remove');
          $item['_actions']['delete']['#attributes']['aria-label'] = $this->t('Remove navigation link');
        }
      }
      unset($item);

      $related_pages = $this->relatedPages();
      if ($related_pages) {
        $form['#attached']['drupalSettings']['moodySubsite']['relatedPages'] = $related_pages;
        $form['subsite_nav']['related_pages'] = [
          '#type' => 'container',
          '#weight' => 101,
          '#attributes' => ['class' => ['moody-subsite-related-pages']],
          'button' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Add related pages'),
            '#attributes' => [
              'type' => 'button',
              'class' => ['button', 'button--small', 'js-moody-subsite-related-pages'],
            ],
          ],
          'status' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->formatPlural(
              count($related_pages),
              '1 related page found.',
              '@count related pages found.',
            ),
            '#attributes' => [
              'class' => ['js-moody-subsite-related-pages-status'],
              'role' => 'status',
              'aria-live' => 'polite',
            ],
          ],
        ];
      }
    }

    if ((int) $this->account->id() !== 1 && !in_array('moody_administrator', $this->account->getRoles(), TRUE)) {
      $form['directory_structure']['#access'] = FALSE;
    }

    $section_weight = -10;
    foreach ($sections as $section_name => $section) {
      $form[$section_name] = [
        '#type' => 'details',
        '#title' => $section['title'],
        '#description' => $section['description'],
        '#group' => 'subsite_sections',
        '#open' => $section['open'] ?? FALSE,
        '#weight' => $section_weight++,
        '#attributes' => $section['attributes'] ?? [],
      ];
      foreach ($section['fields'] as $field_name) {
        if (isset($form[$field_name])) {
          $form[$section_name][$field_name] = $form[$field_name];
          unset($form[$field_name]);
        }
      }
    }

    return $form;
  }

  /**
   * Returns pages associated with this subsite's directory terms.
   */
  protected function relatedPages() {
    $term_ids = array_column($this->entity->get('directory_structure')->getValue(), 'target_id');
    if (!$term_ids) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    try {
      $ids = $storage->getQuery()
        ->condition('field_moody_url_generator.target_id', $term_ids, 'IN')
        ->sort('title')
        ->accessCheck(TRUE)
        ->execute();
    }
    catch (\Exception) {
      return [];
    }

    $pages = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($node->access('view', $this->account)) {
        $pages[] = [
          'title' => (string) $node->label(),
          'url' => $node->toUrl()->toString(),
        ];
      }
    }
    return $pages;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addMessage($this->t('Created the %label Moody subsite.', [
          '%label' => $entity->label(),
        ]));
        break;

      default:
        $this->messenger()->addMessage($this->t('Saved the %label Moody subsite.', [
          '%label' => $entity->label(),
        ]));
    }
    $form_state->setRedirect('entity.moody_subsite.canonical', ['moody_subsite' => $entity->id()]);
  }

}
