<?php

namespace Drupal\ocha_ai_summarize\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Weekly stats.
 */
class OchaAiSummarizeWeeklyStats extends ControllerBase {

  /**
   * Generate weekly statistics.
   */
  public function weeklyStats(RouteMatchInterface $route_match, Request $request) {
    $data = ocha_ai_summarize_get_weekly_stats();

    return new JsonResponse($data, 200);
  }

}
