<?php

namespace Drupal\ocha_ai_summarize\Plugin\QueueWorker;

use Drupal\Component\Utility\Timer;
use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a document file.
 *
 * @QueueWorker(
 *   id = "ocha_ai_summarize_action_points",
 *   title = @Translation("Use AI to extract action points"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiSummarizeActionPoints extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $bot = $data->brain ?? 'openai';
    $nid = $data->nid;
    $document_language = $data->language ?? 'eng';
    if ($document_language == 'Arabic') {
      $document_language = 'ara';
    }

    $output_language = $data->output_language ?? 'eng';
    if ($output_language == 'Arabic') {
      $output_language = 'ara';
    }

    if (empty($nid)) {
      return;
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'action_points') {
      return;
    }

    $content_moderation_state = ContentModerationState::loadFromModeratedEntity($node);

    if (!$content_moderation_state) {
      return;
    }

    if ($content_moderation_state->get('moderation_state')->value !== 'action_points') {
      return;
    }

    if ($node->field_document_text->isEmpty()) {
      return;
    }

    if ($document_language !== $output_language) {
      \Drupal::logger('AI Summarize')->info('Generate action points from @file_name and translate to @output_language using @brain', [
        '@brain'           => $data->brain,
        '@output_language' => ocha_ai_summarize_get_lang_name_translated($output_language),
      ]);
    }
    else {
      \Drupal::logger('AI Summarize')->info('Generate action points from @file_name using @brain', [
        '@brain'     => $data->brain,
        '@file_name' => $data->file_name,
      ]);
    }

    $prompt = $this->t("Extract the action points from the following meeting minutes.", [], [
      'langcode' => ocha_ai_summarize_get_lang_code($document_language),
    ])->__toString();

    if ($document_language !== $output_language) {
      $prompt = $this->t('Extract the action points from the following meeting minutes and translate to @output_language.', [
        '@output_language' => ocha_ai_summarize_get_lang_name_translated($output_language),
      ], [
        'langcode' => ocha_ai_summarize_get_lang_code($document_language),
      ])->__toString();
    }

    $prompt .= "\n\n";

    // Claude can handle all text at once.
    if ($bot == 'claude') {
      $text = '';
      foreach ($node->field_document_text as $document_text) {
        $text = $document_text->value . "\n";
      }

      $text = ocha_ai_summarize_check_length($text, $bot);
      Timer::start('action_points');
      $action_points = $this->sendToClaudeAi("$prompt:\n\n" . $text);
      ocha_ai_summarize_log_time_action_points($nid, Timer::read('action_points'));
      Timer::stop('action_points');
    }
    else {
      // Summarize each page.
      Timer::start('summarize');
      $results = [];
      foreach ($node->field_document_text as $document_text) {
        $text = $document_text->value;

        if (strlen($text) < 100) {
          $results[] = $text;
          continue;
        }

        $text = ocha_ai_summarize_check_length($text, $bot);
        $prompt = $this->t('Summarize the following text in @num_paragraphs paragraphs', [
          '@num_paragraphs' => 3,
        ], [
          'langcode' => ocha_ai_summarize_get_lang_code($document_language),
        ])->__toString();
        $prompt .= ":\n\n";

        switch ($bot) {
          case 'openai':
            $results[] = $this->sendToOpenAi($prompt . $text);
            break;

          case 'azure_trained':
            $results[] = $this->sendToAzureAi($prompt . $text);
            break;

          case 'bedrock':
            $results[] = $this->sendToBedRock($prompt . $text);
            break;

          case 'amazon_titan_premier':
            $results[] = $this->sendToTitanPremier($prompt . $text);
            break;

        }
      }
      ocha_ai_summarize_log_time_summarize($nid, Timer::read('summarize'));
      Timer::stop('summarize');

      // Get the action points.
      $text = '';
      foreach ($results as $row) {
        $text .= $row;
        $text .= "\n";
      }

      $text = ocha_ai_summarize_check_length($text, $bot);

      Timer::start('action_points');
      switch ($bot) {
        case 'openai':
          $action_points = $this->sendToOpenAi("$prompt:\n\n" . $text);
          break;

        case 'azure_trained':
          $action_points = $this->sendToAzureAi("$prompt:\n\n" . $text);
          break;

        case 'bedrock':
          $action_points = $this->sendToBedRock("$prompt:\n\n" . $text);
          break;

        case 'amazon_titan_premier':
          $action_points = $this->sendToTitanPremier($prompt . $text);
          break;

      }
      ocha_ai_summarize_log_time_action_points($nid, Timer::read('action_points'));
      Timer::stop('action_points');
    }

    $node->set('field_action_points', [
      'value' => $action_points,
      'format' => 'text_editor_simple',
    ]);
    $node->set('moderation_state', 'action_points_created');
    $node->save();

    ocha_ai_summarize_notify_user($node);
  }

  /**
   * Send query to OpenAi.
   */
  protected function sendToOpenAi($text) : string {
    $result = ocha_ai_summarize_http_call_openai(
      [
        'model' => 'gpt-3.5-turbo-16k',
        'messages' => [
          [
            'role' => 'user',
            'content' => $text,
          ],
        ],
        'temperature' => .2,
        'max_tokens' => 600,
      ],
    );

    return $result['choices'][0]['message']['content'] ?? '';
  }

  /**
   * Send query to Azure AI.
   */
  protected function sendToAzureAi($text) : string {
    $result = ocha_ai_summarize_http_call_azure(
      [
        [
          'role' => 'system',
          'content' => $this->t('You are an AI assistant that extracts action points out of meeting minutes.'),
        ],
        [
          'role' => 'user',
          'content' => $text,
        ],
      ],
    );

    return $result ?? '';
  }

  /**
   * Send query to BedRock.
   */
  protected function sendToBedRock($text) : string {
    $result = ocha_ai_summarize_http_call_bedrock($text);
    return $result['results'][0]['outputText'] ?? '';
  }

  /**
   * Send query to Titan Premier.
   */
  protected function sendToTitanPremier($text) : string {
    $result = ocha_ai_summarize_http_call_titan_premier($text);
    print_r($result);
    return $result['results'][0]['outputText'] ?? '';
  }

  /**
   * Send query to Claude AI.
   */
  protected function sendToClaudeAi($text) : string {
    $prompt = "\n\nHuman: $text\n\nAssistant:";

    $result = ocha_ai_summarize_http_call_claude($prompt);

    return $result['completion'] ?? '';
  }

}
