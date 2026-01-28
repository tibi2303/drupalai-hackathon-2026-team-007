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

    // Provider fallback section
    $form['ai_settings']['provider_fallback'] = [
      '#type' => 'details',
      '#title' => $this->t('Provider Fallback Configuration'),
      '#description' => $this->t('Configure multiple AI providers with automatic fallback when one fails.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="ai_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_settings']['provider_fallback']['provider_fallback_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable multi-provider fallback'),
      '#description' => $this->t('When enabled, the system will try multiple AI providers in sequence if one fails. This improves reliability but may increase costs.'),
      '#default_value' => $config->get('provider_fallback_enabled'),
    ];

    // Get available providers
    $aiProviderManager = \Drupal::service('ai.provider');
    $providerOptions = $aiProviderManager->getSimpleProviderModelOptions(
      'chat_with_complex_json',
      FALSE,
      TRUE
    );

    if (empty($providerOptions)) {
      $form['ai_settings']['provider_fallback']['no_providers'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('No AI providers are configured. Visit the <a href="@url">AI module settings</a> to configure providers.',
            ['@url' => '/admin/config/ai']) .
          '</div>',
        '#states' => [
          'visible' => [
            ':input[name="provider_fallback_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
    else {
      $existingChain = $config->get('provider_fallback_chain') ?: [];

      $form['ai_settings']['provider_fallback']['provider_chain_container'] = [
        '#type' => 'container',
        '#states' => [
          'visible' => [
            ':input[name="provider_fallback_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['ai_settings']['provider_fallback']['provider_chain_container']['providers'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Provider & Model'),
          $this->t('Enabled'),
          $this->t('Priority (Weight)'),
        ],
        '#empty' => $this->t('No providers available.'),
        '#tabledrag' => [
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'provider-weight',
          ],
        ],
      ];

      // Build rows for each provider
      foreach ($providerOptions as $key => $label) {
        [$providerId, $modelId] = explode('__', $key);
        $weight = 0;
        $enabled = FALSE;

        // Check if in existing chain
        foreach ($existingChain as $item) {
          if ($item['provider_id'] === $providerId && $item['model_id'] === $modelId) {
            $weight = $item['weight'] ?? 0;
            $enabled = $item['enabled'] ?? FALSE;
            break;
          }
        }

        $form['ai_settings']['provider_fallback']['provider_chain_container']['providers'][$key] = [
          '#attributes' => ['class' => ['draggable']],
          '#weight' => $weight,
          'provider_label' => [
            '#plain_text' => $label,
          ],
          'enabled' => [
            '#type' => 'checkbox',
            '#default_value' => $enabled,
          ],
          'weight' => [
            '#type' => 'weight',
            '#title' => $this->t('Weight for @provider', ['@provider' => $label]),
            '#title_display' => 'invisible',
            '#default_value' => $weight,
            '#attributes' => ['class' => ['provider-weight']],
            '#delta' => 50,
          ],
          'provider_id' => [
            '#type' => 'hidden',
            '#value' => $providerId,
          ],
          'model_id' => [
            '#type' => 'hidden',
            '#value' => $modelId,
          ],
        ];
      }

      // Retry settings
      $form['ai_settings']['provider_fallback']['retry_on_rate_limit'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Retry on rate limit'),
        '#description' => $this->t('If a provider hits rate limits, wait and retry before moving to the next provider.'),
        '#default_value' => $config->get('retry_on_rate_limit'),
        '#states' => [
          'visible' => [
            ':input[name="provider_fallback_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['ai_settings']['provider_fallback']['rate_limit_retry_delay'] = [
        '#type' => 'number',
        '#title' => $this->t('Rate limit retry delay (seconds)'),
        '#description' => $this->t('How long to wait before retrying after a rate limit error.'),
        '#default_value' => $config->get('rate_limit_retry_delay') ?: 5,
        '#min' => 1,
        '#max' => 60,
        '#states' => [
          'visible' => [
            ':input[name="retry_on_rate_limit"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['ai_settings']['provider_fallback']['rate_limit_max_retries'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum retries per provider'),
        '#description' => $this->t('How many times to retry a provider before moving to the next one.'),
        '#default_value' => $config->get('rate_limit_max_retries') ?: 2,
        '#min' => 0,
        '#max' => 10,
        '#states' => [
          'visible' => [
            ':input[name="retry_on_rate_limit"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['ai_settings']['provider_fallback']['log_provider_fallback'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Log provider fallback events'),
        '#description' => $this->t('Log detailed information when fallback occurs for debugging and monitoring.'),
        '#default_value' => $config->get('log_provider_fallback') ?? TRUE,
        '#states' => [
          'visible' => [
            ':input[name="provider_fallback_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

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

    // Validate provider fallback settings
    if ($form_state->getValue('provider_fallback_enabled')) {
      $providers = $form_state->getValue('providers');

      if (!empty($providers)) {
        $enabledCount = 0;
        foreach ($providers as $key => $provider) {
          if (!empty($provider['enabled'])) {
            $enabledCount++;
          }
        }

        if ($enabledCount === 0) {
          $form_state->setErrorByName(
            'provider_fallback_enabled',
            $this->t('At least one provider must be enabled when fallback is active.')
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('seo_audit.settings')
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
      ->set('provider_fallback_enabled', (bool) $form_state->getValue('provider_fallback_enabled'))
      ->set('retry_on_rate_limit', (bool) $form_state->getValue('retry_on_rate_limit'))
      ->set('rate_limit_retry_delay', (int) $form_state->getValue('rate_limit_retry_delay'))
      ->set('rate_limit_max_retries', (int) $form_state->getValue('rate_limit_max_retries'))
      ->set('log_provider_fallback', (bool) $form_state->getValue('log_provider_fallback'));

    // Build and save provider chain
    $providers = $form_state->getValue('providers');
    if (!empty($providers)) {
      $chain = [];
      foreach ($providers as $key => $provider) {
        if (!empty($provider['provider_id']) && !empty($provider['model_id'])) {
          $chain[] = [
            'provider_id' => $provider['provider_id'],
            'model_id' => $provider['model_id'],
            'weight' => (int) $provider['weight'],
            'enabled' => (bool) $provider['enabled'],
          ];
        }
      }
      $config->set('provider_fallback_chain', $chain);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
