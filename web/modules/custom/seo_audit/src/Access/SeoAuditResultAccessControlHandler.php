<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the SEO Audit Result entity.
 */
class SeoAuditResultAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view seo audit reports'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'administer seo audit'),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'run seo audit');
  }

}
