<?php

namespace Drupal\ocha_ai_summarize\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller to compare summaries.
 */
class CompareSummaryController extends ControllerBase {

  /**
   * Output comparison.
   */
  public function compare($id = NULL) {
    $node = $this->entityTypeManager()
      ->getStorage('node')
      ->load($id);

    /** @var \Drupal\node\Entity\Node $nodes */
    $nodes = $this->entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'summary',
        'field_document.target_id' => $node->get('field_document')->target_id,
      ]);

    $headers = [];
    $columns = [];

    foreach ($nodes as $node) {
      $headers[] = $node->get('field_ai_brain')->value;
      $columns[] = $node->get('field_summary')->value;
    }

    $build = [
      '#type' => 'table',
      '#caption' => $this->t('Summary comparison'),
      '#header' => $headers,
      '#rows' => [$columns],
    ];

    return $build;
  }

}
