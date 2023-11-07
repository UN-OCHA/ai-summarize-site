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
    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager()
      ->getStorage('node')
      ->load($id);

    $field_name = 'field_summary';
    $caption = $this->t('Summary comparison');
    if ($node->bundle() == 'action_points') {
      $field_name = 'field_action_points';
      $caption = $this->t('Action point comparison');
    }

    $ais = $node->get('field_ai_brain')->getSettings()['allowed_values'];

    /** @var \Drupal\node\Entity\Node $nodes */
    $nodes = $this->entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => $node->bundle(),
        'field_document.target_id' => $node->get('field_document')->target_id,
      ]);

    $headers = [];
    $columns = [];
    $tags = [];

    foreach ($nodes as $node) {
      $headers[] = $ais[$node->get('field_ai_brain')->value];
      $columns[] = $node->get($field_name)->value;
      $tags = array_merge($tags, $node->getCacheTags());
    }

    $build = [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $headers,
      '#rows' => [$columns],
      '#cache' => [
        'tags' => $tags,
      ],
    ];

    return $build;
  }

}
