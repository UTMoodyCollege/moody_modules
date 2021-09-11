<?php

namespace Drupal\utexas_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Provides a 'twitter_widgets' migrate source.
 *
 * @MigrateSource(
 *  id = "twitter_widgets",
 *  source_module = "utexas_migrate"
 * )
 */
class TwitterWidgets extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('utexas_twitter_widgets', 'b')
      ->fields('b', array_keys($this->fields()));
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'id' => $this->t('Entity ID'),
      'name' => $this->t('Name'),
      'headline' => $this->t('Headline'),
      'type' => $this->t('Type'),
      'search' => $this->t('Search'),
      'account' => $this->t('Account'),
      'timeline_list' => $this->t('Timeline List'),
      'count' => $this->t('Count'),
      'view_all' => $this->t('View all'),
      'retweets' => $this->t('Retweets'),
      'replies' => $this->t('Replies'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => [
        'type' => 'integer',
        'alias' => 'b',
      ],
    ];
  }

}
