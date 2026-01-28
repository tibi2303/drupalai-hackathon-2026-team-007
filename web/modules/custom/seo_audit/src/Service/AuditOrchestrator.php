<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Orchestrates AI analysis for SEO audits.
 */
class AuditOrchestrator {

  public function __construct(
    protected AiProviderPluginManager $aiProviderPluginManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected AiProviderFallbackService $fallbackService,
  ) {}

  /**
   * Run AI analysis on content with deterministic results as context.
   *
   * @param string $contentText
   *   Plain text of the page content.
   * @param string $metaTitle
   *   The page meta title.
   * @param string $metaDescription
   *   The page meta description.
   * @param array $seoResults
   *   Deterministic SEO check results.
   * @param array $accessibilityResults
   *   Deterministic accessibility check results.
   *
   * @return array
   *   Array with keys: content_quality_score, issues, executive_summary, tokens_used.
   *
   * @throws \Exception
   *   If AI provider is not configured or fails.
   */
  public function runAiAnalysis(
    string $contentText,
    string $metaTitle,
    string $metaDescription,
    array $seoResults,
    array $accessibilityResults,
  ): array {
    $config = $this->configFactory->get('seo_audit.settings');

    if (!$config->get('ai_enabled')) {
      return $this->getEmptyResult();
    }

    // Check if fallback is enabled
    $fallbackEnabled = $config->get('provider_fallback_enabled');

    if ($fallbackEnabled) {
      return $this->runWithFallback(
        $contentText,
        $metaTitle,
        $metaDescription,
        $seoResults,
        $accessibilityResults
      );
    }

    // Original single-provider logic (backward compatibility)
    return $this->runSingleProvider(
      $contentText,
      $metaTitle,
      $metaDescription,
      $seoResults,
      $accessibilityResults
    );
  }

  /**
   * Run AI analysis with provider fallback.
   */
  protected function runWithFallback(
    string $contentText,
    string $metaTitle,
    string $metaDescription,
    array $seoResults,
    array $accessibilityResults
  ): array {
    $result = $this->fallbackService->executeWithFallback(
      function($providerId, $modelId) use (
        $contentText,
        $metaTitle,
        $metaDescription,
        $seoResults,
        $accessibilityResults
      ) {
        return $this->executeAiAnalysis(
          $providerId,
          $modelId,
          $contentText,
          $metaTitle,
          $metaDescription,
          $seoResults,
          $accessibilityResults
        );
      }
    );

    if ($result['success']) {
      return array_merge($result['data'], [
        'provider_used' => $result['provider_used'],
        'fallback_attempts' => $result['attempts'],
      ]);
    }

    // All providers failed
    $this->loggerFactory->get('seo_audit')->error(
      'All AI providers failed after {attempts} attempts. Last error: {error}',
      [
        'attempts' => $result['attempts'],
        'error' => $result['last_error'],
      ]
    );

    return $this->getEmptyResult();
  }

  /**
   * Run AI analysis with single provider (original logic).
   */
  protected function runSingleProvider(
    string $contentText,
    string $metaTitle,
    string $metaDescription,
    array $seoResults,
    array $accessibilityResults
  ): array {
    $default = $this->aiProviderPluginManager->getDefaultProviderForOperationType('chat_with_complex_json');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $this->loggerFactory->get('seo_audit')->warning('No AI provider configured for chat_with_complex_json. Running deterministic-only audit.');
      return $this->getEmptyResult();
    }

