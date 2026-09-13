<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

/**
 * Detects what the host terminal can actually render.
 *
 * Terminals disagree about composite (ZWJ) emoji: modern emulators compose
 * "🏃‍➡️" into a single double-width glyph, while others draw the runner and
 * the arrow separately and advance the cursor twice as far. A game that
 * assumes the wrong behaviour either loses its directional art or smears the
 * row it draws on, so the engine detects the terminal at start-up and picks
 * the matching strategy instead of forcing one.
 *
 * Detection order, cheapest and safest first:
 * 1. An explicit project setting (`graphics.sprites.allow_composite_emoji`
 *    set to true/false) always wins — authors can override the guess.
 * 2. Known terminal families, read from the environment.
 * 3. An empirical cursor-position probe for anything unrecognised.
 * 4. False, the safe default: correct layout everywhere, plainer art.
 *
 * @package Ichiloto\Engine\IO\Console
 */
final class TerminalCapabilities
{
  /**
   * The project setting that overrides detection.
   */
  public const string CONFIG_COMPOSITE_EMOJI = 'graphics.sprites.allow_composite_emoji';
  /**
   * Terminal programs known to compose ZWJ emoji sequences.
   */
  protected const array COMPOSING_TERMINALS = ['iTerm.app', 'WezTerm', 'vscode', 'Hyper', 'ghostty', 'rio'];
  /**
   * Terminal programs known *not* to compose ZWJ emoji sequences.
   */
  protected const array NON_COMPOSING_TERMINALS = ['Apple_Terminal'];
  /**
   * How long to wait for a probe response, in microseconds.
   */
  protected const int PROBE_TIMEOUT_MICROSECONDS = 120_000;

  /**
   * @var bool|null The cached composite-emoji answer.
   */
  protected static ?bool $supportsCompositeEmoji = null;
  /**
   * @var string The reason behind the cached answer, for diagnostics.
   */
  protected static string $compositeEmojiReason = 'not detected';

  /**
   * TerminalCapabilities constructor.
   */
  private function __construct()
  {
  }

  /**
   * Determines whether the terminal composes ZWJ emoji sequences.
   *
   * @return bool True when composite emoji render as one double-width glyph.
   */
  public static function supportsCompositeEmoji(): bool
  {
    // An explicit project setting is authoritative and cheap to read, so it
    // is never cached: changing it takes effect immediately.
    $override = self::configuredOverride();

    if ($override !== null) {
      self::$compositeEmojiReason = 'set by the project configuration';

      return $override;
    }

    if (self::$supportsCompositeEmoji !== null) {
      return self::$supportsCompositeEmoji;
    }

    [$supported, $reason] = self::detectCompositeEmojiSupport();
    self::$compositeEmojiReason = $reason;

    return self::$supportsCompositeEmoji = $supported;
  }

  /**
   * Returns why the current composite-emoji answer was chosen.
   *
   * @return string The human-readable reason.
   */
  public static function getCompositeEmojiReason(): string
  {
    self::supportsCompositeEmoji();

    return self::$compositeEmojiReason;
  }

  /**
   * Runs detection once at start-up and logs the outcome.
   *
   * @return void
   */
  public static function detect(): void
  {
    Debug::info(sprintf(
      'Terminal capabilities: composite emoji %s (%s).',
      self::supportsCompositeEmoji() ? 'supported' : 'unsupported',
      self::getCompositeEmojiReason()
    ));
  }

  /**
   * Clears the cache (tests and terminal changes).
   *
   * @return void
   */
  public static function reset(): void
  {
    self::$supportsCompositeEmoji = null;
    self::$compositeEmojiReason = 'not detected';
  }

