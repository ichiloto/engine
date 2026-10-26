<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/** Project-owned display terms; semantic command and resource IDs stay unchanged. */
final class Vocabulary
{
  public static function getTerm(string $path, string $default = ''): string
  {
    if (!ConfigStore::has(ProjectConfig::class)) { return $default; }
    $locale = MessageCatalog::locale();
    if ($locale !== '') {
      $term = config(ProjectConfig::class, "vocab.$locale.$path");
      if (is_string($term)) { return $term; }
    }
    $term = config(ProjectConfig::class, "vocab.$path", $default);
    return is_string($term) ? $term : $default;
  }

  /** Resolve a map of terms, retaining base entries not translated by the active locale. */
  public static function getTerms(string $path): array
  {
    if (!ConfigStore::has(ProjectConfig::class)) { return []; }
    $terms = config(ProjectConfig::class, "vocab.$path", []);
    $terms = is_array($terms) ? array_filter($terms, 'is_string') : [];
    $locale = MessageCatalog::locale();
    $localized = $locale !== '' ? config(ProjectConfig::class, "vocab.$locale.$path", []) : [];
    return is_array($localized) ? array_replace($terms, array_filter($localized, 'is_string')) : $terms;
  }
}
