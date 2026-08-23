<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Gives transient cinematic narration enough time to be read.
 *
 * Narration appears all at once rather than through the dialogue typewriter,
 * so a short fixed duration can expire before the player has located and read
 * the overlay. The authored duration remains a minimum; this policy only
 * extends it when the visible word count requires a longer reading window.
 */
final class CinematicTextTimingPolicy
{
  public const float DEFAULT_MINIMUM_DURATION = 3.0;
  public const float DEFAULT_WORDS_PER_MINUTE = 180.0;
  public const float DEFAULT_SETTLE_DURATION = 1.0;

  private function __construct()
  {
  }

  /**
   * Resolves the hold time for a narration overlay.
   *
   * Projects may tune the policy under:
   *
   * - `ui.cinematics.narration.minimum_duration`
   * - `ui.cinematics.narration.words_per_minute`
   * - `ui.cinematics.narration.settle_duration`
   */
  public static function narrationDuration(string $text, float $authoredMinimum = 0.0): float
  {
    $visibleText = trim(TerminalText::stripAnsi($text));

    if ($visibleText === '') {
      return max(0.0, $authoredMinimum);
    }

    $words = preg_split('/\s+/u', $visibleText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $wordsPerMinute = max(1.0, self::configuredFloat(
      'ui.cinematics.narration.words_per_minute',
      self::DEFAULT_WORDS_PER_MINUTE,
    ));
    $minimumDuration = self::configuredFloat(
      'ui.cinematics.narration.minimum_duration',
      self::DEFAULT_MINIMUM_DURATION,
    );
    $settleDuration = self::configuredFloat(
      'ui.cinematics.narration.settle_duration',
      self::DEFAULT_SETTLE_DURATION,
    );
    $readingDuration = $settleDuration + (count($words) / ($wordsPerMinute / 60.0));

    return max(0.0, $authoredMinimum, $minimumDuration, $readingDuration);
  }

  private static function configuredFloat(string $path, float $default): float
  {
    if (ConfigStore::doesntHave(ProjectConfig::class)) {
      return $default;
    }

    $configured = config(ProjectConfig::class, $path, $default);

    return is_numeric($configured) ? max(0.0, floatval($configured)) : $default;
  }
}
