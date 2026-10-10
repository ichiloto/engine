<?php

namespace Ichiloto\Engine\Field;

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
  )
  {
  }

  /**
   * Reads an authored NPC entry, or null for one the field skips: not an
   * array, no name, or no coordinates.
   */
  public static function fromArray(mixed $entry): ?self
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
    $hasLines = static fn(mixed $lines): bool => array_filter((array) $lines, is_array(...)) !== [];

    return new self(
      $name,
      $id !== '' ? $id : null,
      intval($entry['x']),
      intval($entry['y']),
      strval($entry['movement'] ?? 'fixed') === 'wander',
      $wanderArea,
      $hasLines($entry['dialogue'] ?? []) || $hasLines($entry['script'] ?? []),
      $hasLines($entry['conditions'] ?? []),
    );
  }
}
