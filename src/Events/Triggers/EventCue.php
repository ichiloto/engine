<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\CellArea;
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
  /** Null preserves older unclassified cues without inventing a story decision. */
  public ?EventCueKind $kind;
  /**
   * @param array<int, array<string, mixed>> $conditions Optional world-state
   * conditions controlling presentation independently of trigger availability.
   */
  public function __construct(
    public string $symbol = '!',
    public string $color = 'bright-yellow',
    public array $conditions = [],
    ?string $kind = null,
  )
  {
    $this->kind = $kind === null ? null : EventCueKind::tryFrom($kind);
    if ($kind !== null && $this->kind === null) {
      throw new InvalidArgumentException('Event cue kind must be story or route.');
    }
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

  /** @param array{symbol?: mixed, color?: mixed, conditions?: mixed, kind?: mixed}|null $data */
  public static function fromArray(?array $data): ?self
  {
    if ($data === null) {
      return null;
    }

    if (isset($data['kind']) && !is_string($data['kind'])) {
      throw new InvalidArgumentException('Event cue kind must be story or route.');
    }
    if (array_key_exists('kind', $data) && $data['kind'] === null) {
      throw new InvalidArgumentException('An authored event cue kind must be story or route.');
    }
    if (isset($data['kind']) && EventCueKind::tryFrom($data['kind']) === null) {
      throw new InvalidArgumentException('Event cue kind must be story or route.');
    }

    $symbol = trim(strval($data['symbol'] ?? ''));
    if ($symbol === '') {
      return null;
    }

    return new self(
      $symbol,
      trim(strval($data['color'] ?? 'bright-yellow')),
      array_values(array_filter((array) ($data['conditions'] ?? []), 'is_array')),
      $data['kind'] ?? null,
    );
  }

  /**
   * Returns the world-space cells the cue shows on: one per separate
   * placement of the trigger, at the middle of that placement's own cells,
   * so a marker painted in two places is cued in both.
   *
   * @return list<Vector2>
   */
  public function findPositions(CellArea $area): array
  {
    return array_map(static fn(CellArea $piece): Vector2 => $piece->findCenterCell(), $area->findPieces());
  }

  /** Returns Symfony Console formatter markup understood by TerminalText. */
  public function styledSymbol(): string
  {
    return sprintf('<fg=%s>%s</>', $this->color, $this->symbol);
  }
}
