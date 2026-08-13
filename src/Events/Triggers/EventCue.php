<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * Optional map presentation for a currently available event trigger.
 *
 * Cues are authored separately from event-layer marker letters: marker
 * letters are map-data identities, while this one-cell glyph is deliberate
 * player guidance. It never changes collision or trigger availability.
 */
final readonly class EventCue
{
  /**
   * @param array<int, array<string, mixed>> $conditions Optional world-state
   * conditions controlling presentation independently of trigger availability.
   */
  public function __construct(
    public string $symbol = '!',
    public string $color = 'bright-yellow',
    public array $conditions = [],
  )
  {
    if (TerminalText::symbolCount($this->symbol) !== 1 || TerminalText::displayWidth($this->symbol) !== 1) {
      throw new InvalidArgumentException('Event cue symbols must occupy exactly one terminal cell.');
    }

    try {
      new OutputFormatterStyle($this->color);
    } catch (InvalidArgumentException $exception) {
      throw new InvalidArgumentException(sprintf('Invalid event cue color "%s".', $this->color), previous: $exception);
    }

    foreach ($this->conditions as $condition) {
      if (! is_array($condition)) {
        throw new InvalidArgumentException('Event cue conditions must be arrays.');
      }
    }
  }

  /** @param array{symbol?: mixed, color?: mixed, conditions?: mixed}|null $data */
  public static function fromArray(?array $data): ?self
  {
    if ($data === null) {
      return null;
    }

    $symbol = trim(strval($data['symbol'] ?? ''));
    if ($symbol === '') {
      return null;
    }

    return new self(
      $symbol,
      trim(strval($data['color'] ?? 'bright-yellow')),
      array_values(array_filter((array) ($data['conditions'] ?? []), 'is_array')),
    );
  }

  /** Returns the world-space cell centered inside the trigger area. */
  public function positionFor(Rect $area): Vector2
  {
    return new Vector2(
      $area->getX() + intdiv(max(1, $area->getWidth()) - 1, 2),
      $area->getY() + intdiv(max(1, $area->getHeight()) - 1, 2),
    );
  }

  /** Returns Symfony Console formatter markup understood by TerminalText. */
  public function styledSymbol(): string
  {
    return sprintf('<fg=%s>%s</>', $this->color, $this->symbol);
  }
}