  /**
   * Resolves composite-emoji support.
   *
   * @return array{0: bool, 1: string} The answer and the reason for it.
   */
  protected static function detectCompositeEmojiSupport(): array
  {
    $program = trim(strval(getenv('TERM_PROGRAM') ?: ''));

    if ($program !== '') {
      if (in_array($program, self::COMPOSING_TERMINALS, true)) {
        return [true, sprintf('%s composes ZWJ sequences', $program)];
      }

      if (in_array($program, self::NON_COMPOSING_TERMINALS, true)) {
        return [false, sprintf('%s draws ZWJ sequences as separate glyphs', $program)];
      }
    }

    // Terminals that identify themselves by their own variables rather than
    // TERM_PROGRAM.
    foreach (['WT_SESSION' => 'Windows Terminal', 'KITTY_WINDOW_ID' => 'kitty', 'ALACRITTY_WINDOW_ID' => 'Alacritty'] as $variable => $name) {
      if (getenv($variable) !== false) {
        return [true, sprintf('%s composes ZWJ sequences', $name)];
      }
    }

    $probed = self::probeCompositeEmojiWidth();

    if ($probed !== null) {
      return [$probed === 2, sprintf('measured %d columns for a composite glyph', $probed)];
    }

    return [false, 'unrecognised terminal; using the safe default'];
  }

  /**
   * Reads the explicit project override, if any.
   *
   * @return bool|null The override, or null when the project defers to detection.
   */
  protected static function configuredOverride(): ?bool
  {
    if (ConfigStore::doesntHave(ProjectConfig::class)) {
      return null;
    }

    $configured = ConfigStore::get(ProjectConfig::class)->get(self::CONFIG_COMPOSITE_EMOJI, 'auto');

    if (is_bool($configured)) {
      return $configured;
    }

    return match (strtolower(trim(strval($configured)))) {
      'true', 'yes', 'on', '1' => true,
      'false', 'no', 'off', '0' => false,
      default => null,
    };
  }

  /**
   * Measures how many columns the terminal advances for a composite glyph.
   *
   * Draws the glyph off-screen, asks the terminal where the cursor ended up
   * (CSI 6n), then erases. Returns null when the terminal does not answer,
   * which is treated as "unknown" rather than "unsupported".
   *
   * @return int|null The measured column advance, or null when unknown.
   */
  protected static function probeCompositeEmojiWidth(): ?int
  {
    if (! Console::isTerminalOutputEnabled() || ! stream_isatty(STDOUT) || ! stream_isatty(STDIN)) {
      return null;
    }

    $previousSettings = shell_exec('stty -g 2>/dev/null');

    if (! is_string($previousSettings) || trim($previousSettings) === '') {
      return null;
    }

    shell_exec('stty raw -echo 2>/dev/null');

    try {
      // Park the cursor on the last row so the probe cannot disturb the
      // visible frame, write the glyph, and ask where we ended up.
      echo "\033[s\033[999;1H";
      echo "\u{1F3C3}\u{200D}\u{27A1}\u{FE0F}";
      echo "\033[6n";

      $response = self::readProbeResponse();

      // Erase the probe row and restore the cursor.
      echo "\033[999;1H\033[2K\033[u";

      if (! preg_match('/\033\[\d+;(\d+)R/', $response, $matches)) {
        return null;
      }

      // The response column is 1-based, and the cursor started at column 1.
      return max(0, intval($matches[1]) - 1);
    } finally {
      shell_exec('stty ' . trim($previousSettings) . ' 2>/dev/null');
    }
  }

  /**
   * Reads a cursor-position report, giving up after a short wait.
   *
   * @return string The raw response.
   */
  protected static function readProbeResponse(): string
  {
    $response = '';
    $deadline = microtime(true) + (self::PROBE_TIMEOUT_MICROSECONDS / 1_000_000);

    while (microtime(true) < $deadline) {
      $read = [STDIN];
      $write = null;
      $except = null;

      if (stream_select($read, $write, $except, 0, 10_000) < 1) {
        continue;
      }

      $chunk = fread(STDIN, 32);

      if (is_string($chunk)) {
        $response .= $chunk;
      }

      if (str_contains($response, 'R')) {
        break;
      }
    }

    return $response;
  }
}
