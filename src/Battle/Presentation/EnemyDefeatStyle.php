<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Bounded, themeable treatment; no combat or audio state is stored here. */
final readonly class EnemyDefeatStyle
{
  public function __construct(public int $pulses = 3, public float $pulseSeconds = .12,
    public float $fadeSeconds = .25, public array $color = [255, 64, 64], public bool $audio = true)
  {
    if ($pulses < 1 || $pulses > 8 || !is_finite($pulseSeconds) || $pulseSeconds < .05 || $pulseSeconds > .5
      || !is_finite($fadeSeconds) || $fadeSeconds < .05 || $fadeSeconds > 2
      || !array_is_list($color) || count($color) !== 3 || array_any($color, static fn($v) => !is_int($v))) {
      throw new InvalidArgumentException('Enemy defeat needs 1..8 pulses, .05.. .5 second pulses, .05..2 second fade and RGB color.');
    }
    PresentationColor::rgb(...$color);
  }

  public static function createFromConfig(): self
  {
    return self::createFromArray(\Ichiloto\Engine\Util\Config\ConfigStore::has(\Ichiloto\Engine\Util\Config\ProjectConfig::class)
      ? config(\Ichiloto\Engine\Util\Config\ProjectConfig::class, 'ui.battle.defeat', []) : []);
  }

  public static function createFromArray(array $data): self
  {
    if (array_diff(array_keys($data), ['pulses', 'pulseSeconds', 'fadeSeconds', 'color', 'audio']) !== []) {
      throw new InvalidArgumentException('Unknown enemy defeat setting.');
    }
    return new self(...$data);
  }

  public function getDurationSeconds(): float { return $this->pulses * $this->pulseSeconds + $this->fadeSeconds; }

  /** Same lifetime in both motion modes; reduced motion fades without any pulse. */
  public function getTreatment(float $elapsed, bool $reducedMotion): array
  {
    if (!is_finite($elapsed)) { throw new InvalidArgumentException('Defeat elapsed time must be finite.'); }
    $pulseEnd = $this->pulses * $this->pulseSeconds;
    $duration = $this->getDurationSeconds();
    return ['visible' => $elapsed < $duration, 'opacity' => $elapsed < 0 ? 1.0
      : 1 - clamp($reducedMotion ? $elapsed / $duration : ($elapsed - $pulseEnd) / $this->fadeSeconds, 0, 1),
      'pulse' => !$reducedMotion && $elapsed >= 0 && $elapsed < $pulseEnd
        && fmod($elapsed, $this->pulseSeconds) < $this->pulseSeconds / 2,
      'color' => PresentationColor::rgb(...$this->color)];
  }

  /** Terminal has no alpha; a steady dim phase precedes removal, never a KO label. */
  public static function applyTerminalTreatment(array $sprite, ?array $treatment): array
  {
    if ($treatment === null) { return $sprite; }
    if (!$treatment['visible']) { return []; }
    if (!$treatment['pulse'] && $treatment['opacity'] === 1.0) { return $sprite; }
    $color = $treatment['color']->toArray();
    $pulse = sprintf("\033[38;2;%d;%d;%dm", $color['r'], $color['g'], $color['b']);
    return array_map(static fn(string $line): string =>
      ($treatment['pulse'] ? $pulse : "\033[2m")
        . TerminalText::stripAnsi($line) . Color::RESET->value, $sprite);
  }
}
