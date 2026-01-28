<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Calculates audit scores from check results.
 */
class ScoringService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Calculate all scores from check results.
   *
   * @param array $seoResults
   *   Array of SEO check results.
   * @param array $accessibilityResults
   *   Array of accessibility check results.
   * @param int $aiContentQualityScore
   *   AI-derived content quality score (0-100).
   *
   * @return array
   *   Array with keys: seo_score, accessibility_score, content_quality_score, overall_score.
   */
  public function calculateScores(array $seoResults, array $accessibilityResults, int $aiContentQualityScore = 0): array {
    $config = $this->configFactory->get('seo_audit.settings');

    $seoDetRatio = (float) $config->get('seo_deterministic_ratio') ?: 0.7;
    $seoAiRatio = (float) $config->get('seo_ai_ratio') ?: 0.3;
    $seoWeight = (float) $config->get('seo_weight') ?: 0.5;
    $a11yWeight = (float) $config->get('accessibility_weight') ?: 0.3;
    $contentWeight = (float) $config->get('content_quality_weight') ?: 0.2;

    // SEO Score.
    $seoEarned = 0;
    $seoMax = 0;
    foreach ($seoResults as $result) {
      $seoMax += $result['maxPoints'];
      $seoEarned += $result['earnedPoints'];
    }
    $detSeoScore = $seoMax > 0 ? ($seoEarned / $seoMax) * 100 : 0;
    $seoScore = (int) round(
      ($detSeoScore * $seoDetRatio) + ($aiContentQualityScore * $seoAiRatio)
    );

    // Accessibility Score.
    $a11yEarned = 0;
    $a11yMax = 0;
    foreach ($accessibilityResults as $result) {
      $a11yMax += $result['maxPoints'];
      $a11yEarned += $result['earnedPoints'];
    }
    $accessibilityScore = $a11yMax > 0 ? (int) round(($a11yEarned / $a11yMax) * 100) : 0;

    // Overall Score.
    $overallScore = (int) round(
      ($seoScore * $seoWeight) + ($accessibilityScore * $a11yWeight) + ($aiContentQualityScore * $contentWeight)
    );

    return [
      'seo_score' => min(100, max(0, $seoScore)),
      'accessibility_score' => min(100, max(0, $accessibilityScore)),
      'content_quality_score' => min(100, max(0, $aiContentQualityScore)),
      'overall_score' => min(100, max(0, $overallScore)),
    ];
  }

  /**
   * Count issues by severity.
   *
   * @param array $results
   *   Combined check results.
   *
   * @return array
   *   Array with keys: critical, major, minor, info.
   */
  public function countBySeverity(array $results): array {
    $counts = ['critical' => 0, 'major' => 0, 'minor' => 0, 'info' => 0];
    foreach ($results as $result) {
      if (isset($counts[$result['severity']])) {
        $counts[$result['severity']]++;
      }
    }
    return $counts;
  }

  /**
   * Get the grade for a score.
   *
   * @param int $score
   *   The score (0-100).
   *
   * @return array
   *   Array with keys: grade, label, color.
   */
  public function getGrade(int $score): array {
    return match (TRUE) {
      $score >= 90 => ['grade' => 'A', 'label' => 'Excellent', 'color' => 'green'],
      $score >= 70 => ['grade' => 'B', 'label' => 'Good', 'color' => 'lightgreen'],
      $score >= 50 => ['grade' => 'C', 'label' => 'Needs Improvement', 'color' => 'yellow'],
      $score >= 30 => ['grade' => 'D', 'label' => 'Poor', 'color' => 'orange'],
      default => ['grade' => 'F', 'label' => 'Critical', 'color' => 'red'],
    };
  }

  /**
   * Merge and prioritize issues from all check results.
   *
   * @param array $seoResults
   *   SEO check results.
   * @param array $accessibilityResults
   *   Accessibility check results.
   * @param array $aiIssues
   *   AI-identified issues.
   *
   * @return array
   *   Merged and sorted issue list.
   */
  public function mergeAndPrioritize(array $seoResults, array $accessibilityResults, array $aiIssues = []): array {
    $severityOrder = ['critical' => 0, 'major' => 1, 'minor' => 2, 'info' => 3, 'pass' => 4];
    $allIssues = [];

    foreach (array_merge($seoResults, $accessibilityResults) as $result) {
      if ($result['severity'] !== 'pass') {
        $allIssues[] = $result;
      }
    }

    foreach ($aiIssues as $issue) {
      $allIssues[] = $issue;
    }

    usort($allIssues, function ($a, $b) use ($severityOrder) {
      $aOrder = $severityOrder[$a['severity']] ?? 5;
      $bOrder = $severityOrder[$b['severity']] ?? 5;
      return $aOrder <=> $bOrder;
    });

    return $allIssues;
  }

}
