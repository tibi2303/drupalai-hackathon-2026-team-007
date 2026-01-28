<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

/**
 * Runs deterministic accessibility checks against rendered HTML.
 */
class AccessibilityCheckerService {

  /**
   * Analyze rendered HTML for accessibility issues.
   *
   * @param string $html
   *   The rendered HTML of the node.
   *
   * @return array
   *   Array of check result arrays.
   */
  public function analyze(string $html): array {
    $results = [];
    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);

    $results[] = $this->checkImageAltText($xpath);
    $results[] = $this->checkDecorativeImageAlt($xpath);
    $results[] = $this->checkHeadingOrder($xpath);
    $results[] = $this->checkNoEmptyHeadings($xpath);
    $results[] = $this->checkFormLabels($xpath);
    $results[] = $this->checkLangAttribute($xpath);
    $results[] = $this->checkAriaLandmarks($xpath);
    $results[] = $this->checkTableHeaders($xpath);
    $results[] = $this->checkDescriptiveLinkText($xpath);
    $results[] = $this->checkLinkDistinguishability($xpath);
    $results[] = $this->checkPageTitle($xpath);
    $results[] = $this->checkSkipNavigation($xpath);
    $results[] = $this->checkIframeTitle($xpath);
    $results[] = $this->checkNoAutoPlayingMedia($xpath);

