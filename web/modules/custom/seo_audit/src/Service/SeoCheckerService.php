<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Service;

use Drupal\node\NodeInterface;

/**
 * Runs deterministic SEO checks against rendered HTML.
 */
class SeoCheckerService {

  /**
   * Analyze rendered HTML for SEO issues.
   *
   * @param string $html
   *   The rendered HTML of the node.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Array of check result arrays.
   */
  public function analyze(string $html, NodeInterface $node): array {
    $results = [];
    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);

    $results[] = $this->checkTitlePresence($xpath);
    $results[] = $this->checkTitleLength($xpath);
    $results[] = $this->checkMetaDescriptionPresence($xpath);
    $results[] = $this->checkMetaDescriptionLength($xpath);
    $results[] = $this->checkSingleH1($xpath);
    $results[] = $this->checkNoMultipleH1($xpath);
    $results[] = $this->checkHeadingHierarchy($xpath);
    $results[] = $this->checkImageAltText($xpath);
    $results[] = $this->checkInternalLinks($xpath, $node);
    $results[] = $this->checkExternalLinksRel($xpath);
    $results[] = $this->checkCanonicalUrl($xpath);
    $results[] = $this->checkSchemaOrgMarkup($xpath);
    $results[] = $this->checkOpenGraphTags($xpath);
    $results[] = $this->checkTwitterCardTags($xpath);
    $results[] = $this->checkCleanUrlStructure($xpath);
    $results[] = $this->checkNoAccidentalNoindex($xpath);
    $results[] = $this->checkContentLength($html);

