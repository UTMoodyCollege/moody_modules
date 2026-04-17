<?php

namespace Drupal\moody_media_image_helper\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Updates a selected media item in-place after cropping.
 */
class UpdateMediaSelectionCommand implements CommandInterface {

  /**
   * Constructs the command.
   */
  public function __construct(
    protected string $widgetRootId,
    protected ?string $selectionInputId,
    protected string $targetInputId,
    protected string $previewWrapperId,
    protected string $previewHtml,
    protected string $actionWrapperId,
    protected string $actionHtml,
    protected int $mediaId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'moodyMediaImageHelperUpdateSelection',
      'widgetRootId' => $this->widgetRootId,
      'selectionInputId' => $this->selectionInputId,
      'targetInputId' => $this->targetInputId,
      'previewWrapperId' => $this->previewWrapperId,
      'previewHtml' => $this->previewHtml,
      'actionWrapperId' => $this->actionWrapperId,
      'actionHtml' => $this->actionHtml,
      'mediaId' => $this->mediaId,
    ];
  }

}
