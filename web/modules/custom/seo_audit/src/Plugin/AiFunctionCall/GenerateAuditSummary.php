<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Generates a prioritized audit summary from deterministic scan results.
 */
#[FunctionCall(
  id: 'information_tools:seo_audit_generate_audit_summary',
  function_name: 'seo_audit_generate_audit_summary',
  name: 'Generate Audit Summary',
  description: 'Synthesizes SEO and accessibility check results into a prioritized issue list with recommendations and an executive summary.',
  group: 'information_tools',
  module_dependencies: ['seo_audit'],
  context_definitions: [
    'seo_results_json' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('SEO Results JSON'),
      description: new TranslatableMarkup('JSON-encoded SEO check results from deterministic scanners.'),
      required: TRUE,
    ),
    'accessibility_results_json' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Accessibility Results JSON'),
      description: new TranslatableMarkup('JSON-encoded accessibility check results from deterministic scanners.'),
      required: TRUE,
    ),
  ],
)]
class GenerateAuditSummary extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): string {
    $seoResultsJson = $this->getContextValue('seo_results_json');
    $a11yResultsJson = $this->getContextValue('accessibility_results_json');

    $seoResults = json_decode($seoResultsJson, TRUE) ?: [];
    $a11yResults = json_decode($a11yResultsJson, TRUE) ?: [];

    // Separate failures from passes.
    $issues = [];
    $passes = [];
    $severityOrder = ['critical' => 0, 'major' => 1, 'minor' => 2, 'info' => 3];

    foreach (array_merge($seoResults, $a11yResults) as $result) {
      if (($result['severity'] ?? 'pass') === 'pass') {
        $passes[] = $result;
      }
      else {
        $issues[] = $result;
      }
    }

    // Sort issues by severity.
    usort($issues, function ($a, $b) use ($severityOrder) {
      return ($severityOrder[$a['severity']] ?? 5) <=> ($severityOrder[$b['severity']] ?? 5);
    });

    // Calculate summary statistics.
    $totalChecks = count($seoResults) + count($a11yResults);
    $passedChecks = count($passes);
    $criticalCount = count(array_filter($issues, fn($i) => $i['severity'] === 'critical'));
    $majorCount = count(array_filter($issues, fn($i) => $i['severity'] === 'major'));
    $minorCount = count(array_filter($issues, fn($i) => $i['severity'] === 'minor'));

    // Build prioritized output.
    $prioritizedIssues = [];
    foreach ($issues as $issue) {
      $prioritizedIssues[] = [
        'severity' => $issue['severity'],
        'category' => $issue['category'] ?? 'general',
        'title' => $issue['title'],
        'description' => $issue['description'],
        'recommendation' => $issue['recommendation'] ?? '',
        'wcag' => $issue['wcag'] ?? NULL,
      ];
    }

    $output = json_encode([
      'summary_stats' => [
        'total_checks' => $totalChecks,
        'passed' => $passedChecks,
        'failed' => count($issues),
        'critical' => $criticalCount,
        'major' => $majorCount,
        'minor' => $minorCount,
      ],
      'prioritized_issues' => $prioritizedIssues,
    ], JSON_PRETTY_PRINT);

    $this->setOutput($output);
    return $output;
  }

}
