<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for queuing an SEO audit.
 */
class SeoAuditConfirmForm extends ConfirmFormBase {

  protected NodeInterface $node;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'seo_audit_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Queue SEO & Accessibility audit for %title?', [
      '%title' => $this->node->getTitle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will queue an SEO and accessibility audit for processing. The audit will run deterministic checks and optionally use AI analysis. Results will be available on the report page once processing is complete.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->node->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Queue Audit');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $this->node = $node;

    $form = parent::buildForm($form, $form_state);

    // Check for existing pending/processing audits.
    $existing = $this->entityTypeManager->getStorage('seo_audit_result')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('node_id', $this->node->id())
      ->condition('status', ['queued', 'processing_deterministic', 'processing_ai'], 'IN')
      ->count()
      ->execute();

    if ($existing > 0) {
      $this->messenger()->addWarning($this->t('An audit is already queued or in progress for this content.'));
    }

    // Language selector for translated content.
    $languages = $this->node->getTranslationLanguages();
    if (count($languages) > 1) {
      $options = [];
      foreach ($languages as $langcode => $language) {
        $options[$langcode] = $language->getName();
      }
      $form['audit_langcode'] = [
        '#type' => 'select',
        '#title' => $this->t('Language to audit'),
        '#options' => $options,
        '#default_value' => $this->node->language()->getId(),
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $langcode = $form_state->getValue('audit_langcode') ?: $this->node->language()->getId();
    $now = \Drupal::time()->getRequestTime();
    $dateStr = date('Y-m-d H:i', $now);

    // Create audit result entity.
    $auditResult = $this->entityTypeManager->getStorage('seo_audit_result')->create([
      'label' => "Audit — {$this->node->getTitle()} — {$dateStr}",
      'node_id' => $this->node->id(),
      'audited_langcode' => $langcode,
      'status' => 'queued',
      'initiated_by' => $this->currentUser->id(),
    ]);
    $auditResult->save();

    // Process the audit immediately via Batch API.
    $batch = [
      'title' => $this->t('Running SEO & Accessibility Audit for %title', [
        '%title' => $this->node->getTitle(),
      ]),
      'operations' => [
        [
          '\Drupal\seo_audit\Batch\SeoAuditBatch::processDeterministic',
          [(int) $auditResult->id(), (int) $this->node->id(), $langcode],
        ],
        [
          '\Drupal\seo_audit\Batch\SeoAuditBatch::processAi',
          [(int) $auditResult->id()],
        ],
        [
          '\Drupal\seo_audit\Batch\SeoAuditBatch::processScoring',
          [(int) $auditResult->id()],
        ],
      ],
      'finished' => '\Drupal\seo_audit\Batch\SeoAuditBatch::finished',
    ];
    batch_set($batch);

    $form_state->setRedirect('seo_audit.report', [
      'seo_audit_result' => $auditResult->id(),
    ]);
  }

}
