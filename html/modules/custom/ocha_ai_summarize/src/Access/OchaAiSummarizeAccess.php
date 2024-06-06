<?php

declare(strict_types=1);

namespace Drupal\ocha_ai_summarize\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks access for reporting.
 */
class OchaAiSummarizeAccess implements AccessInterface {

  use LoggerChannelTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Retrieves a configuration object.
   */
  protected function config($name) {
    return $this->configFactory->get($name);
  }

  /**
   * Access result callback.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Determines the access to controller.
   */
  public function access(AccountInterface $account) {
    $header_secret = $this->requestStack->getCurrentRequest()->headers->get('weekly-stats-api-key') ?? NULL;
    $config_secret = $this->config('ocha_ai_summarize')->get('statistics.key');
    $config_secret='1234';
    if ((!empty($header_secret) && $header_secret === $config_secret)
      || $account->hasPermission('ocha_ai_summarize stats')) {
      $access_result = AccessResult::allowed();
    }
    else {
      $access_result = AccessResult::forbidden('Access denied');
      $logger = $this->getLogger('ocha_ai_summarize');
      $logger->warning('Unauthorized access to weekly statistics');
    }
    $access_result
      ->setCacheMaxAge(0)
      ->addCacheContexts([
        'headers:weekly-stats-api-key',
        'user.roles',
      ])
      ->addCacheTags(['ocha_ai_summarize']);

    return $access_result;
  }

}
