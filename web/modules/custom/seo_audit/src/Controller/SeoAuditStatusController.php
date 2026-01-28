<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\seo_audit\Entity\SeoAuditResult;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON status endpoint for audit progress polling.
 */
class SeoAuditStatusController extends ControllerBase {

  /**
   * Return the current status of an audit as JSON.
   */
  public function status(SeoAuditResult $seo_audit_result): JsonResponse {
    $status = $seo_audit_result->get('status')->value;
    $data = [
      'id' => $seo_audit_result->id(),
      'status' => $status,
      'completed' => $status === 'completed' || $status === 'failed',
    ];

    if ($status === 'completed') {
      $data['overall_score'] = (int) $seo_audit_result->get('overall_score')->value;
      $data['seo_score'] = (int) $seo_audit_result->get('seo_score')->value;
      $data['accessibility_score'] = (int) $seo_audit_result->get('accessibility_score')->value;
    }

    if ($status === 'failed') {
      $data['error'] = $seo_audit_result->get('error_message')->value;
    }

    return new JsonResponse($data);
  }

}
