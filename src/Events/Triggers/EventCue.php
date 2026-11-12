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
  public const int MAX_DISTANCE = 64;
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
    public ?int $maxDistance = null,
  )
  {
    if ($maxDistance !== null && ($maxDistance < 0 || $maxDistance > self::MAX_DISTANCE)) {
      throw new InvalidArgumentException('Cue maxDistance must be an integer from 0 to 64 cells.');
    }
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

  /** @param array{symbol?: mixed, color?: mixed, conditions?: mixed, kind?: mixed, maxDistance?: mixed}|null $data */
  public static function fromArray(?array $data): ?self
  {
    if ($data === null) {
      return null;
    }

    if (array_key_exists('maxDistance', $data)
      && (!is_int($data['maxDistance']) || $data['maxDistance'] < 0 || $data['maxDistance'] > self::MAX_DISTANCE)) {
      throw new InvalidArgumentException('Cue maxDistance must be an integer from 0 to 64 cells.');
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
      $data['maxDistance'] ?? null,
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

  /** Range is measured in logical cardinal steps, independently for every separate cue placement. */
  public function isInRange(Vector2 $position, ?Vector2 $viewer): bool
  {
    return $this->maxDistance === null || ($viewer !== null
      && abs($position->x - $viewer->x) + abs($position->y - $viewer->y) <= $this->maxDistance);
  }

  /** @return list<Vector2> */
  public function findVisiblePositions(CellArea $area, ?Vector2 $viewer): array
  {
    return array_values(array_filter($this->findPositions($area), fn(Vector2 $cell): bool => $this->isInRange($cell, $viewer)));
  }

  /** Returns Symfony Console formatter markup understood by TerminalText. */
  public function styledSymbol(): string
  {
    return sprintf('<fg=%s>%s</>', $this->color, $this->symbol);
  }
}
