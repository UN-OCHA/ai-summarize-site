<?php

namespace Drupal\ocha_ai_summarize\Controller;

use Drupal\content_moderation\Entity\ContentModerationState;
use Drupal\content_moderation\ModerationInformation;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Get overview of all documents.
 */
class OchaAiSummarizeStats extends ControllerBase {

  /**
   * Moderation service.
   *
   * @var \Drupal\content_moderation\ModerationInformation
   */
  protected $moderationService;

  /**
   * {@inheritdoc}
   */
  public function __construct(ModerationInformation $moderation_information_service) {
    $this->moderationService = $moderation_information_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_moderation.moderation_information')
    );
  }

  /**
   * Returns sales report.
   */
  public function getPage() {
    $headers = $this->getHeaders();
    $rows = $this->getData();

    return [
      'table' => [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $rows,
      ],
      'download' => [
        Link::createFromRoute('Download', 'ocha_ai_summarize.stats_csv')->toRenderable(),
      ],
    ];
  }

  /**
   * Returns csv.
   */
  public function getCsv() {
    $headers = $this->getHeaders();
    $rows = $this->getData(TRUE);

    $filename = 'temporary://' . time() . '.csv';
    $f = fopen($filename, 'w');
    fputcsv($f, $headers);

    foreach ($rows as $row) {
      fputcsv($f, $row);
    }

    fclose($f);

    return new BinaryFileResponse($filename, 200, [
      'Content-Type' => 'text/csv',
      'Content-Description' => 'File Download',
      'Content-Disposition' => 'attachment; filename=statistics.csv',
    ]);
  }

  /**
   * Returns headers.
   */
  protected function getHeaders() : array {
    return [
      'Document',
      'Type',
      'Status',
      'AI',
      'Extract text',
      'Summarize',
      'Action points',
    ];
  }

  /**
   * Returns data.
   */
  protected function getData($simple = FALSE) : array {
    $nodes = $this->entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => ['action_points', 'summary'],
    ]);

    $moderation_information_service = $this->moderationService;

    $rows = [];
    /** @var \Drupal\node\Entity\Node */
    foreach ($nodes as $node) {
      $content_moderation_state = ContentModerationState::loadFromModeratedEntity($node);
      if (!$content_moderation_state) {
        $state = 'document_uploaded';
      }
      else {
        $state = $content_moderation_state->get('moderation_state')->value;
      }

      $workflow = $moderation_information_service->getWorkflowForEntity($node);
      $state_label = $workflow->getTypePlugin()->getState($state)->label();

      $current_ai = $node->get('field_ai_brain')->value;
      $ais = $node->get('field_ai_brain')->getSettings()['allowed_values'];

      $rows[$node->id()] = [
        $simple ? $node->id() : $node->toLink($node->getTitle()),
        $node->bundle(),
        $state_label,
        $ais[$current_ai],
        0,
        0,
        0,
      ];

      $timings = ocha_ai_summarize_log_time_get($node->id());
      if (!empty($timings)) {
        foreach ($timings as $timing) {
          switch ($timing->action) {
            case 'extract_text':
              $rows[$node->id()][4] += round($timing->duration / 1000, 2);
              break;

            case 'summarize':
              $rows[$node->id()][5] += round($timing->duration / 1000, 2);
              break;

            case 'action_point':
              $rows[$node->id()][6] += round($timing->duration / 1000, 2);
              break;

          }
        }
      }
    }

    return $rows;
  }

}