    return $results;
  }

  /**
   * Check: Title tag presence.
   */
  protected function checkTitlePresence(\DOMXPath $xpath): array {
    $titles = $xpath->query('//title');
    $pass = $titles->length > 0 && trim($titles->item(0)->textContent) !== '';
    return [
      'checkId' => 'seo_title_presence',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Page title',
      'description' => $pass
        ? 'Page has a title tag.'
        : 'Page is missing a title tag.',
      'recommendation' => $pass ? '' : 'Add a descriptive <title> tag to the page.',
      'maxPoints' => 10,
      'earnedPoints' => $pass ? 10 : 0,
    ];
  }

  /**
   * Check: Title length (30-60 chars).
   */
  protected function checkTitleLength(\DOMXPath $xpath): array {
    $titles = $xpath->query('//title');
    if ($titles->length === 0) {
      return [
        'checkId' => 'seo_title_length',
        'category' => 'seo',
        'severity' => 'major',
        'title' => 'Title length',
        'description' => 'No title tag found to check length.',
        'recommendation' => 'Add a title tag with 30-60 characters.',
        'maxPoints' => 8,
        'earnedPoints' => 0,
      ];
    }
    $len = mb_strlen(trim($titles->item(0)->textContent));
    $pass = $len >= 30 && $len <= 60;
    return [
      'checkId' => 'seo_title_length',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Title length',
      'description' => $pass
        ? "Title length is $len characters (optimal: 30-60)."
        : "Title length is $len characters (optimal: 30-60).",
      'recommendation' => $pass ? '' : 'Adjust the title to be between 30 and 60 characters.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : 0,
    ];
  }

  /**
   * Check: Meta description presence.
   */
  protected function checkMetaDescriptionPresence(\DOMXPath $xpath): array {
    $meta = $xpath->query('//meta[@name="description"]');
    $pass = $meta->length > 0 && trim($meta->item(0)->getAttribute('content')) !== '';
    return [
      'checkId' => 'seo_meta_description_presence',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'Meta description',
      'description' => $pass
        ? 'Page has a meta description.'
        : 'Page is missing a meta description.',
      'recommendation' => $pass ? '' : 'Add a meta description tag with a compelling summary of the page content.',
      'maxPoints' => 10,
      'earnedPoints' => $pass ? 10 : 0,
    ];
  }

  /**
   * Check: Meta description length (120-160 chars).
   */
  protected function checkMetaDescriptionLength(\DOMXPath $xpath): array {
    $meta = $xpath->query('//meta[@name="description"]');
    if ($meta->length === 0) {
      return [
        'checkId' => 'seo_meta_description_length',
        'category' => 'seo',
        'severity' => 'minor',
        'title' => 'Meta description length',
        'description' => 'No meta description found.',
        'recommendation' => 'Add a meta description with 120-160 characters.',
        'maxPoints' => 5,
        'earnedPoints' => 0,
      ];
    }
    $len = mb_strlen(trim($meta->item(0)->getAttribute('content')));
    $pass = $len >= 120 && $len <= 160;
    return [
      'checkId' => 'seo_meta_description_length',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Meta description length',
      'description' => "Meta description is $len characters (optimal: 120-160).",
      'recommendation' => $pass ? '' : 'Adjust the meta description to be between 120 and 160 characters.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: Single H1 presence.
   */
  protected function checkSingleH1(\DOMXPath $xpath): array {
    $h1s = $xpath->query('//h1');
    $pass = $h1s->length >= 1;
    return [
      'checkId' => 'seo_single_h1',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'critical',
      'title' => 'H1 heading presence',
      'description' => $pass
        ? 'Page has an H1 heading.'
        : 'Page is missing an H1 heading.',
      'recommendation' => $pass ? '' : 'Add exactly one H1 heading that describes the main topic of the page.',
      'maxPoints' => 10,
      'earnedPoints' => $pass ? 10 : 0,
    ];
  }

  /**
   * Check: No multiple H1 tags.
   */
  protected function checkNoMultipleH1(\DOMXPath $xpath): array {
    $h1s = $xpath->query('//h1');
    $pass = $h1s->length <= 1;
    return [
      'checkId' => 'seo_no_multiple_h1',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Single H1 only',
      'description' => $pass
        ? 'Page has at most one H1.'
        : "Page has {$h1s->length} H1 headings. Only one is recommended.",
      'recommendation' => $pass ? '' : 'Reduce to a single H1 heading. Use H2-H6 for subheadings.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: Heading hierarchy (no level skips).
   */
  protected function checkHeadingHierarchy(\DOMXPath $xpath): array {
    $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
    if ($headings->length === 0) {
      return [
        'checkId' => 'seo_heading_hierarchy',
        'category' => 'seo',
        'severity' => 'major',
        'title' => 'Heading hierarchy',
        'description' => 'No headings found on the page.',
        'recommendation' => 'Add a logical heading structure starting with H1.',
        'maxPoints' => 8,
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
      'checkId' => 'seo_heading_hierarchy',
      'category' => 'seo',
      'severity' => $skipped ? 'major' : 'pass',
      'title' => 'Heading hierarchy',
      'description' => $skipped
        ? 'Heading hierarchy has level skips (e.g., H2 to H4).'
        : 'Heading hierarchy is properly structured.',
      'recommendation' => $skipped ? 'Ensure headings follow a logical order without skipping levels.' : '',
      'maxPoints' => 8,
      'earnedPoints' => $skipped ? 0 : 8,
    ];
  }

  /**
   * Check: Image alt text.
   */
  protected function checkImageAltText(\DOMXPath $xpath): array {
    $images = $xpath->query('//img');
    if ($images->length === 0) {
      return [
        'checkId' => 'seo_image_alt_text',
        'category' => 'seo',
        'severity' => 'pass',
        'title' => 'Image alt text',
        'description' => 'No images found on the page.',
        'recommendation' => '',
        'maxPoints' => 8,
        'earnedPoints' => 8,
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
      'checkId' => 'seo_image_alt_text',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Image alt text',
      'description' => $pass
        ? "All {$images->length} images have alt attributes."
        : "$missing of {$images->length} images are missing alt attributes.",
      'recommendation' => $pass ? '' : 'Add descriptive alt text to all images.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : (int) round(8 * (($images->length - $missing) / $images->length)),
    ];
  }

  /**
   * Check: Internal links present.
   */
  protected function checkInternalLinks(\DOMXPath $xpath, NodeInterface $node): array {
    $links = $xpath->query('//a[@href]');
    $internal = 0;
    $host = \Drupal::request()->getHost();
    foreach ($links as $link) {
      $href = $link->getAttribute('href');
      if (str_starts_with($href, '/') || str_contains($href, $host)) {
        $internal++;
      }
    }

    $pass = $internal > 0;
    return [
      'checkId' => 'seo_internal_links',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Internal links',
      'description' => $pass
        ? "Found $internal internal links."
        : 'No internal links found on the page.',
      'recommendation' => $pass ? '' : 'Add internal links to relevant content on your site.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: External links have rel attribute.
   */
  protected function checkExternalLinksRel(\DOMXPath $xpath): array {
    $links = $xpath->query('//a[@href]');
    $external = 0;
    $missingRel = 0;
    $host = \Drupal::request()->getHost();

    foreach ($links as $link) {
      $href = $link->getAttribute('href');
      if (str_starts_with($href, 'http') && !str_contains($href, $host)) {
        $external++;
        if (!$link->hasAttribute('rel')) {
          $missingRel++;
        }
      }
    }

    if ($external === 0) {
      return [
        'checkId' => 'seo_external_links_rel',
        'category' => 'seo',
        'severity' => 'pass',
        'title' => 'External link rel attributes',
        'description' => 'No external links found.',
        'recommendation' => '',
        'maxPoints' => 3,
        'earnedPoints' => 3,
      ];
    }

    $pass = $missingRel === 0;
    return [
      'checkId' => 'seo_external_links_rel',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'info',
      'title' => 'External link rel attributes',
      'description' => $pass
        ? "All $external external links have rel attributes."
        : "$missingRel of $external external links are missing rel attributes.",
      'recommendation' => $pass ? '' : 'Add rel="noopener" or rel="nofollow" to external links as appropriate.',
      'maxPoints' => 3,
      'earnedPoints' => $pass ? 3 : 0,
    ];
  }

  /**
   * Check: Canonical URL.
   */
  protected function checkCanonicalUrl(\DOMXPath $xpath): array {
    $canonical = $xpath->query('//link[@rel="canonical"]');
    $pass = $canonical->length > 0;
    return [
      'checkId' => 'seo_canonical_url',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'major',
      'title' => 'Canonical URL',
      'description' => $pass
        ? 'Page has a canonical URL.'
        : 'Page is missing a canonical URL.',
      'recommendation' => $pass ? '' : 'Add a <link rel="canonical"> tag pointing to the preferred URL.',
      'maxPoints' => 8,
      'earnedPoints' => $pass ? 8 : 0,
    ];
  }

  /**
   * Check: Schema.org markup.
   */
  protected function checkSchemaOrgMarkup(\DOMXPath $xpath): array {
    $jsonLd = $xpath->query('//script[@type="application/ld+json"]');
    $microdata = $xpath->query('//*[@itemtype]');
    $pass = $jsonLd->length > 0 || $microdata->length > 0;
    return [
      'checkId' => 'seo_schema_org',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Schema.org markup',
      'description' => $pass
        ? 'Page contains structured data markup.'
        : 'No Schema.org markup found.',
      'recommendation' => $pass ? '' : 'Add JSON-LD structured data to improve search engine understanding.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : 0,
    ];
  }

  /**
   * Check: Open Graph tags.
   */
  protected function checkOpenGraphTags(\DOMXPath $xpath): array {
    $ogTitle = $xpath->query('//meta[@property="og:title"]');
    $ogDesc = $xpath->query('//meta[@property="og:description"]');
    $ogImage = $xpath->query('//meta[@property="og:image"]');
    $count = ($ogTitle->length > 0 ? 1 : 0)
      + ($ogDesc->length > 0 ? 1 : 0)
      + ($ogImage->length > 0 ? 1 : 0);
    $pass = $count >= 2;
    return [
      'checkId' => 'seo_open_graph',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Open Graph tags',
      'description' => $pass
        ? "Found $count/3 essential Open Graph tags."
        : "Only $count/3 essential Open Graph tags found (og:title, og:description, og:image).",
      'recommendation' => $pass ? '' : 'Add Open Graph meta tags for better social media sharing.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : (int) round(5 * ($count / 3)),
    ];
  }

  /**
   * Check: Twitter card tags.
   */
  protected function checkTwitterCardTags(\DOMXPath $xpath): array {
    $twitterCard = $xpath->query('//meta[@name="twitter:card"]');
    $pass = $twitterCard->length > 0;
    return [
      'checkId' => 'seo_twitter_card',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'info',
      'title' => 'Twitter card tags',
      'description' => $pass
        ? 'Page has Twitter card meta tags.'
        : 'No Twitter card meta tags found.',
      'recommendation' => $pass ? '' : 'Add Twitter card meta tags for better Twitter/X sharing.',
      'maxPoints' => 3,
      'earnedPoints' => $pass ? 3 : 0,
    ];
  }

  /**
   * Check: Clean URL structure.
   */
  protected function checkCleanUrlStructure(\DOMXPath $xpath): array {
    $canonical = $xpath->query('//link[@rel="canonical"]');
    if ($canonical->length === 0) {
      return [
        'checkId' => 'seo_clean_url',
        'category' => 'seo',
        'severity' => 'minor',
        'title' => 'Clean URL structure',
        'description' => 'No canonical URL to evaluate.',
        'recommendation' => 'Add a canonical URL to evaluate URL structure.',
        'maxPoints' => 4,
        'earnedPoints' => 0,
      ];
    }
    $url = $canonical->item(0)->getAttribute('href');
    $hasQueryString = str_contains($url, '?');
    $hasNodeId = (bool) preg_match('/\/node\/\d+/', $url);
    $pass = !$hasQueryString && !$hasNodeId;
    return [
      'checkId' => 'seo_clean_url',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Clean URL structure',
      'description' => $pass
        ? 'URL structure is clean and SEO-friendly.'
        : 'URL contains query parameters or raw node IDs.',
      'recommendation' => $pass ? '' : 'Use URL aliases with descriptive, keyword-rich paths.',
      'maxPoints' => 4,
      'earnedPoints' => $pass ? 4 : 0,
    ];
  }

  /**
   * Check: No accidental noindex.
   */
  protected function checkNoAccidentalNoindex(\DOMXPath $xpath): array {
    $robots = $xpath->query('//meta[@name="robots"]');
    $noindex = FALSE;
    if ($robots->length > 0) {
      $content = strtolower($robots->item(0)->getAttribute('content'));
      $noindex = str_contains($content, 'noindex');
    }
    return [
      'checkId' => 'seo_no_noindex',
      'category' => 'seo',
      'severity' => $noindex ? 'critical' : 'pass',
      'title' => 'No accidental noindex',
      'description' => $noindex
        ? 'Page has a noindex robots meta tag. Search engines will not index this page.'
        : 'Page does not have a noindex directive.',
      'recommendation' => $noindex ? 'Remove the noindex directive if this page should be indexed by search engines.' : '',
      'maxPoints' => 8,
      'earnedPoints' => $noindex ? 0 : 8,
    ];
  }

  /**
   * Check: Content length >= 300 words.
   */
  protected function checkContentLength(string $html): array {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    $wordCount = str_word_count(trim($text));
    $pass = $wordCount >= 300;
    return [
      'checkId' => 'seo_content_length',
      'category' => 'seo',
      'severity' => $pass ? 'pass' : 'minor',
      'title' => 'Content length',
      'description' => "Page contains approximately $wordCount words (minimum recommended: 300).",
      'recommendation' => $pass ? '' : 'Add more content to provide comprehensive coverage of the topic.',
      'maxPoints' => 5,
      'earnedPoints' => $pass ? 5 : (int) min(5, round(5 * ($wordCount / 300))),
    ];
  }

}
