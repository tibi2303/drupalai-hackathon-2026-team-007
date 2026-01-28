<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Batch;

/**
 * Batch operations for SEO audit processing.
 */
class SeoAuditBatch {

  /**
   * Batch operation: Run deterministic SEO and accessibility checks.
   */
  public static function processDeterministic(int $audit_result_id, int $node_id, string $langcode, array &$context): void {
    $logger = \Drupal::logger('seo_audit');

    $auditResult = \Drupal::entityTypeManager()
      ->getStorage('seo_audit_result')
      ->load($audit_result_id);

    if (!$auditResult) {
      $context['results']['error'] = 'Audit result entity not found.';
      $logger->warning('Audit result entity @id not found.', ['@id' => $audit_result_id]);
      return;
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
    if (!$node) {
      $auditResult->set('status', 'failed');
      $auditResult->set('error_message', 'Node not found.');
      $auditResult->save();
      $context['results']['error'] = 'Node not found.';
      $logger->warning('Node @id not found for audit.', ['@id' => $node_id]);
      return;
    }

    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }

    $auditResult->set('status', 'processing_deterministic');
    $auditResult->save();

    $context['message'] = t('Running SEO and accessibility checks…');

    /** @var \Drupal\seo_audit\Service\NodeHtmlRenderer $htmlRenderer */
    $htmlRenderer = \Drupal::service('seo_audit.node_html_renderer');
    $html = $htmlRenderer->render($node);

    /** @var \Drupal\seo_audit\Service\SeoCheckerService $seoChecker */
    $seoChecker = \Drupal::service('seo_audit.seo_checker');
    $seoResults = $seoChecker->analyze($html, $node);

    /** @var \Drupal\seo_audit\Service\AccessibilityCheckerService $accessibilityChecker */
    $accessibilityChecker = \Drupal::service('seo_audit.accessibility_checker');
    $accessibilityResults = $accessibilityChecker->analyze($html);

    $auditResult->set('seo_results_raw', json_encode($seoResults));
    $auditResult->set('accessibility_results_raw', json_encode($accessibilityResults));
    $auditResult->save();

    $context['results']['audit_result_id'] = $audit_result_id;
    $context['results']['seo_results'] = $seoResults;
    $context['results']['accessibility_results'] = $accessibilityResults;
    $context['results']['html'] = $html;

    $logger->info('Deterministic analysis completed for node @nid.', ['@nid' => $node_id]);
  }

