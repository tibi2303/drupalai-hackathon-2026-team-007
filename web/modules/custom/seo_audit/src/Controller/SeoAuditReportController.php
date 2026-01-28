<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\seo_audit\Entity\SeoAuditResult;
use Drupal\seo_audit\Service\ScoringService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for SEO audit report pages.
 */
class SeoAuditReportController extends ControllerBase {

  public function __construct(
    protected ScoringService $scoringService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('seo_audit.scoring'),
    );
  }

  /**
   * Display a single audit report.
   */
  public function report(SeoAuditResult $seo_audit_result): array {
    $status = $seo_audit_result->get('status')->value;
    $config = $this->config('seo_audit.settings');

    // If not completed, show progress indicator with AJAX polling.
    if ($status !== 'completed' && $status !== 'failed') {
      return $this->buildProgressPage($seo_audit_result);
    }

    // Failed audit.
    if ($status === 'failed') {
      return $this->buildFailedPage($seo_audit_result);
    }

    // Completed audit - full report.
    return $this->buildCompletedReport($seo_audit_result, $config);
  }

  /**
   * Build progress page with AJAX polling.
   */
  protected function buildProgressPage(SeoAuditResult $result): array {
    $statusUrl = Url::fromRoute('seo_audit.status', [
      'seo_audit_result' => $result->id(),
    ])->toString();

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['seo-audit-progress'],
      ],
      '#cache' => ['max-age' => 0],
      'status' => [
        '#markup' => '<p>' . $this->t('Your audit is being processed. Please wait…') . '</p>',
      ],
      'noscript' => [
        '#markup' => '<noscript><meta http-equiv="refresh" content="10"></noscript>',
      ],
      '#attached' => [
        'library' => ['seo_audit/progress'],
        'drupalSettings' => [
          'seoAudit' => [
            'statusUrl' => $statusUrl,
          ],
        ],
      ],
    ];
  }

  /**
   * Build failed audit page.
   */
  protected function buildFailedPage(SeoAuditResult $result): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-failed']],
      'message' => [
        '#markup' => '<div class="messages messages--error">' . $this->t('Audit failed.') . '</div>',
      ],
      'error' => [
        '#markup' => '<p><strong>' . $this->t('Error:') . '</strong> ' . ($result->get('error_message')->value ?: $this->t('Unknown error')) . '</p>',
      ],
      'retry' => [
        '#type' => 'link',
        '#title' => $this->t('Try Again'),
        '#url' => Url::fromRoute('seo_audit.confirm_audit', [
          'node' => $result->get('node_id')->target_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
    ];
  }

  /**
   * Build completed report page.
   */
  protected function buildCompletedReport(SeoAuditResult $result, $config): array {
    $overallScore = (int) $result->get('overall_score')->value;
    $seoScore = (int) $result->get('seo_score')->value;
    $a11yScore = (int) $result->get('accessibility_score')->value;
    $contentScore = (int) $result->get('content_quality_score')->value;

    $overallGrade = $this->scoringService->getGrade($overallScore);
    $seoGrade = $this->scoringService->getGrade($seoScore);
    $a11yGrade = $this->scoringService->getGrade($a11yScore);
    $contentGrade = $this->scoringService->getGrade($contentScore);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-report']],
      '#attached' => [
        'library' => ['seo_audit/report'],
      ],
    ];

    // Score cards.
    $build['scores'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-scores']],
      'overall' => $this->buildScoreCard($this->t('Overall'), $overallScore, $overallGrade),
      'seo' => $this->buildScoreCard($this->t('SEO'), $seoScore, $seoGrade),
      'accessibility' => $this->buildScoreCard($this->t('Accessibility'), $a11yScore, $a11yGrade),
      'content' => $this->buildScoreCard($this->t('Content Quality'), $contentScore, $contentGrade),
    ];

    // Executive summary.
    $summary = $result->get('executive_summary')->value;
    if ($summary) {
      $build['summary'] = [
        '#type' => 'details',
        '#title' => $this->t('Executive Summary'),
        '#open' => TRUE,
        '#attributes' => ['class' => ['seo-audit-summary']],
        'content' => [
          '#markup' => '<p>' . htmlspecialchars($summary) . '</p>',
        ],
      ];
    }

    // Issues table.
    $issuesJson = $result->get('issues_json')->value;
    $issues = $issuesJson ? json_decode($issuesJson, TRUE) : [];

    if (!empty($issues)) {
      $rows = [];
      foreach ($issues as $issue) {
        $severityClass = 'seo-audit-severity--' . ($issue['severity'] ?? 'info');
        $rows[] = [
          ['data' => ucfirst($issue['severity'] ?? 'info'), 'class' => [$severityClass]],
          $issue['category'] ?? '',
          $issue['title'] ?? '',
          $issue['description'] ?? '',
          $issue['recommendation'] ?? '',
        ];
      }

      $build['issues'] = [
        '#type' => 'details',
        '#title' => $this->t('Issues (@count)', ['@count' => count($issues)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Severity'),
            $this->t('Category'),
            $this->t('Issue'),
            $this->t('Details'),
            $this->t('Recommendation'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No issues found.'),
          '#attributes' => ['class' => ['seo-audit-issues-table']],
        ],
      ];
    }

    // Disclaimers.
    $build['disclaimers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-disclaimers']],
      'accessibility' => [
        '#markup' => '<div class="messages messages--warning"><strong>' . $this->t('Accessibility Disclaimer:') . '</strong> ' . htmlspecialchars($config->get('accessibility_disclaimer') ?: '') . '</div>',
      ],
      'seo' => [
        '#markup' => '<div class="messages messages--warning"><strong>' . $this->t('SEO Disclaimer:') . '</strong> ' . htmlspecialchars($config->get('seo_disclaimer') ?: '') . '</div>',
      ],
    ];

    // Metadata.
    $build['meta'] = [
      '#type' => 'details',
      '#title' => $this->t('Audit Details'),
      '#open' => FALSE,
      'content' => [
        '#type' => 'table',
        '#rows' => [
          [$this->t('Audit ID'), $result->id()],
          [$this->t('Created'), date('Y-m-d H:i:s', (int) $result->get('created')->value)],
          [$this->t('Language'), $result->get('audited_langcode')->value],
          [$this->t('AI Provider Used'), $result->get('ai_provider_used')->value ?: $this->t('N/A')],
          [$this->t('Fallback Attempts'), $result->get('ai_fallback_attempts')->value ?: 1],
          [$this->t('AI Tokens Used'), $result->get('ai_tokens_used')->value ?: $this->t('N/A')],
          [$this->t('Critical Issues'), $result->get('critical_count')->value],
          [$this->t('Major Issues'), $result->get('major_count')->value],
        ],
      ],
    ];

    // Historical runs link.
    $nodeId = $result->get('node_id')->target_id;
    if ($nodeId) {
      $build['history'] = [
        '#type' => 'link',
        '#title' => $this->t('View all audits for this page'),
        '#url' => Url::fromRoute('seo_audit.node_reports', ['node' => $nodeId]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $build;
  }

  /**
   * Build a score card render array.
   */
  protected function buildScoreCard($label, int $score, array $grade): array {
    // Calculate the stroke-dashoffset for the circular progress ring.
    // Circle circumference = 2πr. For r=54, circumference = ~339.
    $circumference = 339;
    $offset = $circumference - (($score / 100) * $circumference);

    // SVG circular progress ring.
    $svg = '<svg class="seo-audit-score-ring" viewBox="0 0 120 120" aria-hidden="true">
      <circle class="seo-audit-score-ring__track" cx="60" cy="60" r="54" />
      <circle class="seo-audit-score-ring__progress" cx="60" cy="60" r="54"
        style="stroke-dashoffset: ' . $offset . '" />
      <text class="seo-audit-score-ring__text" x="60" y="60" text-anchor="middle"
        dominant-baseline="central">' . $score . '</text>
    </svg>';

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['seo-audit-score-card', 'seo-audit-score-card--' . strtolower($grade['color'])],
      ],
      'ring' => [
        '#markup' => $svg,
      ],
      'label' => [
        '#markup' => '<div class="seo-audit-score-label">' . $label . '</div>',
      ],
      'grade' => [
        '#markup' => '<div class="seo-audit-score-grade">' . $grade['grade'] . ' — ' . $grade['label'] . '</div>',
      ],
    ];
  }

  /**
   * Display historical audit reports for a node.
   */
  public function nodeReports(NodeInterface $node): array {
    $storage = $this->entityTypeManager()->getStorage('seo_audit_result');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('node_id', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();

    $results = $storage->loadMultiple($ids);

    if (empty($results)) {
      return [
        '#markup' => '<p>' . $this->t('No audits have been run for this content yet.') . '</p>',
        'run_audit' => [
          '#type' => 'link',
          '#title' => $this->t('Run SEO & Accessibility Audit'),
          '#url' => Url::fromRoute('seo_audit.confirm_audit', ['node' => $node->id()]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    $rows = [];
    foreach ($results as $result) {
      $status = $result->get('status')->value;
      $row = [
        date('Y-m-d H:i', (int) $result->get('created')->value),
        $result->get('audited_langcode')->value ?: '-',
        ucfirst($status),
      ];

      if ($status === 'completed') {
        $overall = (int) $result->get('overall_score')->value;
        $grade = $this->scoringService->getGrade($overall);
        $row[] = "$overall ({$grade['grade']})";
        $row[] = $result->get('seo_score')->value;
        $row[] = $result->get('accessibility_score')->value;
      }
      else {
        $row[] = '-';
        $row[] = '-';
        $row[] = '-';
      }

      $row[] = [
        'data' => [
          '#type' => 'link',
          '#title' => $this->t('View'),
          '#url' => Url::fromRoute('seo_audit.report', ['seo_audit_result' => $result->id()]),
        ],
      ];

      $rows[] = $row;
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Date'),
          $this->t('Language'),
          $this->t('Status'),
          $this->t('Overall'),
          $this->t('SEO'),
          $this->t('Accessibility'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['seo-audit-history']],
      ],
      'run_audit' => [
        '#type' => 'link',
        '#title' => $this->t('Run New Audit'),
        '#url' => Url::fromRoute('seo_audit.confirm_audit', ['node' => $node->id()]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];
  }

}
