<?php

namespace Drupal\hackaton_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetch public Google Doc as exported HTML.
 */
#[FunctionCall(
  id: 'ai_agent:fetch_google_doc_html',
  function_name: 'ai_agent_fetch_google_doc_html',
  name: 'Fetch Google Doc HTML',
  description: 'Fetches a public Google Doc via the export endpoint and returns HTML.',
  group: 'information_tools',
  context_definitions: [
    'url' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Google Doc URL"),
      description: new TranslatableMarkup("Public Google Doc URL (share link)."),
      required: TRUE,
    ),
  ],
)]
class FetchGoogleDocHtml extends FunctionCallBase implements ExecutableFunctionCallInterface {

  public function execute() {
    $url = trim($this->getContextValue('url'));

    // Extract document ID from common share URLs.
    if (!preg_match('~/document/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
      $this->setOutput('');
      return;
    }
    $doc_id = $m[1];

    $export_url = "https://docs.google.com/document/d/{$doc_id}/export?format=html";

    try {
      $client = \Drupal::httpClient();
      $res = $client->get($export_url, [
        'headers' => [
          // Helps avoid some edge caching quirks.
          'Accept' => 'text/html',
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ],
        'timeout' => 30,
        'allow_redirects' => TRUE,
      ]);
      $html = (string) $res->getBody();
      if (empty($html) || strlen($html) < 100) {
        $this->setOutput('Warning: Response too short or empty. Length: ' . strlen($html));
        return;
      }
      $this->setOutput($html);
    }
    catch (GuzzleException $e) {
      // Return error message for debugging.
      $this->setOutput('Error fetching document: ' . $e->getMessage());
    }
  }

}
