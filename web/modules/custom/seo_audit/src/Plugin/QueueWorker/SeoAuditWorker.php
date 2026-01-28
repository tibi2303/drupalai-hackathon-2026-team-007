<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\seo_audit\Service\AccessibilityCheckerService;
use Drupal\seo_audit\Service\AuditOrchestrator;
use Drupal\seo_audit\Service\NodeHtmlRenderer;
use Drupal\seo_audit\Service\ScoringService;
use Drupal\seo_audit\Service\SeoCheckerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker that processes SEO and accessibility audits.
 *
 * @QueueWorker(
 *   id = "seo_audit_worker",
 *   title = @Translation("SEO Audit Worker"),
 *   cron = {"time" = 30}
 * )
 */
class SeoAuditWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected NodeHtmlRenderer $nodeHtmlRenderer,
    protected SeoCheckerService $seoChecker,
    protected AccessibilityCheckerService $accessibilityChecker,
    protected ScoringService $scoringService,
    protected AuditOrchestrator $auditOrchestrator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('logger.factory'),
      $container->get('seo_audit.node_html_renderer'),
      $container->get('seo_audit.seo_checker'),
      $container->get('seo_audit.accessibility_checker'),
      $container->get('seo_audit.scoring'),
      $container->get('seo_audit.audit_orchestrator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $logger = $this->loggerFactory->get('seo_audit');

    $auditResult = $this->entityTypeManager->getStorage('seo_audit_result')
      ->load($data['audit_result_id']);

    if (!$auditResult) {
      $logger->warning('Audit result entity @id not found.', ['@id' => $data['audit_result_id']]);
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')
      ->load($data['node_id']);

    if (!$node) {
      $auditResult->set('status', 'failed');
      $auditResult->set('error_message', 'Node not found.');
      $auditResult->save();
      $logger->warning('Node @id not found for audit.', ['@id' => $data['node_id']]);
      return;
    }

    // Use the correct translation.
    $langcode = $data['langcode'] ?? $node->language()->getId();
    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }

    try {
      // Phase 1: Deterministic checks.
      $auditResult->set('status', 'processing_deterministic');
      $auditResult->save();

      $logger->info('Starting deterministic analysis for node @nid.', ['@nid' => $node->id()]);

      $html = $this->nodeHtmlRenderer->render($node);
      $seoResults = $this->seoChecker->analyze($html, $node);
      $accessibilityResults = $this->accessibilityChecker->analyze($html);

      $auditResult->set('seo_results_raw', json_encode($seoResults));
      $auditResult->set('accessibility_results_raw', json_encode($accessibilityResults));

      // Phase 2: AI analysis (if enabled).
      $aiContentQualityScore = 0;
      $aiIssues = [];
      $executiveSummary = '';
      $aiTokensUsed = 0;

      try {
        $auditResult->set('status', 'processing_ai');
        $auditResult->save();

        $contentText = $this->nodeHtmlRenderer->extractText($html);

        // Extract meta title and description from HTML for AI context.
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);
        $titleNodes = $xpath->query('//title');
        $metaTitle = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
        $metaDescNodes = $xpath->query('//meta[@name="description"]');
        $metaDescription = $metaDescNodes->length > 0 ? trim($metaDescNodes->item(0)->getAttribute('content')) : '';

        $aiResult = $this->auditOrchestrator->runAiAnalysis(
          $contentText,
          $metaTitle,
          $metaDescription,
          $seoResults,
          $accessibilityResults,
        );

        $aiContentQualityScore = $aiResult['content_quality_score'];
        $aiIssues = $aiResult['issues'];
        $executiveSummary = $aiResult['executive_summary'];
        $aiTokensUsed = $aiResult['tokens_used'];

        $auditResult->set('ai_analysis_raw', json_encode($aiResult));
        $auditResult->set('executive_summary', $executiveSummary);
        $auditResult->set('ai_tokens_used', $aiTokensUsed);

        // Store provider information if available (from fallback service)
        if (isset($aiResult['provider_used'])) {
          $auditResult->set('ai_provider_used', $aiResult['provider_used']);
        }
        if (isset($aiResult['fallback_attempts'])) {
          $auditResult->set('ai_fallback_attempts', $aiResult['fallback_attempts']);
        }
      }
      catch (\Exception $e) {
        // AI failure is not fatal - continue with deterministic-only scores.
        $logger->warning('AI analysis failed for node @nid: @message. Continuing with deterministic-only results.', [
          '@nid' => $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }

      // Phase 3: Calculate scores.
      $scores = $this->scoringService->calculateScores(
        $seoResults,
        $accessibilityResults,
        $aiContentQualityScore,
      );

      $auditResult->set('seo_score', $scores['seo_score']);
      $auditResult->set('accessibility_score', $scores['accessibility_score']);
      $auditResult->set('content_quality_score', $scores['content_quality_score']);
      $auditResult->set('overall_score', $scores['overall_score']);

      // Merge and prioritize issues.
      $allIssues = $this->scoringService->mergeAndPrioritize(
        $seoResults,
        $accessibilityResults,
        $aiIssues,
      );
      $auditResult->set('issues_json', json_encode($allIssues));

      // Count severities.
      $severityCounts = $this->scoringService->countBySeverity(
        array_merge($seoResults, $accessibilityResults)
      );
      $auditResult->set('critical_count', $severityCounts['critical']);
      $auditResult->set('major_count', $severityCounts['major']);

      // Mark completed.
      $auditResult->set('status', 'completed');
      $auditResult->save();

      $logger->info('Audit completed for node @nid. Overall score: @score.', [
        '@nid' => $node->id(),
        '@score' => $scores['overall_score'],
      ]);
    }
    catch (\Exception $e) {
      $auditResult->set('status', 'failed');
      $auditResult->set('error_message', $e->getMessage());
      $auditResult->save();

      $logger->error('Audit failed for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
