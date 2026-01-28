<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the SEO Audit module.
 */
class SeoAuditSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['seo_audit.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'seo_audit_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('seo_audit.settings');

    $form['ai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Settings'),
      '#open' => TRUE,
    ];

    $form['ai_settings']['ai_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI analysis'),
      '#description' => $this->t('When disabled, only deterministic checks will run. Enable to use AI for content quality analysis and summarization.'),
      '#default_value' => $config->get('ai_enabled'),
    ];

    $form['ai_settings']['max_tokens_per_audit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum tokens per audit'),
      '#description' => $this->t('Limits the AI token usage per audit request.'),
      '#default_value' => $config->get('max_tokens_per_audit'),
      '#min' => 500,
      '#max' => 16000,
    ];

    $form['ai_settings']['max_audits_per_day'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum audits per day'),
      '#description' => $this->t('Limits the total number of AI-powered audits per day.'),
      '#default_value' => $config->get('max_audits_per_day'),
      '#min' => 1,
      '#max' => 10000,
    ];

    $form['ai_settings']['max_content_length_for_ai'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum content length for AI (characters)'),
      '#description' => $this->t('Content longer than this will be truncated before sending to the AI provider.'),
      '#default_value' => $config->get('max_content_length_for_ai'),
      '#min' => 1000,
      '#max' => 200000,
    ];

    $form['scoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Scoring Weights'),
      '#open' => TRUE,
    ];

    $form['scoring']['seo_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('SEO weight in overall score'),
      '#default_value' => $config->get('seo_weight'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['scoring']['accessibility_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Accessibility weight in overall score'),
      '#default_value' => $config->get('accessibility_weight'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['scoring']['content_quality_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Content quality weight in overall score'),
      '#default_value' => $config->get('content_quality_weight'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['scoring']['seo_deterministic_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('Deterministic checks ratio in SEO score'),
      '#default_value' => $config->get('seo_deterministic_ratio'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['scoring']['seo_ai_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('AI analysis ratio in SEO score'),
      '#default_value' => $config->get('seo_ai_ratio'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
    ];

    $form['disclaimers'] = [
      '#type' => 'details',
      '#title' => $this->t('Disclaimers'),
      '#open' => FALSE,
    ];

    $form['disclaimers']['accessibility_disclaimer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Accessibility disclaimer'),
      '#description' => $this->t('Shown on every audit report. Clarifies that automated checks do not constitute WCAG conformance.'),
      '#default_value' => $config->get('accessibility_disclaimer'),
      '#rows' => 3,
    ];

    $form['disclaimers']['seo_disclaimer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SEO disclaimer'),
      '#description' => $this->t('Shown on every audit report. Clarifies that scores do not represent search engine rankings.'),
      '#default_value' => $config->get('seo_disclaimer'),
      '#rows' => 3,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $seoWeight = (float) $form_state->getValue('seo_weight');
    $a11yWeight = (float) $form_state->getValue('accessibility_weight');
    $contentWeight = (float) $form_state->getValue('content_quality_weight');
    $total = $seoWeight + $a11yWeight + $contentWeight;

    if (abs($total - 1.0) > 0.01) {
      $form_state->setErrorByName('seo_weight', $this->t('Overall score weights must sum to 1.0 (currently @total).', [
        '@total' => round($total, 2),
      ]));
    }

    $detRatio = (float) $form_state->getValue('seo_deterministic_ratio');
    $aiRatio = (float) $form_state->getValue('seo_ai_ratio');
    $seoTotal = $detRatio + $aiRatio;

    if (abs($seoTotal - 1.0) > 0.01) {
      $form_state->setErrorByName('seo_deterministic_ratio', $this->t('SEO score ratios must sum to 1.0 (currently @total).', [
        '@total' => round($seoTotal, 2),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('seo_audit.settings')
      ->set('ai_enabled', (bool) $form_state->getValue('ai_enabled'))
      ->set('max_tokens_per_audit', (int) $form_state->getValue('max_tokens_per_audit'))
      ->set('max_audits_per_day', (int) $form_state->getValue('max_audits_per_day'))
      ->set('max_content_length_for_ai', (int) $form_state->getValue('max_content_length_for_ai'))
      ->set('seo_weight', (float) $form_state->getValue('seo_weight'))
      ->set('accessibility_weight', (float) $form_state->getValue('accessibility_weight'))
      ->set('content_quality_weight', (float) $form_state->getValue('content_quality_weight'))
      ->set('seo_deterministic_ratio', (float) $form_state->getValue('seo_deterministic_ratio'))
      ->set('seo_ai_ratio', (float) $form_state->getValue('seo_ai_ratio'))
      ->set('accessibility_disclaimer', $form_state->getValue('accessibility_disclaimer'))
      ->set('seo_disclaimer', $form_state->getValue('seo_disclaimer'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
