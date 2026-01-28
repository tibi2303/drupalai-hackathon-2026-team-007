<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Renders a node to HTML for audit analysis.
 */
class NodeHtmlRenderer {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Render a node to HTML string.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to render.
   * @param string $viewMode
   *   The view mode to use for rendering.
   *
   * @return string
   *   The rendered HTML.
   */
  public function render(NodeInterface $node, string $viewMode = 'full'): string {
    $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
    $build = $viewBuilder->view($node, $viewMode);
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Extract plain text content from HTML.
   *
   * @param string $html
   *   The HTML string.
   *
   * @return string
   *   Cleaned plain text.
   */
  public function extractText(string $html): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
  }

}
