<?php

namespace Ichiloto\Engine\Localization;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Resolves and formats player-facing message strings.
 *
 * Messages live in the project config under `messages.*` and may carry
 * positional placeholders (`%1`, `%2`, …) — the form the scaffolded message
 * tree already uses:
 *
 * ```php
 * 'messages' => [
 *   'obtained_gold' => '%1 %2 found!',
 *   'confirm' => ['quit' => 'Are you sure you want to quit?'],
 * ],
 * ```
 *
 * A project localizes by nesting locale keys and setting `locale`:
 *
 * ```php
 * 'locale' => 'fr',
 * 'messages' => [
 *   'fr' => ['confirm' => ['quit' => 'Voulez-vous vraiment quitter ?']],
 *   'confirm' => ['quit' => 'Are you sure you want to quit?'],   // fallback
 * ],
 * ```
 *
 * Lookup order is locale-first, then the untagged tree, then the supplied
 * default — so a partial translation degrades to the base language instead
 * of showing a missing-key marker.
 *
 * @package Ichiloto\Engine\Localization
 */
final class MessageCatalog
{
  /**
   * MessageCatalog constructor.
   */
  private function __construct()
  {
  }

  /**
   * Returns the active locale.
   *
   * @return string The locale key; empty when the project sets none.
   */
  public static function locale(): string
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return '';
    }

    return trim(strval(config(ProjectConfig::class, 'locale', '')));
  }

  /**
   * Resolves a message and substitutes its placeholders.
   *
   * @param string $path The dot path under `messages.`.
   * @param string $default The string used when the path is not authored.
   * @param int|float|string ...$arguments Values for `%1`, `%2`, …
   * @return string The formatted message.
   */
  public static function get(string $path, string $default = '', int|float|string ...$arguments): string
  {
    return self::format(self::resolve($path, $default), ...$arguments);
  }

  /**
   * Resolves a message without formatting it.
   *
   * @param string $path The dot path under `messages.`.
   * @param string $default The string used when the path is not authored.
   * @return string The raw message.
   */
  public static function resolve(string $path, string $default = ''): string
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return $default;
    }

    $locale = self::locale();

    if ($locale !== '') {
      $localized = config(ProjectConfig::class, "messages.$locale.$path", null);

      if (is_string($localized) && $localized !== '') {
        return $localized;
      }
    }

    $message = config(ProjectConfig::class, "messages.$path", $default);

    return is_string($message) ? $message : $default;
  }

  /**
   * Substitutes positional placeholders in a message.
   *
   * Placeholders are 1-based (`%1` is the first argument). Unmatched
   * placeholders are left untouched so a partially-argued string still
   * reads intelligibly rather than collapsing to an empty span.
   *
   * @param string $message The message template.
   * @param int|float|string ...$arguments The replacement values.
   * @return string The formatted message.
   */
  public static function format(string $message, int|float|string ...$arguments): string
  {
    if (empty($arguments) || ! str_contains($message, '%')) {
      return $message;
    }

    $replacements = [];

    // Replace the highest index first so %10 is never eaten by %1.
    foreach (array_reverse(array_keys($arguments)) as $index) {
      $replacements['%' . ($index + 1)] = strval($arguments[$index]);
    }

    return strtr($message, $replacements);
  }
}
