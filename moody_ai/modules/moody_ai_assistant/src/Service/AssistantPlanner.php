<?php

namespace Drupal\moody_ai_assistant\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\moody_ai_base\AiGenerationService;

/**
 * Translates assistant workflows into provider-neutral base service calls.
 */
class AssistantPlanner {

  /**
   * Keeps one Assistant request useful without allowing unbounded page builds.
   */
  const MAX_STRUCTURED_BLOCKS = 12;
  protected $generator;
  protected $logger;
  protected $usageEvents = [];
  protected $selectedModel;

  public function __construct(
    AiGenerationService $generator,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->generator = $generator;
    $this->logger = $logger_factory->get('moody_ai_assistant');
  }

  /**
   * Applies one validated provider/model selection to this request.
   */
  public function selectProviderAndModel(?string $provider = NULL, ?string $model = NULL) {
    $provider = $provider ?: 'openai';
    $model = $model ?: $this->generator->defaultModel();
    if (!isset($this->generator->providerOptions()[$provider]) || !isset($this->generator->modelOptions()[$model])) {
      throw new \InvalidArgumentException('The selected AI provider or model is not available.');
    }
    $this->selectedModel = $model;
  }

  public function identifyBlockPlan($prompt, array $blockData, ?callable $stream_callback = NULL) {
    $messages = [
      [
        'role' => 'system',
        'content' => $this->getIdentifierPrompt($blockData) . $this->getConfiguredContextPrompt(),
      ],
      [
        'role' => 'user',
        'content' => $prompt,
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.2, $stream_callback);
    $plan = $this->parseJsonMessage($response);

    if (empty($plan['selected_block_type'])) {
      throw new \Exception('Identifier agent did not return a selected block type.');
    }
    $plan['selected_block_type'] = $this->resolveAllowedBlockType(
      (string) $plan['selected_block_type'],
      array_keys($blockData['content_blocks'] ?? [])
    );

    $plan = $this->mergeInferredAssetRequirements($plan, $prompt, $blockData);

    return $plan;
  }

  /**
   * Plans whether a conversational request should create or edit a block.
   */
  public function planConversationAction($message, array $page_context, array $recent_messages = [], ?callable $stream_callback = NULL) {
    $messages = [
      [
        'role' => 'system',
        'content' => "You are a Drupal Layout Builder assistant planner.\n\n"
          . "Review the user's message, recent conversation context, and the existing page components.\n"
          . "Decide whether the user most likely wants to create a new block or edit an existing inline block already on the page.\n\n"
          . "Return valid JSON only with this structure:\n"
          . "{\n"
          . "  \"action\": \"create|edit\",\n"
          . "  \"reasoning\": \"short explanation\",\n"
          . "  \"target_component_uuid\": \"component uuid or empty string\",\n"
          . "  \"target_block_type\": \"block machine name or empty string\",\n"
          . "  \"preview_title\": \"short preview title\",\n"
          . "  \"preview_summary\": \"one paragraph summary\",\n"
          . "  \"changes\": [\"short change bullet\", \"short change bullet\"]\n"
          . "}\n\n"
          . "Rules:\n"
          . "- Choose \"edit\" only when the user is clearly referring to an existing page block by conversational clues, prior context, or labels on the page.\n"
          . "- Only choose a target_component_uuid that exists in existing_components and represents an inline block with block_type data.\n"
          . "- If inspected block contents are provided in block_tools.inspected_blocks, use those exact contents to select the best target and summarize the planned edit.\n"
          . "- If there is ambiguity, prefer create.\n"
          . "- Never wrap JSON in markdown fences.\n\n"
          . "Page context JSON:\n" . json_encode($page_context, JSON_PRETTY_PRINT)
          . "\n\nRecent messages JSON:\n" . json_encode($recent_messages, JSON_PRETTY_PRINT),
      ],
      [
        'role' => 'user',
        'content' => $message,
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.2, $stream_callback);
    $plan = $this->parseJsonMessage($response);
    $plan['action'] = ($plan['action'] ?? 'create') === 'edit' ? 'edit' : 'create';
    $plan['changes'] = !empty($plan['changes']) && is_array($plan['changes']) ? array_values($plan['changes']) : [];
    return $plan;
  }

  /**
   * Selects existing page blocks whose contents should be inspected.
   */
  public function selectRelevantExistingBlocks($message, array $editable_blocks, array $recent_messages = [], ?callable $stream_callback = NULL) {
    if (!$editable_blocks) {
      return [];
    }

    $messages = [
      [
        'role' => 'system',
        'content' => "You are a Drupal Layout Builder block inspection planner.\n\n"
          . "The user may be asking to edit an existing block. You receive only a compact index of editable page blocks.\n"
          . "Choose the smallest useful set of component UUIDs whose full contents should be inspected before target selection.\n\n"
          . "Return valid JSON only with this structure:\n"
          . "{\n"
          . "  \"component_uuids\": [\"uuid\", \"uuid\"],\n"
          . "  \"reasoning\": \"short explanation\"\n"
          . "}\n\n"
          . "Rules:\n"
          . "- Return at most 3 component UUIDs.\n"
          . "- Prefer blocks whose label, type, placement, or recent conversation clues match the user's requested edit.\n"
          . "- If the request clearly targets all matching blocks, choose the most relevant examples needed to understand the content pattern.\n"
          . "- If the request is not about editing existing blocks, return an empty component_uuids array.\n"
          . "- Never invent UUIDs; use only component_uuid values from editable_blocks.\n"
          . "- Never wrap JSON in markdown fences.\n\n"
          . "Editable blocks JSON:\n" . json_encode($editable_blocks, JSON_PRETTY_PRINT)
          . "\n\nRecent messages JSON:\n" . json_encode($recent_messages, JSON_PRETTY_PRINT),
      ],
      [
        'role' => 'user',
        'content' => $message,
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.1, $stream_callback);
    $selection = $this->parseJsonMessage($response);
    $allowed = array_fill_keys(array_filter(array_map(function (array $block) {
      return (string) ($block['component_uuid'] ?? '');
    }, $editable_blocks)), TRUE);

    $component_uuids = [];
    foreach (($selection['component_uuids'] ?? []) as $uuid) {
      $uuid = (string) $uuid;
      if ($uuid !== '' && isset($allowed[$uuid])) {
        $component_uuids[] = $uuid;
      }
    }

    return array_slice(array_values(array_unique($component_uuids)), 0, 3);
  }

  /**
   * Plans the top-level assistant action for a user request.
   */
  public function planTopLevelAction($message, array $page_context = [], array $page_options = [], ?callable $stream_callback = NULL) {
    $build_on_current_page = $this->shouldBuildOnCurrentPage($message, $page_context);
    $messages = [
      [
        'role' => 'system',
        'content' => "You are a Drupal site-building assistant planner.\n\n"
          . "Decide whether the user's request is primarily about:\n"
          . "- working with page blocks on the current page\n"
          . "- creating a redirect\n"
          . "- creating a brand new page\n"
          . "- changing or explaining publication status\n"
          . "- finding a Drupal administration page for a site-management task\n\n"
          . "Return valid JSON only with this structure:\n"
          . "{\n"
          . "  \"action\": \"block|redirect|create_page|guide\",\n"
          . "  \"reasoning\": \"short explanation\",\n"
          . "  \"redirect\": {\n"
          . "    \"source\": \"relative source path beginning with / or empty string\",\n"
          . "    \"destination\": \"destination path or URL or empty string\",\n"
          . "    \"status_code\": 301,\n"
          . "    \"summary\": \"one sentence summary\"\n"
          . "  },\n"
          . "  \"page\": {\n"
          . "    \"summary\": \"one sentence summary\",\n"
          . "    \"suggested_bundles\": [\"machine_name\", \"machine_name\"]\n"
          . "  },\n"
          . "  \"guide\": {\n"
          . "    \"topic\": \"menus|redirects|content|publishing|media|users|taxonomy|configuration\",\n"
          . "    \"summary\": \"one sentence explaining where the user can continue\"\n"
          . "  }\n"
          . "}\n\n"
          . "Rules:\n"
          . "- Choose \"redirect\" only when the user asks to create a concrete redirect and provides both the source and destination.\n"
          . "- When page_context has an entity_id, page-building requests apply to that existing current page by default.\n"
          . "- Phrases such as \"make a page\", \"build a page\", or \"create a page\" do not by themselves request a new page; use \"block\" so the current page is composed with blocks.\n"
          . "- Choose \"create_page\" only when the user explicitly distinguishes a new target with language such as new, another, separate, additional, fresh, or different page/node.\n"
          . "- Choose \"guide\" with topic=publishing when the user asks to publish, unpublish, archive, draft, or explain the publication state of the current content.\n"
          . "- Choose \"guide\" for how-to or navigation requests about menus, redirects without enough details to create one, content, media, users, taxonomy, or configuration.\n"
          . "- user_access is a Drupal-calculated snapshot for this request. Never claim or plan access beyond it. False values and omitted content types are unavailable.\n"
          . "- Choose redirect only when user_access.site_tools.create_redirect is true. Otherwise use guide with topic=redirects and explain that the account cannot create redirects.\n"
          . "- For publishing guidance, use only user_access.current_content.publication and its available_transitions. Do not invent a transition or imply that a role grants broader access.\n"
          . "- Content type edit_scope is only a broad permission scope; individual content items always require their own access check.\n"
          . "- Otherwise choose \"block\".\n"
          . "- For redirects, use 301 unless the user clearly asks for a temporary redirect.\n"
          . "- available_page_types is the complete page recommendation allowlist. Never recommend or mention another page type.\n"
          . "- For create_page, suggested_bundles must come from available_page_types.\n"
          . "- Never wrap JSON in markdown fences.\n\n"
          . "Page context JSON:\n" . json_encode($page_context, JSON_PRETTY_PRINT)
          . "\n\nAvailable page types JSON:\n" . json_encode($page_options, JSON_PRETTY_PRINT),
      ],
      [
        'role' => 'user',
        'content' => $message,
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.1, $stream_callback);
    $plan = $this->parseJsonMessage($response);
    $plan['action'] = in_array(($plan['action'] ?? 'block'), ['block', 'redirect', 'create_page', 'guide'], TRUE) ? $plan['action'] : 'block';
    if ($plan['action'] === 'create_page' && $build_on_current_page) {
      $plan['action'] = 'block';
      $plan['reasoning'] = 'Build on the current page because the user did not explicitly request a separate new page.';
    }
    $plan['redirect'] = !empty($plan['redirect']) && is_array($plan['redirect']) ? $plan['redirect'] : [];
    $plan['page'] = !empty($plan['page']) && is_array($plan['page']) ? $plan['page'] : [];
    $plan['page']['suggested_bundles'] = !empty($plan['page']['suggested_bundles']) && is_array($plan['page']['suggested_bundles']) ? array_values($plan['page']['suggested_bundles']) : [];
    $plan['guide'] = !empty($plan['guide']) && is_array($plan['guide']) ? $plan['guide'] : [];

    if ($plan['action'] === 'redirect' && isset($page_context['user_access']['site_tools']['create_redirect']) && empty($page_context['user_access']['site_tools']['create_redirect'])) {
      $plan['action'] = 'guide';
      $plan['redirect'] = [];
      $plan['guide'] = [
        'topic' => 'redirects',
        'summary' => 'Your current Drupal access does not allow you to create redirects on this site.',
      ];
    }

    return $plan;
  }

  /**
   * Plans whether to build one block, many blocks, or a page blueprint.
   */
  public function planStructuredBuild($message, array $page_context, array $recent_messages, array $page_options, array $blockData, ?callable $stream_callback = NULL) {
    $build_on_current_page = $this->shouldBuildOnCurrentPage($message, $page_context);
    $available_block_types = $this->getStructuredPlanBlockTypes($message, $page_context, $blockData);
    $messages = [
      [
        'role' => 'system',
        'content' => "You are a Drupal page-composition planner.\n\n"
          . "Decide whether the user's request is best fulfilled by a single block, multiple coordinated blocks, or a blueprint for a new page.\n\n"
          . "Return valid JSON only with this structure:\n"
          . "{\n"
          . "  \"mode\": \"single|multi|page_blueprint\",\n"
          . "  \"summary\": \"short summary\",\n"
          . "  \"blocks\": [\n"
          . "    {\n"
          . "      \"label\": \"short component label\",\n"
          . "      \"goal\": \"what this component should do\",\n"
          . "      \"placement_hint\": \"short human placement note\",\n"
          . "      \"section_delta\": 0,\n"
          . "      \"region\": \"existing region machine name or empty string\",\n"
          . "      \"selected_block_type\": \"machine_name or empty string\",\n"
          . "      \"reasoning\": \"short explanation\"\n"
          . "    }\n"
          . "  ],\n"
          . "  \"page_blueprint\": {\n"
          . "    \"summary\": \"short summary\",\n"
          . "    \"suggested_bundles\": [\"machine_name\", \"machine_name\"],\n"
          . "    \"sections\": [\n"
          . "      {\n"
          . "        \"label\": \"section label\",\n"
          . "        \"purpose\": \"what belongs in this section\",\n"
          . "        \"suggested_block_type\": \"machine_name or empty string\"\n"
          . "      }\n"
          . "    ]\n"
          . "  }\n"
          . "}\n\n"
          . "Rules:\n"
          . "- Use mode=multi when the user clearly wants several components or an entire page section flow on the current page.\n"
          . "- Requests to make, build, or create a page use mode=multi when page_context identifies an existing page.\n"
          . "- Use mode=page_blueprint only when the user explicitly requests a new, another, separate, additional, fresh, or different page.\n"
          . "- Use mode=single when one block is enough.\n"
          . "- A multi-block plan may contain at most " . static::MAX_STRUCTURED_BLOCKS . " blocks. Prioritize a complete, coherent page flow within that limit.\n"
          . "- Do not use a dynamic profile listing, feed, or other record-driven block unless the user explicitly selected that block type and supplied the existing records or filters it needs. Use a generated-content block instead.\n"
          . "- Block type values must come from available_block_types when present.\n"
          . "- Place each block in an existing page_context section and region. section_delta is zero-based; never invent a section or region. Use section 0 and an empty region when unsure.\n"
          . "- available_page_types is the complete page recommendation allowlist. Never recommend or mention another page type.\n"
          . "- Page type values must come from available_page_types.\n"
          . "- Never wrap JSON in markdown fences.\n\n"
          . "Page context JSON:\n" . json_encode($page_context, JSON_PRETTY_PRINT)
          . "\n\nRecent messages JSON:\n" . json_encode($recent_messages, JSON_PRETTY_PRINT)
          . "\n\nAvailable page types JSON:\n" . json_encode($page_options, JSON_PRETTY_PRINT)
          . "\n\nAvailable block types JSON:\n" . json_encode($available_block_types, JSON_PRETTY_PRINT)
          . $this->getConfiguredContextPrompt(),
      ],
      [
        'role' => 'user',
        'content' => $message,
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.2, $stream_callback);
    $plan = $this->parseJsonMessage($response);
    $plan['mode'] = in_array(($plan['mode'] ?? 'single'), ['single', 'multi', 'page_blueprint'], TRUE) ? $plan['mode'] : 'single';
    $plan['blocks'] = !empty($plan['blocks']) && is_array($plan['blocks'])
      ? array_values(array_filter($plan['blocks'], 'is_array'))
      : [];
    $plan['blocks'] = array_slice($plan['blocks'], 0, static::MAX_STRUCTURED_BLOCKS);
    $plan['page_blueprint'] = !empty($plan['page_blueprint']) && is_array($plan['page_blueprint']) ? $plan['page_blueprint'] : [];
    $plan['page_blueprint']['sections'] = !empty($plan['page_blueprint']['sections']) && is_array($plan['page_blueprint']['sections']) ? array_values($plan['page_blueprint']['sections']) : [];
    $plan['page_blueprint']['suggested_bundles'] = !empty($plan['page_blueprint']['suggested_bundles']) && is_array($plan['page_blueprint']['suggested_bundles']) ? array_values($plan['page_blueprint']['suggested_bundles']) : [];

    if ($plan['mode'] === 'page_blueprint' && $build_on_current_page) {
      $plan['mode'] = 'multi';
      if (!$plan['blocks']) {
        $section_count = count($plan['page_blueprint']['sections']);
        foreach ($plan['page_blueprint']['sections'] as $index => $section) {
          $plan['blocks'][] = [
            'label' => $section['label'] ?? ('Component ' . ($index + 1)),
            'goal' => $section['purpose'] ?? '',
            'placement_hint' => $index === 0 ? 'top' : ($index === $section_count - 1 ? 'bottom' : 'middle'),
            'selected_block_type' => $section['suggested_block_type'] ?? '',
            'reasoning' => 'Use this planned section on the current page.',
          ];
        }
      }
    }

    foreach ($plan['blocks'] as &$block) {
      $selected_type = (string) ($block['selected_block_type'] ?? '');
      if ($selected_type !== '') {
        $block['selected_block_type'] = $this->resolveAllowedBlockType($selected_type, $available_block_types);
      }
    }
    unset($block);

    return $plan;
  }

  /**
   * Resolves a model-selected block type to an installed, allowed bundle.
   */
  protected function resolveAllowedBlockType($selected_type, array $allowed_types) {
    $selected_type = trim((string) $selected_type);
    $allowed = array_fill_keys($allowed_types, TRUE);
    if (isset($allowed[$selected_type])) {
      return $selected_type;
    }

    $suffix = preg_replace('/^(?:moody|utexas)_/', '', $selected_type);
    $matches = array_values(array_filter($allowed_types, static function ($candidate) use ($suffix) {
      return preg_replace('/^(?:moody|utexas)_/', '', (string) $candidate) === $suffix;
    }));
    if (count($matches) === 1) {
      return $matches[0];
    }

    return isset($allowed['basic']) ? 'basic' : (string) reset($allowed_types);
  }

  /**
   * Removes block types that cannot produce useful standalone generated data.
   */
  protected function getStructuredPlanBlockTypes($message, array $page_context, array $blockData) {
    $types = array_keys($blockData['content_blocks'] ?? []);
    $explicit_types = [];
    foreach ($page_context['selected_block_references'] ?? [] as $reference) {
      foreach (['block_type', 'reference_id', 'plugin_id'] as $key) {
        if (!empty($reference[$key])) {
          $explicit_types[] = str_replace('inline_block:', '', (string) $reference[$key]);
        }
      }
    }

    $dynamic_types = ['feed_block', 'utprof_profile_listing'];
    $text_only = preg_match('/\b(?:text[- ]only|no (?:images?|imagery|photos?|media)|without (?:any )?(?:images?|imagery|photos?|media))\b/i', (string) $message);
    return array_values(array_filter($types, function ($type) use ($message, $blockData, $explicit_types, $dynamic_types, $text_only) {
      $explicit = in_array($type, $explicit_types, TRUE) || str_contains((string) $message, $type);
      if (in_array($type, $dynamic_types, TRUE) && !$explicit) {
        return FALSE;
      }
      if ($text_only && !$explicit) {
        foreach ($blockData['content_blocks'][$type]['fields'] ?? [] as $field) {
          if (
            !empty($field['required'])
            && (($field['target_type'] ?? '') === 'media' || isset($field['properties']['image']) || isset($field['properties']['media']))
          ) {
            return FALSE;
          }
        }
      }
      return TRUE;
    }));
  }

  /**
   * Determines whether ambiguous page-building language targets this page.
   */
  protected function shouldBuildOnCurrentPage($message, array $page_context) {
    if (empty($page_context['entity_id'])) {
      return FALSE;
    }

    return !preg_match(
      '/\b(?:new|another|separate|additional|fresh|different)\s+(?:(?!(?:block|component|section|content|copy|image|layout|on|to|for|in|of|within|the|this|current)\b)[\p{L}\p{N}_-]+\s+){0,5}(?:page|node|content\s+item)\b|\b(?:node|content)\/add\b/iu',
      trim((string) $message)
    );
  }

  public function createBlockPayload($prompt, array $plan, array $blockData, array $context = [], ?callable $stream_callback = NULL) {
    $messages = [
      [
        'role' => 'system',
        'content' => $this->getCreatorPrompt($plan, $blockData, $context),
      ],
      [
        'role' => 'user',
        'content' => $this->buildCreatorInput($prompt, $context),
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.6, $stream_callback);
    $payload = $this->parseJsonMessage($response);
    $selected_type = (string) ($plan['selected_block_type'] ?? '');

    if (!empty($context['edit_mode'])) {
      if (!isset($payload['values']) || !is_array($payload['values'])) {
        throw new \Exception('Editor agent did not return block values.');
      }

      $allowed_fields = array_fill_keys(array_filter(
        array_keys($blockData['content_blocks'][$selected_type]['fields'] ?? []),
        static fn ($field_name): bool => $field_name === 'body' || str_starts_with($field_name, 'field_')
      ), TRUE);
      $field_info = [];
      foreach ($payload['values'] as $field_name => $field_value) {
        if (isset($allowed_fields[$field_name])) {
          $field_info[$field_name] = is_array($field_value) ? $field_value : ['value' => $field_value];
        }
      }

      $payload = [
        'instructions' => [[
          'block_type' => $selected_type,
          'description' => trim((string) ($payload['description'] ?? 'Update the selected block.')),
          'field_info' => $field_info,
        ]],
      ];
    }

    if (empty($payload['instructions']) || !is_array($payload['instructions'])) {
      throw new \Exception('Creator agent did not return block instructions.');
    }

    $plan = $this->mergeInferredAssetRequirements($plan, $prompt, $blockData);
    $payload = $this->normalizeAssetRequirements($payload, $plan, $blockData, $context);
    if ($selected_type !== '' && isset($blockData['content_blocks'][$selected_type])) {
      foreach ($payload['instructions'] as &$instruction) {
        if (is_array($instruction)) {
          $instruction['block_type'] = $selected_type;
        }
      }
      unset($instruction);
    }
    $payload['plan'] = $plan;
    $payload['prefer_ai_images'] = !empty($context['prefer_ai_images']);
    return $payload;
  }

  /**
   * Generates accessibility metadata for an uploaded image.
   *
   * @param string $binary
   *   The raw image bytes.
   * @param string $mime_type
   *   The image mime type.
   * @param string $filename
   *   The original filename.
   *
   * @return array
   *   Generated alt text and title.
   */
  public function generateImageMetadata($binary, $mime_type, $filename = '') {
    $messages = [
      [
        'role' => 'system',
        'content' => "You write concise, accessibility-first metadata for uploaded website images.\n\n"
          . "Return valid JSON only with this structure:\n"
          . "{\n"
          . "  \"alt\": \"short descriptive alt text\",\n"
          . "  \"title\": \"short asset title\"\n"
          . "}\n\n"
          . "Rules:\n"
          . "- Alt text should usually be under 160 characters.\n"
          . "- Describe the key visual content plainly and specifically.\n"
          . "- Avoid starting with 'image of' or 'photo of' unless needed for clarity.\n"
          . "- Include only the most important visible text from the image when relevant.\n"
          . "- Make the title short and useful in a media library.\n"
          . "- Never wrap JSON in markdown fences.",
      ],
      [
        'role' => 'user',
        'content' => [
          [
            'type' => 'text',
            'text' => 'Generate alt text and a short title for this uploaded image. Filename: ' . $filename,
          ],
          [
            'type' => 'image_url',
            'image_url' => [
              'url' => 'data:' . $mime_type . ';base64,' . base64_encode($binary),
            ],
          ],
        ],
      ],
    ];

    $response = $this->requestChatCompletion($messages, 0.2);
    $metadata = $this->parseJsonMessage($response);

    return [
      'alt' => trim((string) ($metadata['alt'] ?? '')),
      'title' => trim((string) ($metadata['title'] ?? '')),
    ];
  }

  public function generateImage($prompt) {
    return $this->generator->generateImage((string) $prompt);
  }

  /**
   * Clears tracked usage events for the current request.
   */
  public function resetUsageEvents() {
    $this->usageEvents = [];
  }

  /**
   * Returns and clears tracked usage events.
   */
  public function consumeUsageEvents() {
    $events = $this->usageEvents;
    $this->usageEvents = [];
    return $events;
  }

  /**
   * Sums total tokens from tracked usage events.
   */
  public function sumUsageTokens(array $events) {
    $total = 0;
    foreach ($events as $event) {
      $total += (int) ($event['total_tokens'] ?? 0);
    }
    return $total;
  }

  protected function getIdentifierPrompt(array $blockData) {
    return "You are a Drupal block type identifier. You must select the single best custom block type for the user's request using only the provided block schema JSON.\n\n"
      . "Return valid JSON only with this structure:\n"
      . "{\n"
      . "  \"selected_block_type\": \"machine_name\",\n"
      . "  \"confidence\": \"high|medium|low\",\n"
      . "  \"reasoning\": \"short explanation\",\n"
      . "  \"essential_fields\": [\n"
      . "    {\n"
      . "      \"field_name\": \"body\",\n"
      . "      \"source\": \"text|image|link|boolean|list|reference\",\n"
      . "      \"why\": \"why this field matters\"\n"
      . "    }\n"
      . "  ],\n"
      . "  \"asset_requirements\": [\n"
      . "    {\n"
      . "      \"field_name\": \"field_feature_highlight_image\",\n"
      . "      \"target_id\": 123,\n"
      . "      \"asset_type\": \"image\",\n"
      . "      \"image_url\": \"https://example.com/image.jpg\",\n"
      . "      \"prompt\": \"image generation prompt\",\n"
      . "      \"alt\": \"descriptive alt text\",\n"
      . "      \"title\": \"asset title\"\n"
      . "    }\n"
      . "  ],\n"
      . "  \"notes\": [\"optional implementation note\"]\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Choose exactly one block type.\n"
      . "- Use only fields that exist on the chosen block type.\n"
      . "- Prefer the simplest valid block type that fulfills the request.\n"
      . "- If prefer_ai_images is true in the prompt/context, strongly prefer generating a new AI image prompt for the relevant field instead of selecting existing media, unless the user explicitly supplied a direct image URL or clearly asked to use a specific uploaded asset.\n"
      . "- If uploaded_assets appear in the prompt/context with media target_id values, prefer reusing those assets only when prefer_ai_images is false or the user explicitly asked to use those uploaded assets.\n"
      . "- If the chosen block type has any image-capable field, including custom compound fields with properties like image or media, and the prompt mentions an image, picture, photo, illustration, graphic, or similar visual, add an image asset requirement.\n"
      . "- If the user provides a direct image URL, include it in asset_requirements as image_url and prefer using that source instead of generating a new image.\n"
      . "- Never wrap the JSON in markdown fences.\n\n"
      . "Available block types JSON:\n"
      . json_encode($this->buildIdentifierCatalog($blockData), JSON_PRETTY_PRINT);
  }

  /**
   * Builds the smallest schema needed to select a block type.
   */
  protected function buildIdentifierCatalog(array $blockData) {
    $catalog = [];
    foreach ($blockData['content_blocks'] ?? [] as $block_type => $definition) {
      $fields = [];
      foreach ($definition['fields'] ?? [] as $field_name => $field) {
        if ($field_name !== 'body' && !str_starts_with($field_name, 'field_')) {
          continue;
        }
        $fields[$field_name] = array_filter([
          'label' => (string) ($field['label'] ?? $field_name),
          'type' => (string) ($field['type'] ?? ''),
          'required' => !empty($field['required']),
          'target_type' => (string) ($field['target_type'] ?? ''),
          'properties' => array_keys($field['properties'] ?? []),
        ], static fn ($value): bool => $value !== '' && $value !== [] && $value !== FALSE);
      }
      $catalog[$block_type] = [
        'label' => (string) ($definition['label'] ?? $block_type),
        'fields' => $fields,
      ];
    }
    return $catalog;
  }

  protected function getCreatorPrompt(array $plan, array $blockData, array $context) {
    $selected_block_type = $plan['selected_block_type'] ?? '';
    $selected_definition = $blockData['content_blocks'][$selected_block_type] ?? ($blockData['selected_block']['definition'] ?? []);
    if (!empty($context['edit_mode'])) {
      return $this->getEditorPrompt($selected_block_type, $selected_definition, $context);
    }

    $custom_field_guidance = $this->getCustomFieldGuidance($selected_definition);
    $compact_schema = $this->buildCompactFieldSchema($selected_definition);

    return "You are a Drupal block creator agent. You receive the user's request, the identifier agent's plan, and the exact schema for the selected block type.\n\n"
      . "Return valid JSON only with this structure:\n"
      . "{\n"
      . "  \"instructions\": [\n"
      . "    {\n"
      . "      \"block_type\": \"{$selected_block_type}\",\n"
      . "      \"description\": \"short description\",\n"
      . "      \"field_info\": {\n"
      . "        \"field_name\": {\n"
      . "          \"type\": \"field_type\",\n"
      . "          \"value\": \"field value or null\",\n"
      . "          \"target_id\": 123,\n"
      . "          \"format\": \"flex_html or full_html when needed\",\n"
      . "          \"asset_type\": \"image when an image must be generated\",\n"
      . "          \"image_prompt\": \"prompt for generated image\",\n"
      . "          \"alt\": \"required alt text for images\",\n"
      . "          \"title\": \"optional image title\"\n"
      . "        }\n"
      . "      }\n"
      . "    }\n"
      . "  ]\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Use only fields defined on the selected block type.\n"
      . "- Fill every required field with realistic content.\n"
      . "- For text-rich fields, produce clean HTML when appropriate.\n"
      . "- If prefer_ai_images is true in the revision context, strongly prefer returning an image_prompt, alt, and title for a newly generated image instead of reusing an existing media target_id, unless the user explicitly supplied a direct image URL or clearly asked to use a specific uploaded asset.\n"
      . "- If uploaded_assets are available and one fits the request, prefer returning target_id for that media item only when prefer_ai_images is false or the user explicitly asked to use the uploaded asset.\n"
      . "- For entity_reference fields targeting media, never return a null-only placeholder. Provide asset_type=image plus image_prompt, alt, and title whenever an image is needed, and always do so for required media fields.\n"
      . "- If the user supplied an explicit image URL, carry it through as image_url on the chosen image/media field or subproperty instead of inventing a new source.\n"
      . "- For compound custom fields, return structured arrays/objects that match the listed subproperties instead of a plain string, and image/media subproperties may use either a numeric media ID, target_id, or an asset instruction object.\n"
      . "- For edit requests, use the Existing instructions JSON as the current source of truth. If the user asks to add another item/delta, return the full updated field value containing the existing items plus the new item. If the user asks to replace/rewrite content, return the full replacement field value.\n"
      . "- Map user intent to the closest matching field or subproperty label. For example: heading/headline/title requests should fill heading-like fields, subheading/tagline/dek requests should fill subheading-like fields, image/photo/picture requests should fill media/image fields, and history/body/description/copy requests should fill copy/body/caption fields.\n"
      . "- For non-media entity references, omit the field instead of returning a null value you cannot satisfy.\n"
      . "- Keep values concise but production-usable.\n"
      . "- Never wrap the JSON in markdown fences.\n\n"
      . ($compact_schema !== '' ? "Compact field schema:\n" . $compact_schema . "\n\n" : '')
      . ($custom_field_guidance !== '' ? "Field-specific guidance:\n" . $custom_field_guidance . "\n\n" : '')
      . "Identifier plan JSON:\n"
      . json_encode($plan, JSON_PRETTY_PRINT)
      . (!empty($context['block_tools']) ? "\n\nBlock inspection tool JSON:\n" . json_encode($context['block_tools'], JSON_PRETTY_PRINT) : '')
      . "\n\nExisting instructions JSON for revision context:\n"
      . json_encode($context['current_instructions']['instructions'] ?? [], JSON_PRETTY_PRINT)
      . $this->getConfiguredContextPrompt();
  }

  /**
   * Builds the compact schema/value contract for one existing block edit.
   */
  protected function getEditorPrompt($block_type, array $block_definition, array $context) {
    $schema = [];
    foreach ($block_definition['fields'] ?? [] as $field_name => $field) {
      if ($field_name !== 'body' && !str_starts_with($field_name, 'field_')) {
        continue;
      }
      $schema[$field_name] = array_filter([
        'label' => (string) ($field['label'] ?? $field_name),
        'type' => (string) ($field['type'] ?? ''),
        'required' => !empty($field['required']),
        'cardinality' => isset($field['cardinality']) ? (int) $field['cardinality'] : NULL,
        'target_type' => (string) ($field['target_type'] ?? ''),
        'allowed_values' => array_values($field['allowed_values'] ?? []),
        'properties' => $field['properties'] ?? [],
        'guidance' => (string) ($field['guidance'] ?? ''),
      ], static fn ($value): bool => $value !== '' && $value !== [] && $value !== NULL && $value !== FALSE);
    }

    $values = [];
    foreach ($context['current_instructions']['instructions'][0]['field_info'] ?? [] as $field_name => $field_value) {
      $field_value = is_array($field_value) ? $field_value : ['value' => $field_value];
      unset($field_value['type']);
      $values[$field_name] = $field_value;
    }

    return "You edit one existing Drupal block through a compact JSON contract.\n\n"
      . "Return valid JSON only in this shape:\n"
      . "{\n"
      . "  \"description\": \"short summary\",\n"
      . "  \"values\": {\n"
      . "    \"field_name\": {\"value\": \"complete updated value\"}\n"
      . "  }\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Use only field names present in schema.\n"
      . "- Return only fields whose values must change; omitted fields remain unchanged.\n"
      . "- For a changed compound or multi-value field, return its complete updated value, including retained items.\n"
      . "- Preserve valid formats, IDs, option tokens, and structured subproperties unless the request changes them.\n"
      . "- Do not invent quotations, attributions, factual claims, URLs, or media IDs. If an approved source or exact value is required but missing, return an empty values object.\n"
      . "- Never wrap JSON in markdown fences.\n\n"
      . "Edit contract JSON:\n"
      . json_encode([
        'block_type' => (string) $block_type,
        'schema' => $schema,
        'values' => $values,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      . (!empty($context['block_tools']) ? "\n\nBlock inspection JSON:\n" . json_encode($context['block_tools'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '')
      . $this->getConfiguredContextPrompt();
  }

  /**
   * Formats configured reference sources for composition prompts.
   */
  protected function getConfiguredContextPrompt(): string {
    return '';
  }

  protected function buildCreatorInput($prompt, array $context) {
    $revision_prompt = trim((string) ($context['revision_prompt'] ?? ''));
    $uploaded_assets = $context['uploaded_assets'] ?? [];
    $prefer_ai_images = !empty($context['prefer_ai_images']);

    $input = "Original request:\n" . $prompt;

    if ($prefer_ai_images) {
      $input .= "\n\nAI image generation preference: prefer newly generated AI artwork over existing media unless the request explicitly points to a supplied file or direct image URL.";
    }

    if ($uploaded_assets) {
      $input .= "\n\nUploaded assets JSON:\n" . json_encode($uploaded_assets, JSON_PRETTY_PRINT);
    }

    if ($revision_prompt === '') {
      return $input;
    }

    return $input . "\n\nRevision request:\n" . $revision_prompt;
  }

  protected function requestChatCompletion(array $messages, $temperature = 0.7, ?callable $stream_callback = NULL) {
    try {
      $response = $this->generator->generateStructured($messages, $this->selectedModel ?: NULL);
    }
    catch (\InvalidArgumentException $exception) {
      $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown';
      $this->logger->warning('Rejected assistant planner request during @operation: @message', [
        '@operation' => $caller,
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
    $usage = $response['usage'] ?? [];
    if (is_array($usage)) {
      $this->usageEvents[] = [
        'endpoint' => 'responses',
        'model' => (string) ($response['model'] ?? ''),
        'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
        'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
      ];
    }
    if ($stream_callback !== NULL) {
      $stream_callback('', $response);
    }
    return [
      'choices' => [
        [
          'message' => [
            'content' => json_encode($response['data'] ?? [], JSON_UNESCAPED_SLASHES),
          ],
        ],
      ],
      'usage' => [
        'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
        'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
      ],
    ];
  }

  protected function parseJsonMessage(array $result) {
    if (empty($result['choices'][0]['message']['content'])) {
      throw new \Exception('Invalid response from OpenAI');
    }

    $content = trim($result['choices'][0]['message']['content']);
    if (preg_match('/```(?:json)?\s*(.*?)```/is', $content, $matches)) {
      $content = trim($matches[1]);
    }

    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded)) {
      $json_object = $this->extractJsonObject($content);
      if ($json_object !== NULL) {
        $decoded = json_decode($json_object, TRUE);
      }
    }

    if (!is_array($decoded)) {
      throw new \Exception('OpenAI response was not valid JSON.');
    }

    return $decoded;
  }

  /**
   * Tracks token usage from an API response when available.
   */
  protected function trackUsageEvent($url, array $payload, array $result) {
    $usage = !empty($result['usage']) && is_array($result['usage']) ? $result['usage'] : [];
    if (!$usage) {
      return;
    }

    $prompt_tokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
    $completion_tokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
    $total_tokens = (int) ($usage['total_tokens'] ?? 0);
    if ($total_tokens === 0) {
      $total_tokens = $prompt_tokens + $completion_tokens;
    }

    $this->usageEvents[] = [
      'endpoint' => $url,
      'model' => (string) ($payload['model'] ?? ''),
      'prompt_tokens' => $prompt_tokens,
      'completion_tokens' => $completion_tokens,
      'total_tokens' => $total_tokens,
    ];
  }

  /**
   * Extracts the first complete JSON object from a string.
   *
   * @param string $content
   *   The raw model content.
   *
   * @return string|null
   *   The extracted JSON object string when found.
   */
  protected function extractJsonObject($content) {
    $start = strpos($content, '{');
    if ($start === FALSE) {
      return NULL;
    }

    $length = strlen($content);
    $depth = 0;
    $in_string = FALSE;
    $is_escaped = FALSE;

    for ($index = $start; $index < $length; $index++) {
      $char = $content[$index];

      if ($in_string) {
        if ($is_escaped) {
          $is_escaped = FALSE;
          continue;
        }

        if ($char === '\\') {
          $is_escaped = TRUE;
          continue;
        }

        if ($char === '"') {
          $in_string = FALSE;
        }

        continue;
      }

      if ($char === '"') {
        $in_string = TRUE;
        continue;
      }

      if ($char === '{') {
        $depth++;
        continue;
      }

      if ($char === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($content, $start, $index - $start + 1);
        }
      }
    }

    return NULL;
  }

  /**
   * Ensures planned media asset requirements are carried into the final payload.
   *
   * @param array $payload
   *   The creator payload.
   * @param array $plan
   *   The identifier plan.
   * @param array $blockData
   *   Block metadata.
   *
   * @return array
   *   The normalized payload.
   */
  protected function normalizeAssetRequirements(array $payload, array $plan, array $blockData, array $context = []) {
    $selected_block_type = $plan['selected_block_type'] ?? '';
    $selected_definition = $blockData['content_blocks'][$selected_block_type] ?? [];
    $field_definitions = $selected_definition['fields'] ?? [];
    $asset_requirements = $plan['asset_requirements'] ?? [];
    $prefer_ai_images = !empty($context['prefer_ai_images']);
    $preferred_uploaded_image = !$prefer_ai_images ? $this->getPreferredUploadedImageAsset($context) : NULL;

    foreach ($payload['instructions'] as &$instruction) {
      if (empty($instruction['field_info']) || !is_array($instruction['field_info'])) {
        $instruction['field_info'] = [];
      }

      foreach ($asset_requirements as $requirement) {
        $field_name = $requirement['field_name'] ?? NULL;
        if (!$field_name || empty($field_definitions[$field_name])) {
          continue;
        }

        $field_definition = $field_definitions[$field_name];
        $is_media_reference = ($field_definition['type'] ?? NULL) === 'entity_reference' && ($field_definition['target_type'] ?? NULL) === 'media';
        $is_moody_showcase = ($field_definition['type'] ?? NULL) === 'moody_showcase';
        if (!$is_media_reference && !$is_moody_showcase) {
          continue;
        }

        if ($is_moody_showcase) {
          $existing_value = $instruction['field_info'][$field_name]['value'] ?? [];
          if (!is_array($existing_value) || $existing_value === []) {
            $existing_value = [[
              'headline' => $instruction['description'] ?? '',
              'copy_value' => '',
              'copy_format' => 'flex_html',
            ]];
          }
          if (array_keys($existing_value) !== range(0, count($existing_value) - 1)) {
            $existing_value = [$existing_value];
          }

          if (empty($existing_value[0]['image']) || !is_array($existing_value[0]['image'])) {
            $existing_value[0]['image'] = [];
          }

          $existing_value[0]['image'] = array_merge(
            $existing_value[0]['image'],
            [
              'target_id' => $existing_value[0]['image']['target_id'] ?? $requirement['target_id'] ?? NULL,
              'asset_type' => $requirement['asset_type'] ?? 'image',
              'image_url' => $existing_value[0]['image']['image_url'] ?? $requirement['image_url'] ?? '',
              'image_prompt' => $existing_value[0]['image']['image_prompt'] ?? $requirement['prompt'] ?? '',
              'alt' => $existing_value[0]['image']['alt'] ?? $requirement['alt'] ?? 'AI generated image',
              'title' => $existing_value[0]['image']['title'] ?? $requirement['title'] ?? 'AI generated image',
            ]
          );

          $instruction['field_info'][$field_name] = array_merge(
            [
              'type' => 'moody_showcase',
              'value' => $existing_value,
            ],
            $instruction['field_info'][$field_name] ?? [],
            [
              'type' => 'moody_showcase',
              'value' => $existing_value,
            ]
          );
          continue;
        }

        $instruction['field_info'][$field_name] = array_merge(
          [
            'type' => 'entity_reference',
            'value' => NULL,
          ],
          $instruction['field_info'][$field_name] ?? [],
          [
            'target_id' => $instruction['field_info'][$field_name]['target_id'] ?? $requirement['target_id'] ?? NULL,
            'asset_type' => $requirement['asset_type'] ?? 'image',
            'image_url' => $instruction['field_info'][$field_name]['image_url'] ?? $requirement['image_url'] ?? '',
            'image_prompt' => $instruction['field_info'][$field_name]['image_prompt'] ?? $requirement['prompt'] ?? '',
            'alt' => $instruction['field_info'][$field_name]['alt'] ?? $requirement['alt'] ?? 'AI generated image',
            'title' => $instruction['field_info'][$field_name]['title'] ?? $requirement['title'] ?? 'AI generated image',
          ]
        );
      }

      if (!empty($context['edit_mode'])) {
        continue;
      }

      foreach ($field_definitions as $field_name => $field_definition) {
        $is_required_media_reference = !empty($field_definition['required'])
          && ($field_definition['type'] ?? NULL) === 'entity_reference'
          && ($field_definition['target_type'] ?? NULL) === 'media';

        if (!$is_required_media_reference) {
          continue;
        }

        if (!isset($instruction['field_info'][$field_name])) {
          if ($preferred_uploaded_image) {
            $instruction['field_info'][$field_name] = [
              'type' => 'entity_reference',
              'value' => NULL,
              'target_id' => $preferred_uploaded_image['target_id'],
              'asset_type' => 'image',
              'alt' => $preferred_uploaded_image['alt'] ?? '',
              'title' => $preferred_uploaded_image['title'] ?? '',
            ];
            continue;
          }

          $instruction['field_info'][$field_name] = [
            'type' => 'entity_reference',
            'value' => NULL,
            'asset_type' => 'image',
            'image_url' => $this->extractImageUrlsFromPrompt($plan['notes_prompt_source'] ?? '')[0] ?? '',
            'image_prompt' => sprintf('Editorial image for %s on %s', $field_name, $selected_block_type),
            'alt' => sprintf('AI generated image for %s', str_replace('_', ' ', $selected_block_type)),
            'title' => sprintf('%s image', ucwords(str_replace('_', ' ', $selected_block_type))),
          ];
        }
      }
    }
    unset($instruction);

    return $payload;
  }

  /**
   * Gets the first uploaded image asset available in generation context.
   *
   * @param array $context
   *   Prompt context.
   *
   * @return array|null
   *   The preferred uploaded image asset, or NULL.
   */
  protected function getPreferredUploadedImageAsset(array $context) {
    foreach ($context['uploaded_assets'] ?? [] as $asset) {
      if (($asset['asset_type'] ?? '') === 'image' && ($asset['intent'] ?? 'content') !== 'inspiration' && !empty($asset['target_id'])) {
        return $asset;
      }
    }

    return NULL;
  }

  /**
   * Builds field-specific creator guidance for compound custom fields.
   *
   * @param array $selected_definition
   *   The selected block schema.
   *
   * @return string
   *   Guidance text for the creator prompt.
   */
  protected function getCustomFieldGuidance(array $selected_definition) {
    $guidance = [];
    foreach ($selected_definition['fields'] ?? [] as $field_name => $field_definition) {
      $properties = $field_definition['properties'] ?? [];
      if (empty($properties)) {
        continue;
      }

      $guidance[] = '- ' . $field_name . ' is a compound field of type ' . ($field_definition['type'] ?? 'custom') . '.';
      $guidance[] = '  Return "value" as an object for single-value fields or an array of objects for multi-value fields.';
      $guidance[] = '  Available subproperties: ' . implode(', ', array_keys($properties)) . '.';

      if (!empty($field_definition['guidance'])) {
        $guidance[] = '  ' . $field_definition['guidance'];
      }

      if (($field_definition['type'] ?? '') === 'utexas_promo_unit') {
        $guidance[] = '  For Promo Unit fields, do not return the raw serialized promo_unit_items storage blob.';
        $guidance[] = '  Use this shape instead: value = { headline: "Group heading", items: [{ headline: "Item heading", image: { target_id or asset_type/image_url/image_prompt }, copy_value: "Short supporting copy", copy_format: "flex_html", link_uri: "/path-or-url", link_title: "Call to action", link_options: {} }] }.';
        $guidance[] = '  Include at least one non-empty item whenever you choose a Promo Unit block. Each item should have meaningful visible content, typically a headline plus copy, link, image, or a combination of those.';
      }

      if (($field_definition['type'] ?? '') === 'utexas_promo_list') {
        $guidance[] = '  For Promo List fields, do not return HTML or the raw serialized promo_list_items storage blob.';
        $guidance[] = '  Use this shape instead: value = { headline: "Group heading", items: [{ headline: "Item heading", image: { target_id or asset_type/image_url/image_prompt }, copy_value: "Short supporting copy", copy_format: "flex_html", link_uri: "/path-or-url", link_title: "Call to action" }] }.';
        $guidance[] = '  Include at least one non-empty item whenever you choose a Promo List block.';
      }

      if (($field_definition['type'] ?? '') === 'utexas_resources') {
        $guidance[] = '  For Resources fields, do not return HTML or the raw serialized resource_items storage blob.';
        $guidance[] = '  Use this shape instead: value = { headline: "Group heading", items: [{ headline: "Resource heading", image: { target_id or asset_type/image_url/image_prompt }, links: [{ uri: "/path-or-url", title: "Descriptive link" }] }] }.';
        $guidance[] = '  Include at least one non-empty resource item with a headline or link.';
      }

      if (($field_definition['type'] ?? '') === 'moody_focus_areas') {
        $guidance[] = '  For Moody Focus Areas fields, do not return HTML or the raw serialized focus_areas_items storage blob.';
        $guidance[] = '  Use this shape instead: value = { items_title: "Group heading", items_style: "default", items_gap: 3, items_row_gap: 3, items: [{ headline: "Area heading", copy_value: "Short supporting copy", copy_format: "flex_html", image: { optional media data }, link_uri: "/optional-path", link_title: "Optional link" }] }.';
        $guidance[] = '  Include at least one non-empty focus area item.';
      }

      if (($field_definition['type'] ?? '') === 'utexas_flex_content_area') {
        $guidance[] = '  For Flex Content Area fields, return links as an array such as [{ uri: "/path", title: "Descriptive link" }]. Do not return a delimited string or raw serialized links value.';
      }

      if (($field_definition['type'] ?? '') === 'moody_impact_facts') {
        $guidance[] = '  For Moody Impact Facts fields, do not return the raw serialized impact_items storage blob.';
        $guidance[] = '  Use this shape instead: value = { headline: "Group heading", style: "orange-headline|grey-headline", col_number: "two-per-row|three-per-row|four-per-row", items: [{ headline: "Large statistic or fact", subheadline: "Short supporting context" }] }.';
        $guidance[] = '  Include at least one non-empty item whenever you choose a Moody Impact Facts block. Each item should have a visible headline, and usually also a short subheadline.';
      }

      if (isset($properties['image']) || isset($properties['media'])) {
        $media_property = isset($properties['image']) ? 'image' : 'media';
        $guidance[] = '  The ' . $media_property . ' subproperty should be either a numeric media ID or an object with asset_type=image plus either image_url or image_prompt, along with alt and title.';
      }

      if (isset($properties['copy_value']) && isset($properties['copy_format'])) {
        $guidance[] = '  When using rich copy, supply both copy_value and copy_format together.';
      }

      if (isset($properties['link_uri']) || isset($properties['link_title']) || isset($properties['link_options'])) {
        $guidance[] = '  For links, use link_uri, link_title, and optionally link_options.';
      }
    }

    return implode("\n", $guidance);
  }

  /**
   * Builds a compact readable schema summary for the selected block.
   *
   * @param array $selected_definition
   *   The selected block definition.
   *
   * @return string
   *   Human-readable schema guidance.
   */
  protected function buildCompactFieldSchema(array $selected_definition) {
    $lines = [];
    foreach ($selected_definition['fields'] ?? [] as $field_name => $field_definition) {
      $label = $field_definition['label'] ?? $field_name;
      $type = $field_definition['type'] ?? 'unknown';
      $required = !empty($field_definition['required']) ? 'required' : 'optional';
      $line = '- ' . $field_name . ' (' . $label . ', ' . $type . ', ' . $required . ')';

      if (!empty($field_definition['allowed_values'])) {
        $line .= ' -> allowed values: ' . implode('|', $field_definition['allowed_values']);
      }

      $properties = $field_definition['properties'] ?? [];
      if (!empty($properties)) {
        $property_bits = [];
        foreach ($properties as $property_name => $property_definition) {
          $property_bits[] = $property_name . ' [' . ($property_definition['label'] ?? $property_name) . ']';
        }
        $line .= ' -> properties: ' . implode(', ', $property_bits);
      }

      $lines[] = $line;
    }

    return implode("\n", $lines);
  }

  /**
   * Adds inferred image asset requirements when the prompt clearly asks for imagery.
   *
   * @param array $plan
   *   The current plan.
   * @param string $prompt
   *   The user's original prompt.
   * @param array $blockData
   *   Collected block metadata.
   *
   * @return array
   *   The plan with any inferred image asset requirements merged in.
   */
  protected function mergeInferredAssetRequirements(array $plan, $prompt, array $blockData) {
    $selected_block_type = $plan['selected_block_type'] ?? NULL;
    if (!$selected_block_type || !$this->promptRequestsImage($prompt)) {
      return $plan;
    }

    $plan['notes_prompt_source'] = $prompt;

    $selected_definition = $blockData['content_blocks'][$selected_block_type] ?? [];
    $candidate_fields = $this->getImageCapableFields($selected_definition);
    if (empty($candidate_fields)) {
      return $plan;
    }

    $existing_requirements = $plan['asset_requirements'] ?? [];
    $existing_fields = [];
    foreach ($existing_requirements as $requirement) {
      if (!empty($requirement['field_name'])) {
        $existing_fields[] = $requirement['field_name'];
      }
    }

    foreach ($candidate_fields as $field_name) {
      if (in_array($field_name, $existing_fields, TRUE)) {
        continue;
      }

      $existing_requirements[] = $this->buildInferredImageRequirement($field_name, $selected_block_type, $prompt);
      break;
    }

    $plan['asset_requirements'] = $existing_requirements;
    return $plan;
  }

  /**
   * Determines whether a user prompt explicitly requests imagery.
   *
   * @param string $prompt
   *   The user's prompt.
   *
   * @return bool
   *   TRUE when imagery was requested.
   */
  protected function promptRequestsImage($prompt) {
    return (bool) preg_match('/\b(image|images|picture|pictures|photo|photos|photograph|photographs|illustration|illustrations|graphic|graphics|artwork|visual|hero image|historic photo)\b/i', (string) $prompt);
  }

  /**
   * Returns image-capable field names for the selected block schema.
   *
   * @param array $selected_definition
   *   The selected block definition.
   *
   * @return array
   *   Candidate field names.
   */
  protected function getImageCapableFields(array $selected_definition) {
    $candidates = [];
    foreach ($selected_definition['fields'] ?? [] as $field_name => $field_definition) {
      $is_media_reference = ($field_definition['type'] ?? NULL) === 'entity_reference'
        && ($field_definition['target_type'] ?? NULL) === 'media';
      $properties = $field_definition['properties'] ?? [];
      $is_compound_media_field = isset($properties['image']) || isset($properties['media']);

      if ($is_media_reference || $is_compound_media_field) {
        $candidates[] = $field_name;
      }
    }

    return $candidates;
  }

  /**
   * Builds a fallback image asset requirement from the user prompt.
   *
   * @param string $field_name
   *   The target field.
   * @param string $selected_block_type
   *   The selected block type.
   * @param string $prompt
   *   The user prompt.
   *
   * @return array
   *   The inferred requirement.
   */
  protected function buildInferredImageRequirement($field_name, $selected_block_type, $prompt) {
    $block_label = str_replace('_', ' ', (string) $selected_block_type);
    $image_url = $this->extractImageUrlsFromPrompt($prompt)[0] ?? '';
    return [
      'field_name' => $field_name,
      'asset_type' => 'image',
      'image_url' => $image_url,
      'prompt' => 'Create an editorial image that matches this request: ' . trim((string) $prompt),
      'alt' => 'AI generated image for ' . $block_label,
      'title' => ucwords($block_label) . ' image',
    ];
  }

  /**
   * Extracts image-like URLs from the user prompt.
   *
   * @param string $prompt
   *   The raw prompt.
   *
   * @return array
   *   Matching image URLs.
   */
  protected function extractImageUrlsFromPrompt($prompt) {
    preg_match_all('/https?:\/\/[^\s\]"\'>]+/i', (string) $prompt, $matches);
    $urls = [];
    foreach ($matches[0] ?? [] as $url) {
      $url = rtrim($url, '.,);');
      $path = strtolower((string) parse_url($url, PHP_URL_PATH));
      if (preg_match('/\.(jpg|jpeg|png|gif|webp)(?:$|\?)/i', $path)) {
        $urls[] = $url;
      }
    }

    return array_values(array_unique($urls));
  }
}
