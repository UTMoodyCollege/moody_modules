<?php

namespace Drupal\moody_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the AI chat thread entity.
 *
 * @ContentEntityType(
 *   id = "moody_ai_chat_thread",
 *   label = @Translation("AI chat thread"),
 *   base_table = "moody_ai_chat_thread",
 *   handlers = {
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler"
 *   },
 *   admin_permission = "administer moody ai chat threads",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "uid" = "user_id"
 *   }
 * )
 */
class AIChatThread extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    $values += [
      'user_id' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * Gets decoded chat messages.
   *
   * @return array
   *   The stored message list.
   */
  public function getMessages() {
    $raw = (string) $this->get('messages_json')->value;
    if ($raw === '') {
      return [];
    }

    $messages = json_decode($raw, TRUE);
    return is_array($messages) ? $messages : [];
  }

  /**
   * Persists the full message list.
   *
   * @param array $messages
   *   The messages to store.
   *
   * @return $this
   */
  public function setMessages(array $messages) {
    $this->set('messages_json', json_encode(array_values($messages)));
    return $this;
  }

  /**
   * Appends a single chat message.
   *
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message text.
   * @param array $metadata
   *   Optional metadata.
   *
   * @return $this
   */
  public function addMessage($role, $content, array $metadata = []) {
    $messages = $this->getMessages();
    $messages[] = [
      'role' => $role,
      'content' => $content,
      'metadata' => $metadata,
      'created' => time(),
    ];
    return $this->setMessages($messages);
  }

  /**
   * Gets a pending action by ID or the latest pending action.
   *
   * @param string|null $action_id
   *   The action ID to search for, or NULL for the newest pending action.
   *
   * @return array|null
   *   The pending action metadata and its message index.
   */
  public function getPendingAction($action_id = NULL) {
    $messages = array_reverse($this->getMessages(), TRUE);

    foreach ($messages as $index => $message) {
      $pending_action = $message['metadata']['pending_action'] ?? NULL;
      if (!is_array($pending_action)) {
        continue;
      }

      if (($pending_action['status'] ?? 'pending') !== 'pending') {
        continue;
      }

      if ($action_id !== NULL && ($pending_action['id'] ?? NULL) !== $action_id) {
        continue;
      }

      return [
        'index' => $index,
        'message' => $message,
        'pending_action' => $pending_action,
      ];
    }

    return NULL;
  }

  /**
   * Resolves a pending action by mutating its metadata.
   *
   * @param string $action_id
   *   The action ID.
   * @param string $status
   *   The new status.
   * @param array $extra
   *   Extra metadata to merge into the pending action.
   *
   * @return bool
   *   TRUE when an action was updated.
   */
  public function resolvePendingAction($action_id, $status, array $extra = []) {
    $messages = $this->getMessages();

    foreach ($messages as $index => $message) {
      $pending_action = $message['metadata']['pending_action'] ?? NULL;
      if (!is_array($pending_action) || ($pending_action['id'] ?? NULL) !== $action_id) {
        continue;
      }

      $pending_action = $pending_action + ['status' => 'pending'];
      $pending_action['status'] = $status;
      $pending_action = array_replace_recursive($pending_action, $extra);
      $messages[$index]['metadata']['pending_action'] = $pending_action;
      $this->setMessages($messages);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setSettings([
        'max_length' => 255,
      ])
      ->setRequired(TRUE);

    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target entity type'))
      ->setSettings([
        'max_length' => 64,
      ])
      ->setRequired(TRUE);

    $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Target entity ID'))
      ->setRequired(TRUE);

    $fields['messages_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Messages JSON'))
      ->setDefaultValue('[]')
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
