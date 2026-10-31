<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;

/**
 * Where an authored NPC entry stands and how it occupies the field: the
 * gameplay facts the field and reachability read from an entry the same way.
 *
 * @package Ichiloto\Engine\Field
 */
final readonly class NpcPlacement
{
  /**
   * @param array{x: int, y: int, width: int, height: int}|null $wanderArea
   */
  private function __construct(
    public string $name,
    public ?string $id,
    public int $x,
    public int $y,
    public bool $wanders,
    public ?array $wanderArea,
    public bool $isTalkable,
    public bool $isConditional,
    public array $conditions,
    public ?NpcPlacementSet $placements = null,
  )
  {
  }

  /**
   * Reads an authored NPC entry, or null for one the field skips: not an
   * array, no name, or no coordinates.
   */
  public static function fromArray(mixed $entry, ?GameState $state = null, ?string $mapId = null): ?self
  {
    if (! is_array($entry)) {
      return null;
    }

    $name = trim(strval($entry['name'] ?? ''));

    if ($name === '' || ! isset($entry['x'], $entry['y'])) {
      return null;
    }

    $wanderArea = null;

    if (is_array($entry['wanderArea'] ?? null)) {
      $wanderArea = [
        'x' => intval($entry['wanderArea']['x'] ?? 0),
        'y' => intval($entry['wanderArea']['y'] ?? 0),
        'width' => max(1, intval($entry['wanderArea']['width'] ?? 1)),
        'height' => max(1, intval($entry['wanderArea']['height'] ?? 1)),
      ];
    }

    $id = trim(strval($entry['id'] ?? ''));
    $placements = array_key_exists('placements', $entry) ? NpcPlacementSet::fromEntry($entry) : null;
    if ($placements === null && $id !== '' && $state !== null && $mapId !== null
      && $state->getVariable(NpcPlacementSet::getStateKey($mapId, $id), NpcPlacementSet::DEFAULT) !== NpcPlacementSet::DEFAULT) {
      throw new \InvalidArgumentException('The NPC no longer declares its saved placements; migrate the selection explicitly.');
    }
    $position = $placements === null ? null : $placements->placements[
      $state !== null && $mapId !== null ? $placements->readSelection($state, $mapId, $id) : NpcPlacementSet::DEFAULT];
    $hasLines = static fn(mixed $lines): bool => array_filter((array) $lines, is_array(...)) !== [];
    $conditions = array_values(array_filter((array) ($entry['conditions'] ?? []), is_array(...)));

    return new self(
      $name,
      $id !== '' ? $id : null,
      $position['x'] ?? intval($entry['x']),
      $position['y'] ?? intval($entry['y']),
      strval($entry['movement'] ?? 'fixed') === 'wander',
      $wanderArea,
      $hasLines($entry['dialogue'] ?? []) || $hasLines($entry['script'] ?? []),
      // A bed's x/y is its standing exit; saved posture determines whether the NPC occupies it.
      $conditions !== [] || array_key_exists('bed', $entry) || $placements !== null,
      $conditions,
      $placements,
    );
  }
  /** @return list<array{int,int}> Source positions used by static reachability, including every saved choice. */
  public function getStandingCells(): array
  {
    return $this->placements === null ? [[$this->x, $this->y]] : array_map(
      static fn(array $position): array => [$position['x'], $position['y']], array_values($this->placements->placements));
  }
}
