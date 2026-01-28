<?php

declare(strict_types=1);

namespace Drupal\seo_audit\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Analyzes content quality for readability, keyword usage, and topic relevance.
 */
#[FunctionCall(
  id: 'information_tools:seo_audit_analyze_content_quality',
  function_name: 'seo_audit_analyze_content_quality',
  name: 'Analyze Content Quality',
  description: 'Analyzes page content for readability, keyword density, and topic identification. Returns quality metrics.',
  group: 'information_tools',
  module_dependencies: ['seo_audit'],
  context_definitions: [
    'content_text' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content Text'),
      description: new TranslatableMarkup('The plain text content of the page to analyze.'),
      required: TRUE,
    ),
    'meta_title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Meta Title'),
      description: new TranslatableMarkup('The page meta title.'),
      required: FALSE,
    ),
    'meta_description' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Meta Description'),
      description: new TranslatableMarkup('The page meta description.'),
      required: FALSE,
    ),
  ],
)]
class AnalyzeContentQuality extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): string {
    $contentText = $this->getContextValue('content_text');
    $metaTitle = $this->getContextValue('meta_title') ?? '';
    $metaDescription = $this->getContextValue('meta_description') ?? '';

    // Word count.
    $wordCount = str_word_count($contentText);

    // Estimate readability (Flesch-Kincaid approximation).
    $sentences = preg_split('/[.!?]+/', $contentText, -1, PREG_SPLIT_NO_EMPTY);
    $sentenceCount = max(1, count($sentences));
    $words = str_word_count($contentText, 1);
    $syllableCount = 0;
    foreach ($words as $word) {
      $syllableCount += $this->countSyllables($word);
    }
    $avgWordsPerSentence = $wordCount / $sentenceCount;
    $avgSyllablesPerWord = $wordCount > 0 ? $syllableCount / $wordCount : 0;
    $readabilityScore = max(0, min(100, (int) round(
      206.835 - (1.015 * $avgWordsPerSentence) - (84.6 * $avgSyllablesPerWord)
    )));

    // Simple keyword density (top 10 words, excluding stop words).
    $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
      'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
      'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'dare',
      'ought', 'used', 'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by',
      'from', 'as', 'into', 'through', 'during', 'before', 'after', 'above',
      'below', 'between', 'and', 'but', 'or', 'not', 'no', 'nor', 'so',
      'yet', 'both', 'either', 'neither', 'each', 'every', 'all', 'any',
      'few', 'more', 'most', 'other', 'some', 'such', 'than', 'too', 'very',
      'just', 'it', 'its', 'this', 'that', 'these', 'those', 'i', 'me', 'my',
      'we', 'our', 'you', 'your', 'he', 'him', 'his', 'she', 'her', 'they',
      'them', 'their', 'what', 'which', 'who', 'whom', 'when', 'where',
      'why', 'how', 'if', 'then', 'else', 'also', 'about', 'up', 'out',
    ];

    $wordFreq = [];
    foreach ($words as $word) {
      $lower = strtolower($word);
      if (mb_strlen($lower) > 2 && !in_array($lower, $stopWords, TRUE)) {
        $wordFreq[$lower] = ($wordFreq[$lower] ?? 0) + 1;
      }
    }
    arsort($wordFreq);
    $topKeywords = array_slice($wordFreq, 0, 10, TRUE);

    $output = json_encode([
      'word_count' => $wordCount,
      'sentence_count' => $sentenceCount,
      'readability_score' => $readabilityScore,
      'avg_words_per_sentence' => round($avgWordsPerSentence, 1),
      'top_keywords' => $topKeywords,
      'meta_title_length' => mb_strlen($metaTitle),
      'meta_description_length' => mb_strlen($metaDescription),
    ], JSON_PRETTY_PRINT);

    $this->setOutput($output);
    return $output;
  }

  /**
   * Approximate syllable count for a word.
   */
  protected function countSyllables(string $word): int {
    $word = strtolower(trim($word));
    if (strlen($word) <= 3) {
      return 1;
    }
    $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word);
    $word = preg_replace('/^y/', '', $word);
    preg_match_all('/[aeiouy]{1,2}/', $word, $matches);
    return max(1, count($matches[0]));
  }

}
