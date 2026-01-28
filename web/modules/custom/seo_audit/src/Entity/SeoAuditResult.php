<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the SEO Audit Result entity.
 *
 * @ContentEntityType(
 *   id = "seo_audit_result",
 *   label = @Translation("SEO Audit Result"),
 *   label_collection = @Translation("SEO Audit Results"),
 *   label_singular = @Translation("SEO audit result"),
 *   label_plural = @Translation("SEO audit results"),
 *   label_count = @PluralTranslation(
 *     singular = "@count SEO audit result",
 *     plural = "@count SEO audit results",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\seo_audit\Access\SeoAuditResultAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "seo_audit_result",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 *   links = {
 *     "canonical" = "/admin/seo-audit/report/{seo_audit_result}",
 *   },
 * )
 */
final class SeoAuditResult extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Auto-generated label for this audit result.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
      ]);

    $fields['node_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Audited Node'))
      ->setDescription(t('The node that was audited.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node');

    $fields['audited_langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Audited Language'))
      ->setDescription(t('The language of the content that was audited.'));

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('queued')
      ->setSettings([
        'allowed_values' => [
          'queued' => 'Queued',
          'processing_deterministic' => 'Processing (Deterministic)',
          'processing_ai' => 'Processing (AI)',
          'completed' => 'Completed',
          'failed' => 'Failed',
        ],
      ]);

    $fields['seo_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('SEO Score'))
      ->setDescription(t('SEO score from 0 to 100.'))
      ->setDefaultValue(0)
      ->setSettings([
        'min' => 0,
        'max' => 100,
      ]);

    $fields['accessibility_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Accessibility Score'))
      ->setDescription(t('Accessibility score from 0 to 100.'))
      ->setDefaultValue(0)
      ->setSettings([
        'min' => 0,
        'max' => 100,
      ]);

    $fields['overall_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Overall Score'))
      ->setDescription(t('Weighted overall score from 0 to 100.'))
      ->setDefaultValue(0)
      ->setSettings([
        'min' => 0,
        'max' => 100,
      ]);

    $fields['content_quality_score'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Content Quality Score'))
      ->setDescription(t('AI-derived content quality score from 0 to 100.'))
      ->setDefaultValue(0)
      ->setSettings([
        'min' => 0,
        'max' => 100,
      ]);

    $fields['seo_results_raw'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('SEO Results (Raw)'))
      ->setDescription(t('JSON-encoded deterministic SEO check results.'));

    $fields['accessibility_results_raw'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Accessibility Results (Raw)'))
      ->setDescription(t('JSON-encoded deterministic accessibility check results.'));

    $fields['ai_analysis_raw'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('AI Analysis (Raw)'))
      ->setDescription(t('JSON-encoded full AI response.'));

    $fields['executive_summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Executive Summary'))
      ->setDescription(t('AI-generated narrative summary.'));

    $fields['issues_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Issues (JSON)'))
      ->setDescription(t('JSON-encoded merged and prioritised issue list.'));

    $fields['critical_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Critical Issues'))
      ->setDescription(t('Number of critical issues found.'))
      ->setDefaultValue(0);

    $fields['major_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Major Issues'))
      ->setDescription(t('Number of major issues found.'))
      ->setDefaultValue(0);

    $fields['ai_tokens_used'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('AI Tokens Used'))
      ->setDescription(t('Number of AI tokens consumed.'))
      ->setDefaultValue(0);

    $fields['initiated_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Initiated By'))
      ->setDescription(t('The user who triggered the audit.'))
      ->setSetting('target_type', 'user');

    $fields['error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Error Message'))
      ->setDescription(t('Failure details if status is failed.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the audit was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the audit was last updated.'));

    return $fields;
  }

}
