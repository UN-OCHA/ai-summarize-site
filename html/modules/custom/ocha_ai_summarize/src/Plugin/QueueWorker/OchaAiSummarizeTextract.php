<?php

namespace Drupal\ocha_ai_summarize\Plugin\QueueWorker;

use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extract text from a PDF file.
 *
 * @QueueWorker(
 *   id = "ocha_ai_summarize_textract",
 *   title = @Translation("Extract text from a PDF file using AWS Textract"),
 *   cron = {"time" = 30}
 * )
 */
class OchaAiSummarizeTextract extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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

    if ($content_moderation_state->get('moderation_state')->value !== 'extract_text') {
      return;
    }

    if (!$node->field_pdf_text->isEmpty()) {
      return;
    }

    /** @var \Drupal\file\Plugin\Field\FieldType\FileItem $file_item */
    $file_item = $node->get('field_pdf')->first() ?? NULL;
    if (!$file_item) {
      return;
    }

    /** @var \Drupal\file\Entity\File $file */
    $file = $this->entityTypeManager->getStorage('file')->load($file_item->getValue()['target_id']);
    if (!$file) {
      return;
    }

    $absolute_path = $this->fileSystem->realpath($file->getFileUri());

    $text = ocha_ai_summarize_texttract($absolute_path);
    // Split in BLOB of 3000 characters.
    $node->set('field_pdf_text', $text);
    $node->set('moderation_state', 'text_extracted');
    $node->save();
  }

}
