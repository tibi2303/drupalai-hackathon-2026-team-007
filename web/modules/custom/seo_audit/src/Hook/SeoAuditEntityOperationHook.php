<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Adds SEO audit operation to node entity operations.
 */
class SeoAuditEntityOperationHook {

  use StringTranslationTrait;

  public function __construct(
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Implements hook_entity_operation().
   */
  #[Hook('entity_operation')]
  public function addSeoAuditOperation(EntityInterface $entity): array {
    if (!$entity instanceof NodeInterface) {
      return [];
    }

    if (!$this->currentUser->hasPermission('run seo audit')) {
      return [];
    }

    return [
      'seo_audit' => [
        'title' => $this->t('Run SEO & Accessibility Audit'),
        'url' => Url::fromRoute('seo_audit.confirm_audit', [
          'node' => $entity->id(),
        ]),
        'weight' => 50,
      ],
    ];
  }

}
