<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Plugin\AiAgent;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agents\Attribute\AiAgent;
use Drupal\ai_agents\PluginBase\AiAgentBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;

/**
 * SEO & Accessibility Audit Agent.
 *
 * Provides AI-powered content quality analysis and audit summarization.
 * This agent is read-only and uses only information_tools.
 */
#[AiAgent(
  id: 'seo_audit_agent',
  label: new TranslatableMarkup('SEO & Accessibility Audit Agent'),
  module_dependencies: ['seo_audit', 'node'],
)]
class SeoAuditAgent extends AiAgentBase {

  /**
   * {@inheritdoc}
   */
  public function agentsNames(): array {
    return [
      'seo_audit_agent' => 'SEO & Accessibility Audit Agent',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function agentsCapabilities(): array {
    return [
      'seo_audit_agent' => [
        'name' => 'SEO & Accessibility Audit Agent',
        'description' => 'Analyzes page content for SEO quality, readability, keyword usage, and synthesizes deterministic scan results into prioritized recommendations. Read-only: never modifies content.',
        'inputs' => [
          [
            'name' => 'content_text',
            'type' => 'string',
            'description' => 'Plain text content of the page.',
            'required' => TRUE,
          ],
          [
            'name' => 'seo_results',
            'type' => 'json',
            'description' => 'JSON-encoded deterministic SEO check results.',
            'required' => TRUE,
          ],
          [
            'name' => 'accessibility_results',
            'type' => 'json',
            'description' => 'JSON-encoded deterministic accessibility check results.',
            'required' => TRUE,
          ],
        ],
        'outputs' => [
          'content_quality_score' => [
            'type' => 'integer',
            'description' => 'Content quality score from 0 to 100.',
          ],
          'executive_summary' => [
            'type' => 'string',
            'description' => 'Executive summary of audit findings.',
          ],
          'issues' => [
            'type' => 'array',
            'description' => 'Prioritized list of issues with recommendations.',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function determineSolvability() {
    $this->agentHelper->setupRunner($this);
    return AiAgentInterface::JOB_SOLVABLE;
  }

  /**
   * {@inheritdoc}
   */
  public function solve() {
    $this->agentHelper->setupRunner($this);

    $systemPrompt = <<<'PROMPT'
You are an SEO and web accessibility analyst. You receive:
1. Deterministic check results (JSON) from automated scanners
2. Plain text of the page content

Your tasks:
- Assess content quality, readability, and keyword usage
- Synthesise deterministic results into a prioritised issue list
- Generate an executive summary (3-5 sentences)
- Provide one actionable recommendation per issue

HARD CONSTRAINTS:
- You are READ-ONLY. Never suggest automatic content changes.
- Frame accessibility findings as "automated check results", never as compliance/non-compliance.
- Do not include PII from content in your output.
- Keep total output under 2000 tokens.
PROMPT;

    $response = $this->runAiProvider($systemPrompt);
    $this->setInformation($response->getText());
    return $response->getText();
  }

  /**
   * {@inheritdoc}
   */
  public function answerQuestion() {
    return $this->information;
  }

}