  /**
   * Batch operation: Run AI analysis.
   */
  public static function processAi(int $audit_result_id, array &$context): void {
    $logger = \Drupal::logger('seo_audit');

    if (!empty($context['results']['error'])) {
      return;
    }

    $auditResult = \Drupal::entityTypeManager()
      ->getStorage('seo_audit_result')
      ->load($audit_result_id);

    if (!$auditResult) {
      return;
    }

    $auditResult->set('status', 'processing_ai');
    $auditResult->save();

    $context['message'] = t('Running AI analysis…');

    $html = $context['results']['html'] ?? '';
    $seoResults = $context['results']['seo_results'] ?? [];
    $accessibilityResults = $context['results']['accessibility_results'] ?? [];

    $aiContentQualityScore = 0;
    $aiIssues = [];

    try {
      /** @var \Drupal\seo_audit\Service\NodeHtmlRenderer $htmlRenderer */
      $htmlRenderer = \Drupal::service('seo_audit.node_html_renderer');
      $contentText = $htmlRenderer->extractText($html);

      $dom = new \DOMDocument();
      @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
      $xpath = new \DOMXPath($dom);
      $titleNodes = $xpath->query('//title');
      $metaTitle = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
      $metaDescNodes = $xpath->query('//meta[@name="description"]');
      $metaDescription = $metaDescNodes->length > 0 ? trim($metaDescNodes->item(0)->getAttribute('content')) : '';

      /** @var \Drupal\seo_audit\Service\AuditOrchestrator $orchestrator */
      $orchestrator = \Drupal::service('seo_audit.audit_orchestrator');
      $aiResult = $orchestrator->runAiAnalysis(
        $contentText,
        $metaTitle,
        $metaDescription,
        $seoResults,
        $accessibilityResults,
      );

      $aiContentQualityScore = $aiResult['content_quality_score'];
      $aiIssues = $aiResult['issues'];

      $auditResult->set('ai_analysis_raw', json_encode($aiResult));
      $auditResult->set('executive_summary', $aiResult['executive_summary']);
      $auditResult->set('ai_tokens_used', $aiResult['tokens_used']);
      $auditResult->save();
    }
    catch (\Exception $e) {
      $logger->warning('AI analysis failed for audit @id: @message. Continuing with deterministic-only results.', [
        '@id' => $audit_result_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $context['results']['ai_content_quality_score'] = $aiContentQualityScore;
    $context['results']['ai_issues'] = $aiIssues;
  }

  /**
   * Batch operation: Calculate scores and finalize.
   */
  public static function processScoring(int $audit_result_id, array &$context): void {
    $logger = \Drupal::logger('seo_audit');

    if (!empty($context['results']['error'])) {
      return;
    }

    $auditResult = \Drupal::entityTypeManager()
      ->getStorage('seo_audit_result')
      ->load($audit_result_id);

    if (!$auditResult) {
      return;
    }

    $context['message'] = t('Calculating scores…');

    $seoResults = $context['results']['seo_results'] ?? [];
    $accessibilityResults = $context['results']['accessibility_results'] ?? [];
    $aiContentQualityScore = $context['results']['ai_content_quality_score'] ?? 0;
    $aiIssues = $context['results']['ai_issues'] ?? [];

    try {
      /** @var \Drupal\seo_audit\Service\ScoringService $scoringService */
      $scoringService = \Drupal::service('seo_audit.scoring');

      $scores = $scoringService->calculateScores(
        $seoResults,
        $accessibilityResults,
        $aiContentQualityScore,
      );

      $auditResult->set('seo_score', $scores['seo_score']);
      $auditResult->set('accessibility_score', $scores['accessibility_score']);
      $auditResult->set('content_quality_score', $scores['content_quality_score']);
      $auditResult->set('overall_score', $scores['overall_score']);

      $allIssues = $scoringService->mergeAndPrioritize(
        $seoResults,
        $accessibilityResults,
        $aiIssues,
      );
      $auditResult->set('issues_json', json_encode($allIssues));

      $severityCounts = $scoringService->countBySeverity(
        array_merge($seoResults, $accessibilityResults)
      );
      $auditResult->set('critical_count', $severityCounts['critical']);
      $auditResult->set('major_count', $severityCounts['major']);

      $auditResult->set('status', 'completed');
      $auditResult->save();

      $logger->info('Audit completed for audit @id. Overall score: @score.', [
        '@id' => $audit_result_id,
        '@score' => $scores['overall_score'],
      ]);
    }
    catch (\Exception $e) {
      $auditResult->set('status', 'failed');
      $auditResult->set('error_message', $e->getMessage());
      $auditResult->save();

      $context['results']['error'] = $e->getMessage();

      $logger->error('Scoring failed for audit @id: @message', [
        '@id' => $audit_result_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function finished(bool $success, array $results, array $operations, string $elapsed): void {
    if (!$success || !empty($results['error'])) {
      \Drupal::messenger()->addError(t('The audit encountered an error: @error', [
        '@error' => $results['error'] ?? t('Unknown error'),
      ]));
      return;
    }

    \Drupal::messenger()->addStatus(t('SEO & Accessibility audit completed successfully.'));

    // Force redirect to the report page.
    $auditResultId = $results['audit_result_id'] ?? NULL;
    if ($auditResultId) {
      $url = \Drupal\Core\Url::fromRoute('seo_audit.report', [
        'seo_audit_result' => $auditResultId,
      ]);
      $batch = &batch_get();
      $batch['redirect'] = $url;
    }
  }

}
