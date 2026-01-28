<?php

declare(strict_types=1);

namespace Drupal\bcl_bridge\Twig;

use Twig\Loader\LoaderInterface;
use Twig\Source;

final class BclTwigLoader implements LoaderInterface {

  public function __construct(
    private readonly LoaderInterface $inner,
    string $appRoot,
  ) {
    if (method_exists($this->inner, 'addPath')) {
      $path = $appRoot . '/themes/custom/bcl_canvas/bcl-templates';
      if (is_dir($path)) {
        $this->inner->addPath($path, 'oe-bcl');
      }
    }
  }

  public function getSourceContext(string $name): Source {
    return $this->inner->getSourceContext($name);
  }

  public function getCacheKey(string $name): string {
    return $this->inner->getCacheKey($name);
  }

  public function isFresh(string $name, int $time): bool {
    return $this->inner->isFresh($name, $time);
  }

  public function exists(string $name): bool {
    return $this->inner->exists($name);
  }

}