    try {
      return $this->executeAiAnalysis(
        $default['provider_id'],
        $default['model_id'],
        $contentText,
        $metaTitle,
        $metaDescription,
        $seoResults,
        $accessibilityResults
      );
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('seo_audit')->error('AI analysis failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Execute AI analysis with specific provider and model.
   */
  protected function executeAiAnalysis(
    string $providerId,
    string $modelId,
    string $contentText,
    string $metaTitle,
    string $metaDescription,
    array $seoResults,
    array $accessibilityResults
  ): array {
    $config = $this->configFactory->get('seo_audit.settings');
    $maxContentLength = (int) $config->get('max_content_length_for_ai') ?: 50000;

    if (mb_strlen($contentText) > $maxContentLength) {
      $contentText = mb_substr($contentText, 0, $maxContentLength);
    }

    $provider = $this->aiProviderPluginManager->createInstance($providerId);

    $systemPrompt = $this->buildSystemPrompt();
    $userMessage = $this->buildUserMessage(
      $contentText,
      $metaTitle,
      $metaDescription,
      $seoResults,
      $accessibilityResults
    );

    $chatMessage = new ChatMessage('user', $userMessage);
    $input = new ChatInput([$chatMessage]);
    $input->setSystemPrompt($systemPrompt);

    $maxTokens = (int) $config->get('max_tokens_per_audit') ?: 4000;
    $provider->setConfiguration(['max_tokens' => $maxTokens]);

    $jsonSchema = $this->getOutputSchema();
    $input->setChatStructuredJsonSchema($jsonSchema);

    $response = $provider->chat($input, $modelId, ['seo_audit']);
    $responseText = $response->getNormalized()->getText();
    $decoded = json_decode($responseText, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException('AI response was not valid JSON: ' . json_last_error_msg());
    }

    return [
      'content_quality_score' => (int) ($decoded['content_quality_score'] ?? 0),
      'issues' => $decoded['issues'] ?? [],
      'executive_summary' => $decoded['executive_summary'] ?? '',
      'keyword_analysis' => $decoded['keyword_analysis'] ?? '',
      'readability_score' => (int) ($decoded['readability_score'] ?? 0),
      'tokens_used' => mb_strlen($userMessage) + mb_strlen($responseText),
    ];
  }

  /**
   * Build the system prompt for AI analysis.
   */
  protected function buildSystemPrompt(): string {
    return <<<'PROMPT'
You are an SEO and web accessibility analyst. You receive:
1. Deterministic check results (JSON) from automated scanners
2. Plain text of the page content

Your tasks:
- Assess content quality, readability, and keyword usage
- Synthesise deterministic results into a prioritised issue list
- Generate an executive summary (3-5 sentences)
- Provide one actionable recommendation per issue

HARD CONSTRAINTS:
- You are READ-ONLY. Never suggest automatic content changes.
- Frame accessibility findings as "automated check results", never as compliance/non-compliance.
- Do not include PII from content in your output.
- Keep total output under 2000 tokens.
- Return valid JSON matching the provided schema.
PROMPT;
  }

  /**
   * Build the user message with context.
   */
  protected function buildUserMessage(
    string $contentText,
    string $metaTitle,
    string $metaDescription,
    array $seoResults,
    array $accessibilityResults,
  ): string {
    $seoJson = json_encode($seoResults, JSON_PRETTY_PRINT);
    $a11yJson = json_encode($accessibilityResults, JSON_PRETTY_PRINT);

    return <<<MSG
## Page Metadata
- Title: {$metaTitle}
- Meta Description: {$metaDescription}

## SEO Check Results
{$seoJson}

## Accessibility Check Results
{$a11yJson}

## Page Content (plain text)
{$contentText}

Please analyze the above and return a JSON response with: content_quality_score (0-100), readability_score (0-100), keyword_analysis (string), executive_summary (3-5 sentences), and issues (array of {severity, title, description, recommendation}).
MSG;
  }

  /**
   * Get the JSON schema for AI output.
   */
  protected function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'content_quality_score' => [
          'type' => 'integer',
          'description' => 'Content quality score from 0 to 100',
        ],
        'readability_score' => [
          'type' => 'integer',
          'description' => 'Readability score from 0 to 100',
        ],
        'keyword_analysis' => [
          'type' => 'string',
          'description' => 'Analysis of keyword usage and density',
        ],
        'executive_summary' => [
          'type' => 'string',
          'description' => 'Executive summary in 3-5 sentences',
        ],
        'issues' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'severity' => [
                'type' => 'string',
                'enum' => ['critical', 'major', 'minor', 'info'],
              ],
              'title' => ['type' => 'string'],
              'description' => ['type' => 'string'],
              'recommendation' => ['type' => 'string'],
            ],
          ],
        ],
      ],
      'required' => ['content_quality_score', 'executive_summary', 'issues'],
    ];
  }

  /**
   * Return empty result when AI is unavailable.
   */
  protected function getEmptyResult(): array {
    return [
      'content_quality_score' => 0,
      'issues' => [],
      'executive_summary' => '',
      'keyword_analysis' => '',
      'readability_score' => 0,
      'tokens_used' => 0,
    ];
  }

}
