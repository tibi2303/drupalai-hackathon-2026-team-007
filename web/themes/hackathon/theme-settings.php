<?php

declare(strict_types=1);

/**
 * @file
 * Theme settings form for Hackathon theme.
 */

use Drupal\Core\Form\FormState;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function hackathon_form_system_theme_settings_alter(array &$form, FormState $form_state): void {

  $form['hackathon'] = [
    '#type' => 'details',
    '#title' => t('Hackathon'),
    '#open' => TRUE,
  ];

  $form['hackathon']['example'] = [
    '#type' => 'textfield',
    '#title' => t('Example'),
    '#default_value' => theme_get_setting('example'),
  ];

}
