<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Covers and reveals the screen between two views.
 *
 * A terminal has no alpha, so a fade is approximated with the block shades:
 * the screen fills with progressively heavier blocks on the way out and
 * empties on the way back in. A wipe sweeps solid columns across instead.
 *
 * Motion is a preference, so a project can turn transitions off with
 * `ui.transitions.style`, and a player who has asked for reduced motion never
 * sees one regardless.
 *
 * @package Ichiloto\Engine\Rendering
 */
class ScreenTransition
{
  /**
   * The config path of the transition style.
   */
  public const string CONFIG_STYLE = 'ui.transitions.style';
  /**
   * The config path of the transition duration, in milliseconds.
   */
  public const string CONFIG_DURATION = 'ui.transitions.duration';
  /**
   * The duration used when a project does not set one.
   */
  public const int DEFAULT_DURATION_MS = 240;

  /**
   * The shades a fade steps through, lightest first.
   */
  protected const array FADE_SHADES = ['░', '▒', '▓', '█'];

  /**
   * @param TransitionStyle $style The effect to play.
   * @param int $durationMs How long a single direction takes.
   */
  public function __construct(
    protected(set) TransitionStyle $style = TransitionStyle::FADE,
    protected(set) int $durationMs = self::DEFAULT_DURATION_MS,
  )
  {
  }

  /**
   * Builds the transition the project asked for.
   *
   * @return self The configured transition.
   */
  public static function fromConfig(): self
  {
    $style = TransitionStyle::tryFrom(strtolower(strval(
      config(ProjectConfig::class, self::CONFIG_STYLE, TransitionStyle::FADE->value)
    ))) ?? TransitionStyle::FADE;

    return new self(
      $style,
      max(0, intval(config(ProjectConfig::class, self::CONFIG_DURATION, self::DEFAULT_DURATION_MS)))
    );
  }

  /**
   * Whether this transition draws anything at all.
   *
   * @return bool True when the effect plays.
   */
  public function isEnabled(): bool
  {
    return $this->style !== TransitionStyle::NONE
      && $this->durationMs > 0
      && ! Accessibility::prefersReducedMotion();
  }

  /**
   * Covers the screen.
   *
   * @return void
   */
  public function out(): void
  {
    $this->play($this->frames());
  }

  /**
   * Reveals the screen again.
   *
   * @param callable|null $redraw Called once the cover is gone, to put the
   * view back. Without it the caller is expected to redraw itself.
   * @return void
   */
  public function in(?callable $redraw = null): void
  {
    $this->play(array_reverse($this->frames()));

    if ($this->isEnabled()) {
      Console::clear();
    }

    if ($redraw !== null) {
      $redraw();
    }
  }

  /**
   * Draws each frame in turn, pausing between them.
   *
   * @param array<int, array{0: string, 1: int}> $frames The frames, each a
   * fill character and the number of columns it covers.
   * @return void
   */
  protected function play(array $frames): void
  {
    if (! $this->isEnabled() || $frames === []) {
      return;
    }

    $pause = (int)((($this->durationMs / count($frames)) * 1000));

    foreach ($frames as [$fill, $columns]) {
      $this->drawFrame($fill, $columns);

      if ($pause > 0) {
        usleep($pause);
      }
    }
  }

  /**
   * Returns the frames of a single direction, lightest first.
   *
   * @return array<int, array{0: string, 1: int}> The frames.
   */
  protected function frames(): array
  {
    $width = get_screen_width();

    if ($this->style === TransitionStyle::WIPE) {
      $steps = max(1, min(24, $width));

      return array_map(
        static fn(int $step): array => ['█', (int)ceil($width * ($step + 1) / $steps)],
        range(0, $steps - 1)
      );
    }

    return array_map(
      static fn(string $shade): array => [$shade, $width],
      self::FADE_SHADES
    );
  }

  /**
   * Fills the screen with one frame.
   *
   * @param string $fill The character to fill with.
   * @param int $columns How many columns to cover, from the left.
   * @return void
   */
  protected function drawFrame(string $fill, int $columns): void
  {
    $height = get_screen_height();
    $row = str_repeat($fill, max(0, $columns));

    Console::beginFrame();

    for ($y = 0; $y < $height; $y++) {
      Console::write($row, 0, $y);
    }

    Console::endFrame();
  }
}
