<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use InvalidArgumentException;

/**
 * Covers and reveals the screen between two views.
 *
 * A terminal has no alpha, so a fade is approximated with the block shades:
 * the screen fills with progressively heavier blocks on the way out and
 * empties on the way back in. A wipe sweeps solid columns across instead.
 *
 * Transitions are opt in: a straight cut is snappier, and an effect between
 * every doorway wears thin fast. A project turns them on with
 * `ui.transitions.style`. Battle entry has its independent
 * `ui.transitions.battle` policy; omission preserves the older shared gate.
 * Players can change either in the config menu. Reduced motion skips both.
 *
 * @package Ichiloto\Engine\Rendering
 */
class ScreenTransition
{
  /**
   * The config path of the transition style.
   */
  public const string CONFIG_STYLE = 'ui.transitions.style';
  public const string CONFIG_BATTLE = 'ui.transitions.battle';
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
    protected(set) TransitionStyle $style = TransitionStyle::NONE,
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
      config(ProjectConfig::class, self::CONFIG_STYLE, TransitionStyle::NONE->value)
    ))) ?? TransitionStyle::NONE;

    return new self(
      $style,
      max(0, intval(config(ProjectConfig::class, self::CONFIG_DURATION, self::DEFAULT_DURATION_MS)))
    );
  }

  /** Explicit battle policy is independent; older projects retain their existing shared setting. */
  public static function isBattleEnabled(): bool
  {
    return (bool) config(ProjectConfig::class, self::CONFIG_BATTLE,
      config(ProjectConfig::class, self::CONFIG_STYLE) !== TransitionStyle::NONE->value);
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

  public function session(string $direction = 'out'): ScreenTransitionSession
  {
    $frames = $this->frames();

    if (strtolower($direction) === 'in') {
      $frames = array_reverse($frames);
    }

    return new ScreenTransitionSession($this, $frames, revealing: strtolower($direction) === 'in');
  }

  /** Paints an animated or settled cover; timing and reduced motion belong to its session. */
  public function composeCover(int $width, int $height, float $coverage = 1): ?PresentationCanvas
  {
    if (!is_finite($coverage) || $coverage < 0 || $coverage > 1) {
      throw new InvalidArgumentException('Transition coverage must be finite and in 0..1.');
    }
    if ($this->style === TransitionStyle::NONE) { return null; }
    $composites = [];
    if ($coverage > 0) {
      $columns = $this->style === TransitionStyle::WIPE ? ceil($width * $coverage) : $width;
      $composites[] = new CanvasComposite('screen-transition-cover', 1, 1,
        new CanvasRectangle(0, 0, $columns, $height), [new CanvasCompositeOperation([
          'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
          'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 0, 'g' => 0, 'b' => 0]],
        ])], PresentationLayerPolicy::TRANSITION,
        $this->style === TransitionStyle::FADE ? $coverage : 1);
    }
    return new PresentationCanvas($width, $height, composites: $composites, protectedAreas: [],
      presentationOwners: ['cinematic-cover', 'transition']);
  }

  /** Graphical appearance is authored data; lifecycle and reduced motion remain shared engine policy. */
  public static function startHandoff(ScreenTransitionTreatment $treatment, callable $present,
    callable $handoff, callable $ready, callable $cleanup, bool $enabled = true): ScreenTransitionSession
  {
    return new ScreenTransitionSession($treatment, present: $present, handoff: $handoff, ready: $ready,
      cleanup: $cleanup, enabled: $enabled && !Accessibility::prefersReducedMotion());
  }

  public function renderFrame(string $fill, int $columns): void
  {
    $this->drawFrame($fill, $columns);
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

    $pause = $this->durationMs / count($frames) / 1000;

    foreach ($frames as [$fill, $columns]) {
      $this->drawFrame($fill, $columns);

      if ($pause > 0) {
        Timers::wait($pause);
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
    // Disabled and reduced-motion transitions are true no-ops. Avoid asking
    // the terminal for dimensions when no frame can ever be rendered; this
    // also keeps headless and pre-screen cinematic hosts independent of
    // PlaySettings.
    if (! $this->isEnabled()) {
      return [];
    }

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

    Console::withLayer('transition', function () use ($row, $height): void {
      for ($y = 0; $y < $height; $y++) {
        Console::write($row, 0, $y);
      }
    }, PresentationLayerPolicy::TRANSITION);

    Console::endFrame();
  }
}
