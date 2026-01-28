<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiAccessDeniedException;

/**
 * Manages AI provider fallback and retry logic.
 */
class AiProviderFallbackService {

  public function __construct(
    protected AiProviderPluginManager $aiProviderPluginManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Execute AI operation with fallback support.
   *
   * @param callable $operation
   *   Callback: function(string $providerId, string $modelId): array
   * @param string $operation_type
   *   Operation type (default: 'chat_with_complex_json')
   *
   * @return array
   *   Keys: success (bool), data (array), provider_used (string),
   *         attempts (int), last_error (string|null)
   */
  public function executeWithFallback(
    callable $operation,
    string $operation_type = 'chat_with_complex_json'
  ): array {
    $config = $this->configFactory->get('seo_audit.settings');
    $providerChain = $this->getProviderChain($operation_type);

    if (empty($providerChain)) {
      return [
        'success' => FALSE,
        'data' => [],
        'provider_used' => NULL,
        'attempts' => 0,
        'last_error' => 'No providers configured in chain',
      ];
    }

    $attemptCount = 0;
    $lastError = NULL;

    foreach ($providerChain as $provider) {
      $providerId = $provider['provider_id'];
      $modelId = $provider['model_id'];

      // Skip disabled providers
      if (!($provider['enabled'] ?? TRUE)) {
        continue;
      }

      $attemptCount++;

      try {
        // Attempt with this provider
        $result = $this->executeWithRetry(
          $operation,
          $providerId,
          $modelId,
          $config
        );

        // Success!
        $this->logSuccess($providerId, $modelId, $attemptCount);

        return [
          'success' => TRUE,
          'data' => $result,
          'provider_used' => "{$providerId}__{$modelId}",
          'attempts' => $attemptCount,
          'last_error' => NULL,
        ];

      }
      catch (\Exception $e) {
        $lastError = $e->getMessage();
        $this->logFailure($providerId, $modelId, $e);

        // Determine if we should continue to next provider
        if (!$this->shouldContinueOnError($e)) {
          break;
        }
      }
    }

    // All providers failed
    return [
      'success' => FALSE,
      'data' => [],
      'provider_used' => NULL,
      'attempts' => $attemptCount,
      'last_error' => $lastError,
    ];
  }

  /**
   * Execute operation with retry logic for rate limits.
   */
  protected function executeWithRetry(
    callable $operation,
    string $providerId,
    string $modelId,
    $config
  ): array {
    $maxRetries = $config->get('rate_limit_max_retries') ?? 2;
    $retryDelay = $config->get('rate_limit_retry_delay') ?? 5;
    $shouldRetryRateLimit = $config->get('retry_on_rate_limit') ?? TRUE;

    $attempt = 0;
    $lastException = NULL;

    while ($attempt <= $maxRetries) {
      try {
        return $operation($providerId, $modelId);
      }
      catch (AiRateLimitException $e) {
        $lastException = $e;

        if (!$shouldRetryRateLimit || $attempt >= $maxRetries) {
          throw $e;
        }

        $this->logger()->warning(
          'Rate limit hit on {provider}. Retrying in {delay}s (attempt {attempt}/{max})',
          [
            'provider' => "{$providerId}__{$modelId}",
            'delay' => $retryDelay,
            'attempt' => $attempt + 1,
            'max' => $maxRetries,
          ]
        );

        sleep($retryDelay);
        $attempt++;
      }
    }

    throw $lastException;
  }

  /**
   * Get ordered provider chain from configuration.
   */
  protected function getProviderChain(string $operation_type): array {
    $config = $this->configFactory->get('seo_audit.settings');
    $chain = $config->get('provider_fallback_chain') ?? [];

    if (empty($chain)) {
      // Fallback to global default
      $default = $this->aiProviderPluginManager
        ->getDefaultProviderForOperationType($operation_type);

      if (!empty($default['provider_id']) && !empty($default['model_id'])) {
        return [[
          'provider_id' => $default['provider_id'],
          'model_id' => $default['model_id'],
          'weight' => 0,
          'enabled' => TRUE,
        ]];
      }

      return [];
    }

    // Sort by weight (lower = higher priority)
    usort($chain, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    return $chain;
  }

  /**
   * Determine if we should continue trying providers on this error.
   */
  protected function shouldContinueOnError(\Exception $e): bool {
    // Don't continue on rate limit (already retried)
    if ($e instanceof AiRateLimitException) {
      return TRUE; // Try next provider
    }

    // Continue on quota exceeded, response errors, missing features
    if ($e instanceof AiQuotaException ||
        $e instanceof AiResponseErrorException ||
        $e instanceof AiMissingFeatureException ||
        $e instanceof AiBadRequestException) {
      return TRUE;
    }

    // Continue on most exceptions, but not auth failures
    if ($e instanceof AiAccessDeniedException) {
      return TRUE; // Try next provider (might have different auth)
    }

    // Default: continue to next provider
    return TRUE;
  }

  /**
   * Get logger channel.
   */
  protected function logger() {
    return $this->loggerFactory->get('seo_audit');
  }

  /**
   * Log successful provider usage.
   */
  protected function logSuccess(string $providerId, string $modelId, int $attempts): void {
    $config = $this->configFactory->get('seo_audit.settings');
    if (!$config->get('log_provider_fallback')) {
      return;
    }

    $this->logger()->info(
      'AI analysis completed with provider {provider} (attempts: {attempts})',
      [
        'provider' => "{$providerId}__{$modelId}",
        'attempts' => $attempts,
      ]
    );
  }

  /**
   * Log provider failure.
   */
  protected function logFailure(string $providerId, string $modelId, \Exception $e): void {
    $config = $this->configFactory->get('seo_audit.settings');
    if (!$config->get('log_provider_fallback')) {
      return;
    }

    $this->logger()->warning(
      'Provider {provider} failed: {error}. Trying next provider.',
      [
        'provider' => "{$providerId}__{$modelId}",
        'error' => $e->getMessage(),
        'exception_class' => get_class($e),
      ]
    );
  }

}
