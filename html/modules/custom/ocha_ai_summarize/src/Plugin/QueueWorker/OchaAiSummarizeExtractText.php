<?php

namespace Drupal\ocha_ai_summarize\Plugin\QueueWorker;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\content_moderation\Entity\ContentModerationState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a document.
 *
 * @QueueWorker(
 *   id = "ocha_ai_summarize_extract_text",
 *   title = @Translation("Extract text from a document"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiSummarizeExtractText extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Queue.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, FileSystem $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
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
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = $data->nid;
    $document_language = $data->language ?? 'eng';
    if ($document_language == 'Arabic') {
      $document_language = 'ara';
    }

    if (empty($nid)) {
      return;
    }

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      return;
    }

    $content_moderation_state = ContentModerationState::loadFromModeratedEntity($node);
    if (!$content_moderation_state) {
      return;
    }

    if ($content_moderation_state->get('moderation_state')->value !== 'extract_text') {
      return;
    }

    if (!$node->field_document_text->isEmpty()) {
      return;
    }

    /** @var \Drupal\file\Plugin\Field\FieldType\FileItem $file_item */
    $file_item = $node->get('field_document')->first() ?? NULL;
    if (!$file_item) {
      return;
    }

    /** @var \Drupal\file\Entity\File $file */
    $file = $this->entityTypeManager->getStorage('file')->load($file_item->getValue()['target_id']);
    if (!$file) {
      return;
    }

    \Drupal::logger('AI Summarize')->info('Locally extract text from @file_name', [
      '@file_name' => $data->file_name,
    ]);

    // PDF or else.
    $absolute_path = $this->fileSystem->realpath($file->getFileUri());
    $file_parts = pathinfo($absolute_path);
    Timer::start('extract_text');
    if (strtolower($file_parts['extension']) == 'pdf') {
      $text = ocha_ai_summarize_extract_pages_from_pdf_ocr($absolute_path, $document_language);
    }
    else {
      $text = ocha_ai_summarize_extract_pages_from_doc($absolute_path, $document_language);
    }

    ocha_ai_summarize_log_time_extract($nid, Timer::read('extract_text'));
    Timer::stop('extract_text');

    $node->set('field_document_text', $text);
    $node->set('moderation_state', 'text_extracted');
    $node->save();
  }

}
