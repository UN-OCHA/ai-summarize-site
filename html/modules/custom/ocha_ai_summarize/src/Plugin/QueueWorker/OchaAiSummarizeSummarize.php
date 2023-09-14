<?php

namespace Drupal\ocha_ai_summarize\Plugin\QueueWorker;

use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a PDF file.
 *
 * @QueueWorker(
 *   id = "ocha_ai_summarize_summarize",
 *   title = @Translation("Use AI to summarize"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiSummarizeSummarize extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    if (empty($nid)) {
      return;
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node || $node->bundle() !== 'summary') {
      return;
    }

    $content_moderation_state = ContentModerationState::loadFromModeratedEntity($node);

    if (!$content_moderation_state) {
      return;
    }

    if ($content_moderation_state->get('moderation_state')->value !== 'summarize') {
      return;
    }

    if ($node->field_pdf_text->isEmpty()) {
      return;
    }

    // Summarize each page.
    $results = [];
    foreach ($node->field_pdf_text as $pdf_text) {
      $text = $pdf_text->value;

      if (strlen($text) < 100) {
        continue;
      }

      if ($bot == 'openai') {
        $results[] = $this->sendToOpenAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
      }
      else {
        $results[] = $this->sendToAzureAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
      }
    }

    // Summarize the summaries.
    $text = '';
    foreach ($results as $row) {
      $text .= $row;
      $text .= "\n";
    }

    if ($bot == 'openai') {
      $summary = $this->sendToOpenAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
    }
    else {
      $summary = $this->sendToAzureAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
    }

    $node->set('field_summary', $summary);
    $node->set('moderation_state', 'summarized');
    $node->save();
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
            'content' => "Summerize the following text in 3 paragraphs:\n\n" . $text,
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
        'messages' => [
          [
            'role' => 'system',
            'content' => 'You are an AI assistant that summarizes information.',
          ],
          [
            'role' => 'user',
            'content' => $text,
          ],
        ],
      ],
    );

    return $result['choices'][0]['message']['content'] ?? '';
  }

}
