<?php

namespace Drupal\ocha_ai_summarize\Plugin\QueueWorker;

use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
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

    $prompt = "Extract the action points from the following meeting minutes";

    // Claude can handle all text at once.
    if ($bot == 'claude') {
      $text = '';
      foreach ($node->field_document_text as $document_text) {
        $text = $document_text->value . "\n";
      }

      $action_points = $this->sendToClaudeAi("$prompt:\n\n" . $text);
    }
    else {
      // Summarize each page.
      $results = [];
      foreach ($node->field_document_text as $document_text) {
        $text = $document_text->value;

        if (strlen($text) < 100) {
          $results[] = $text;
          continue;
        }

        switch ($bot) {
          case 'openai':
            $results[] = $this->sendToOpenAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
            break;

          case 'azure_trained':
            $results[] = $this->sendToAzureAi("Summerize the following text in 3 paragraphs:\n\n" . $text);
            break;

          case 'bedrock':
            $results[] = $this->sendToBedRock("Summerize the following text in 3 paragraphs:\n\n" . $text);
            break;

        }
      }

      // Get the action points.
      $text = '';
      foreach ($results as $row) {
        $text .= $row;
        $text .= "\n";
      }

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
      }
    }

    $node->set('field_action_points', [
      'value' => $action_points,
      'format' => 'text_editor_simple',
    ]);
    $node->set('moderation_state', 'action_points_created');
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
        'messages' => [
          [
            'role' => 'system',
            'content' => 'You are an AI assistant that extracts action points out of meeting minutes.',
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

  /**
   * Send query to BedRock.
   */
  protected function sendToBedRock($text) : string {
    $result = ocha_ai_summarize_http_call_bedrock($text);
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
