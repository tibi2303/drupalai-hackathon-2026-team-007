<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\seo_audit\Service\ScoringService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard controller showing site-wide audit overview.
 */
class SeoAuditDashboardController extends ControllerBase {

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
   * Site-wide dashboard page.
   */
  public function dashboard(): array {
    $storage = $this->entityTypeManager()->getStorage('seo_audit_result');

    // Get the most recent completed audits, one per node.
    $allIds = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 'completed')
      ->sort('created', 'DESC')
      ->range(0, 200)
      ->execute();

    $results = $storage->loadMultiple($allIds);

    // Deduplicate: keep only the latest audit per node.
    $latestByNode = [];
    foreach ($results as $result) {
      $nodeId = $result->get('node_id')->target_id;
      if (!isset($latestByNode[$nodeId])) {
        $latestByNode[$nodeId] = $result;
      }
    }

    if (empty($latestByNode)) {
      return [
        '#markup' => '<p>' . $this->t('No completed audits found. Run audits from the content listing page using the "Run SEO & Accessibility Audit" operation.') . '</p>',
      ];
    }

    // Summary stats.
    $totalAudits = count($latestByNode);
    $avgOverall = 0;
    $avgSeo = 0;
    $avgA11y = 0;
    $totalCritical = 0;
    $totalMajor = 0;

    $rows = [];
    foreach ($latestByNode as $nodeId => $result) {
      $overall = (int) $result->get('overall_score')->value;
      $seo = (int) $result->get('seo_score')->value;
      $a11y = (int) $result->get('accessibility_score')->value;
      $critical = (int) $result->get('critical_count')->value;
      $major = (int) $result->get('major_count')->value;

      $avgOverall += $overall;
      $avgSeo += $seo;
      $avgA11y += $a11y;
      $totalCritical += $critical;
      $totalMajor += $major;

      $grade = $this->scoringService->getGrade($overall);
      $node = $result->get('node_id')->entity;
      $nodeTitle = $node ? $node->getTitle() : $this->t('(deleted)');

      $rows[] = [
        $node ? [
          'data' => [
            '#type' => 'link',
            '#title' => $nodeTitle,
            '#url' => $node->toUrl(),
          ],
        ] : (string) $nodeTitle,
        "$overall ({$grade['grade']})",
        $seo,
        $a11y,
        $critical,
        $major,
        date('Y-m-d', (int) $result->get('created')->value),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Report'),
            '#url' => Url::fromRoute('seo_audit.report', ['seo_audit_result' => $result->id()]),
          ],
        ],
      ];
    }

    $avgOverall = $totalAudits > 0 ? (int) round($avgOverall / $totalAudits) : 0;
    $avgSeo = $totalAudits > 0 ? (int) round($avgSeo / $totalAudits) : 0;
    $avgA11y = $totalAudits > 0 ? (int) round($avgA11y / $totalAudits) : 0;

    $build = [];

    // Summary cards.
    $overallGrade = $this->scoringService->getGrade($avgOverall);
    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['seo-audit-dashboard-summary']],
      'stats' => [
        '#markup' => '<div class="seo-audit-dashboard-stats">'
          . '<div><strong>' . $this->t('Pages Audited:') . '</strong> ' . $totalAudits . '</div>'
          . '<div><strong>' . $this->t('Avg Overall:') . '</strong> ' . $avgOverall . ' (' . $overallGrade['grade'] . ')</div>'
          . '<div><strong>' . $this->t('Avg SEO:') . '</strong> ' . $avgSeo . '</div>'
          . '<div><strong>' . $this->t('Avg Accessibility:') . '</strong> ' . $avgA11y . '</div>'
          . '<div><strong>' . $this->t('Total Critical:') . '</strong> ' . $totalCritical . '</div>'
          . '<div><strong>' . $this->t('Total Major:') . '</strong> ' . $totalMajor . '</div>'
          . '</div>',
      ],
    ];

    // Results table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Page'),
        $this->t('Overall'),
        $this->t('SEO'),
        $this->t('Accessibility'),
        $this->t('Critical'),
        $this->t('Major'),
        $this->t('Last Audit'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['seo-audit-dashboard-table']],
    ];

    return $build;
  }

}