    return $results;
  }

  /**
   * Check: Image alt text (WCAG 1.1.1).
   */
  protected function checkImageAltText(\DOMXPath $xpath): array {
    $images = $xpath->query('//img');
    if ($images->length === 0) {
      return [
        'checkId' => 'a11y_image_alt',
        'category' => 'accessibility',
        'wcag' => '1.1.1',
        'severity' => 'pass',
        'title' => 'Image alt text',
        'description' => 'No images found.',
        'recommendation' => '',
        'maxPoints' => 15,
        'earnedPoints' => 15,
      ];
    }

    $missing = 0;
    foreach ($images as $img) {
      if (!$img->hasAttribute('alt')) {
        $missing++;
      }
    }

    $pass = $missing === 0;
    return [
      'checkId' => 'a11y_image_alt',
      'category' => 'accessibility',
      'wcag' => '1.1.1',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Image alt text',
      'description' => $pass
        ? "All {$images->length} images have alt attributes."
        : "$missing of {$images->length} images are missing alt attributes.",
      'recommendation' => $pass ? '' : 'Add alt text to all images. Use alt="" for decorative images.',
      'maxPoints' => 15,
      'earnedPoints' => $pass ? 15 : (int) round(15 * (($images->length - $missing) / $images->length)),
    ];
  }

  /**
   * Check: Decorative images use alt="" (WCAG 1.1.1).
   */
  protected function checkDecorativeImageAlt(\DOMXPath $xpath): array {
    $images = $xpath->query('//img[@role="presentation" or @role="none"]');
    $missingEmpty = 0;
    foreach ($images as $img) {
      if ($img->getAttribute('alt') !== '') {
        $missingEmpty++;
      }
    }

    if ($images->length === 0) {
      return [
        'checkId' => 'a11y_decorative_alt',
        'category' => 'accessibility',
        'wcag' => '1.1.1',
        'severity' => 'pass',
        'title' => 'Decorative image alt',
        'description' => 'No explicitly decorative images found.',
        'recommendation' => '',
        'maxPoints' => 5,
        'earnedPoints' => 5,
      ];
    }

    $pass = $missingEmpty === 0;
    return [
      'checkId' => 'a11y_decorative_alt',
      'category' => 'accessibility',
      'wcag' => '1.1.1',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Decorative image alt',
      'description' => $pass
        ? 'All decorative images have empty alt attributes.'
        : "$missingEmpty decorative images have non-empty alt text.",
      'recommendation' => $pass ? '' : 'Set alt="" on decorative images (role="presentation" or role="none").',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: Heading order (WCAG 1.3.1).
   */
  protected function checkHeadingOrder(\DOMXPath $xpath): array {
    $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
    if ($headings->length === 0) {
      return [
        'checkId' => 'a11y_heading_order',
        'category' => 'accessibility',
        'wcag' => '1.3.1',
        'severity' => 'major',
        'title' => 'Heading order',
        'description' => 'No headings found on the page.',
        'recommendation' => 'Add headings with a logical hierarchical order.',
        'maxPoints' => 10,
        'earnedPoints' => 0,
      ];
    }

    $levels = [];
    foreach ($headings as $heading) {
      $levels[] = (int) substr($heading->nodeName, 1);
    }

    $skipped = FALSE;
    for ($i = 1; $i < count($levels); $i++) {
      if ($levels[$i] > $levels[$i - 1] + 1) {
        $skipped = TRUE;
        break;
      }
    }

    return [
      'checkId' => 'a11y_heading_order',
      'category' => 'accessibility',
      'wcag' => '1.3.1',
      'severity' => $skipped ? 'major' : 'pass',
      'title' => 'Heading order',
      'description' => $skipped
        ? 'Heading levels are skipped (e.g., H2 directly to H4).'
        : 'Headings follow a logical hierarchical order.',
      'recommendation' => $skipped ? 'Ensure heading levels are sequential without skipping levels.' : '',
      'maxPoints' => 10,
      'earnedPoints' => $skipped ? 0 : 10,
    ];
  }

  /**
   * Check: No empty headings (WCAG 1.3.1).
   */
  protected function checkNoEmptyHeadings(\DOMXPath $xpath): array {
    $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
    $empty = 0;
    foreach ($headings as $heading) {
      if (trim($heading->textContent) === '') {
        $empty++;
      }
    }

    $pass = $empty === 0;
    return [
      'checkId' => 'a11y_no_empty_headings',
      'category' => 'accessibility',
      'wcag' => '1.3.1',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'No empty headings',
      'description' => $pass
        ? 'All headings contain text.'
        : "$empty headings are empty.",
      'recommendation' => $pass ? '' : 'Remove empty headings or add meaningful text content.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: Form labels (WCAG 1.3.1).
   */
  protected function checkFormLabels(\DOMXPath $xpath): array {
    $inputs = $xpath->query('//input[@type!="hidden" and @type!="submit" and @type!="button" and @type!="reset" and @type!="image"] | //textarea | //select');
    if ($inputs->length === 0) {
      return [
        'checkId' => 'a11y_form_labels',
        'category' => 'accessibility',
        'wcag' => '1.3.1',
        'severity' => 'pass',
        'title' => 'Form labels',
        'description' => 'No form inputs found.',
        'recommendation' => '',
        'maxPoints' => 10,
        'earnedPoints' => 10,
      ];
    }

    $unlabeled = 0;
    foreach ($inputs as $input) {
      $id = $input->getAttribute('id');
      $hasLabel = FALSE;
      if ($id) {
        $labels = $xpath->query("//label[@for='$id']");
        $hasLabel = $labels->length > 0;
      }
      if (!$hasLabel) {
        $hasLabel = $input->hasAttribute('aria-label') || $input->hasAttribute('aria-labelledby') || $input->hasAttribute('title');
      }
      if (!$hasLabel) {
        $unlabeled++;
      }
    }

    $pass = $unlabeled === 0;
    return [
      'checkId' => 'a11y_form_labels',
      'category' => 'accessibility',
      'wcag' => '1.3.1',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Form labels',
      'description' => $pass
        ? "All {$inputs->length} form inputs have associated labels."
        : "$unlabeled of {$inputs->length} form inputs are missing labels.",
      'recommendation' => $pass ? '' : 'Add <label> elements, aria-label, or aria-labelledby to all form inputs.',
      'maxPoints' => 10,
      'earnedPoints' => $pass ? 10 : (int) round(10 * (($inputs->length - $unlabeled) / $inputs->length)),
    ];
  }

  /**
   * Check: lang attribute (WCAG 3.1.1).
   */
  protected function checkLangAttribute(\DOMXPath $xpath): array {
    $html = $xpath->query('//html[@lang]');
    $pass = $html->length > 0 && trim($html->item(0)->getAttribute('lang')) !== '';
    return [
      'checkId' => 'a11y_lang_attribute',
      'category' => 'accessibility',
      'wcag' => '3.1.1',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Language attribute',
      'description' => $pass
        ? 'Page has a lang attribute on the html element.'
        : 'Page is missing a lang attribute on the html element.',
      'recommendation' => $pass ? '' : 'Add a lang attribute to the <html> element (e.g., lang="en").',
      'maxPoints' => 10,
      'earnedPoints' => $pass ? 10 : 0,
    ];
  }

  /**
   * Check: ARIA landmarks (WCAG 1.3.1).
   */
  protected function checkAriaLandmarks(\DOMXPath $xpath): array {
    $landmarks = $xpath->query('//main | //nav | //header | //footer | //aside | //*[@role="main"] | //*[@role="navigation"] | //*[@role="banner"] | //*[@role="contentinfo"] | //*[@role="complementary"]');
    $pass = $landmarks->length > 0;
    return [
      'checkId' => 'a11y_aria_landmarks',
      'category' => 'accessibility',
      'wcag' => '1.3.1',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'ARIA landmarks',
      'description' => $pass
        ? "Found {$landmarks->length} landmark regions."
        : 'No ARIA landmark regions found.',
      'recommendation' => $pass ? '' : 'Add semantic HTML5 elements (main, nav, header, footer) or ARIA landmark roles.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : 0,
    ];
  }

  /**
   * Check: Table headers (WCAG 1.3.1).
   */
  protected function checkTableHeaders(\DOMXPath $xpath): array {
    $tables = $xpath->query('//table');
    if ($tables->length === 0) {
      return [
        'checkId' => 'a11y_table_headers',
        'category' => 'accessibility',
        'wcag' => '1.3.1',
        'severity' => 'pass',
        'title' => 'Table headers',
        'description' => 'No tables found on the page.',
        'recommendation' => '',
        'maxPoints' => 8,
        'earnedPoints' => 8,
      ];
    }

    $missingHeaders = 0;
    foreach ($tables as $table) {
      $ths = $xpath->query('.//th', $table);
      $scope = $xpath->query('.//th[@scope]', $table);
      if ($ths->length === 0) {
        $missingHeaders++;
      }
    }

    $pass = $missingHeaders === 0;
    return [
      'checkId' => 'a11y_table_headers',
      'category' => 'accessibility',
      'wcag' => '1.3.1',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Table headers',
      'description' => $pass
        ? "All {$tables->length} tables have header cells."
        : "$missingHeaders of {$tables->length} tables are missing header cells.",
      'recommendation' => $pass ? '' : 'Add <th> elements with scope attributes to data tables.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : (int) round(8 * (($tables->length - $missingHeaders) / $tables->length)),
    ];
  }

  /**
   * Check: Descriptive link text (WCAG 2.4.4).
   */
  protected function checkDescriptiveLinkText(\DOMXPath $xpath): array {
    $links = $xpath->query('//a[@href]');
    if ($links->length === 0) {
      return [
        'checkId' => 'a11y_descriptive_links',
        'category' => 'accessibility',
        'wcag' => '2.4.4',
        'severity' => 'pass',
        'title' => 'Descriptive link text',
        'description' => 'No links found.',
        'recommendation' => '',
        'maxPoints' => 8,
        'earnedPoints' => 8,
      ];
    }

    $genericPhrases = ['click here', 'read more', 'more', 'here', 'link', 'this'];
    $generic = 0;
    foreach ($links as $link) {
      $text = strtolower(trim($link->textContent));
      if (in_array($text, $genericPhrases, TRUE) || $text === '') {
        if (!$link->hasAttribute('aria-label') && !$link->hasAttribute('aria-labelledby')) {
          $generic++;
        }
      }
    }

    $pass = $generic === 0;
    return [
      'checkId' => 'a11y_descriptive_links',
      'category' => 'accessibility',
      'wcag' => '2.4.4',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Descriptive link text',
      'description' => $pass
        ? 'All links have descriptive text.'
        : "$generic links have generic or empty text (e.g., 'click here', 'read more').",
      'recommendation' => $pass ? '' : 'Use descriptive link text that indicates the destination or purpose.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : (int) max(0, 8 - ($generic * 2)),
    ];
  }

  /**
   * Check: Link distinguishability (WCAG 1.4.1).
   */
  protected function checkLinkDistinguishability(\DOMXPath $xpath): array {
    // This is a heuristic check - we can only verify that links exist within
    // text content, as actual color contrast requires CSS evaluation.
    $links = $xpath->query('//p//a | //li//a | //td//a');
    $pass = TRUE; // Best-effort, as we can't evaluate CSS.
    return [
      'checkId' => 'a11y_link_distinguishability',
      'category' => 'accessibility',
      'wcag' => '1.4.1',
      'severity' => 'pass',
      'title' => 'Link distinguishability',
      'description' => 'Link distinguishability requires visual inspection (CSS-dependent). Found ' . $links->length . ' inline links.',
      'recommendation' => 'Ensure links are visually distinguishable from surrounding text by means other than color alone.',
      'maxPoints' => 5,
      'earnedPoints' => 5,
    ];
  }

  /**
   * Check: Page title (WCAG 2.4.2).
   */
  protected function checkPageTitle(\DOMXPath $xpath): array {
    $titles = $xpath->query('//title');
    $pass = $titles->length > 0 && trim($titles->item(0)->textContent) !== '';
    return [
      'checkId' => 'a11y_page_title',
      'category' => 'accessibility',
      'wcag' => '2.4.2',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Page title',
      'description' => $pass
        ? 'Page has a descriptive title.'
        : 'Page is missing a title element.',
      'recommendation' => $pass ? '' : 'Add a descriptive <title> element to the page.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : 0,
    ];
  }

  /**
   * Check: Skip navigation link (WCAG 2.4.1).
   */
  protected function checkSkipNavigation(\DOMXPath $xpath): array {
    $skipLinks = $xpath->query('//a[contains(@href, "#main") or contains(@href, "#content") or contains(@class, "skip")]');
    $pass = $skipLinks->length > 0;
    return [
      'checkId' => 'a11y_skip_navigation',
      'category' => 'accessibility',
      'wcag' => '2.4.1',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Skip navigation',
      'description' => $pass
        ? 'Page has a skip navigation link.'
        : 'No skip navigation link found.',
      'recommendation' => $pass ? '' : 'Add a "skip to main content" link at the beginning of the page.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: iframe title (WCAG 2.4.1).
   */
  protected function checkIframeTitle(\DOMXPath $xpath): array {
    $iframes = $xpath->query('//iframe');
    if ($iframes->length === 0) {
      return [
        'checkId' => 'a11y_iframe_title',
        'category' => 'accessibility',
        'wcag' => '2.4.1',
        'severity' => 'pass',
        'title' => 'Iframe titles',
        'description' => 'No iframes found.',
        'recommendation' => '',
        'maxPoints' => 5,
        'earnedPoints' => 5,
      ];
    }

    $missing = 0;
    foreach ($iframes as $iframe) {
      if (!$iframe->hasAttribute('title') || trim($iframe->getAttribute('title')) === '') {
        $missing++;
      }
    }

    $pass = $missing === 0;
    return [
      'checkId' => 'a11y_iframe_title',
      'category' => 'accessibility',
      'wcag' => '2.4.1',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Iframe titles',
      'description' => $pass
        ? "All {$iframes->length} iframes have title attributes."
        : "$missing of {$iframes->length} iframes are missing title attributes.",
      'recommendation' => $pass ? '' : 'Add descriptive title attributes to all iframes.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : (int) round(5 * (($iframes->length - $missing) / $iframes->length)),
    ];
  }

  /**
   * Check: No auto-playing media (WCAG 1.4.2).
   */
  protected function checkNoAutoPlayingMedia(\DOMXPath $xpath): array {
    $autoplay = $xpath->query('//video[@autoplay] | //audio[@autoplay]');
    $pass = $autoplay->length === 0;
    return [
      'checkId' => 'a11y_no_autoplay',
      'category' => 'accessibility',
      'wcag' => '1.4.2',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'No auto-playing media',
      'description' => $pass
        ? 'No auto-playing audio or video elements found.'
        : "{$autoplay->length} media elements have autoplay enabled.",
      'recommendation' => $pass ? '' : 'Remove autoplay from media elements or provide a mechanism to pause/stop.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : 0,
    ];
  }

}
