<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/** Project-owned display terms; semantic command and resource IDs stay unchanged. */
final class Vocabulary
{
  /**
   * The Engine's own wording for terms many screens share, so a game rewords
   * each once (in the Editor's Terms) and no screen keeps its own fallback.
   * `stats.level_short` is the level in rows, lists and status lines ("Lv 12");
   * `stats.level` is the full word, for sentences ("Level 12!").
   */
  public const array DEFAULTS = [
    'stats.level' => 'Level',
    'stats.level_short' => 'Lv',
    'stats.hp' => 'HP',
    'stats.mp' => 'MP',
    'stats.exp' => 'EXP',
  ];

  /** A term as the game words it, else the default given, else the Engine's own ({@see DEFAULTS}), else nothing. */
  public static function getTerm(string $path, ?string $default = null): string
  {
    $default ??= self::DEFAULTS[$path] ?? '';
    if (!ConfigStore::has(ProjectConfig::class)) { return $default; }
    $locale = MessageCatalog::locale();
    if ($locale !== '') {
      $term = config(ProjectConfig::class, "vocab.$locale.$path");
      if (is_string($term)) { return $term; }
    }
    $term = config(ProjectConfig::class, "vocab.$path", $default);
    return is_string($term) ? $term : $default;
  }

  /** A term that names a label/value field. A field cannot have a blank label, so a blank term keeps the default. */
  public static function getLabel(string $path, string $default): string
  {
    $term = trim(self::getTerm($path, $default));
    return $term !== '' ? $term : $default;
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
