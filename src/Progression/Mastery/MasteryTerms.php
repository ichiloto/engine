<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * One character's own terms for one node of the mastery tree, as their map
 * file states them: its price, the level it opens at, what it builds on for
 * them, whether menus keep it a secret, and where a graphical map draws it.
 * The node itself (its skill, region, branch and chain) stays the tree's.
 *
 * Terms replace the tree's terms for that character in full, including what
 * the node builds on: a character who starts partway along a branch learns
 * from where they are. Validation reports a chain step such terms skip.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryTerms
{
  /** The keys a map entry may hold. */
  public const array KEYS = ['id', 'cost', 'opensAtLevel', 'requires', 'position', 'hidden'];

  /**
   * @param string $id The tree node they are terms for.
   * @param int $cost The Mastery points (SP) it takes to learn; 0 is learned as soon as it opens.
   * @param int $opensAtLevel The character level it opens at.
   * @param list<string> $requires The nodes that must be learned first.
   * @param array{x: float, y: float}|null $position Where a graphical map draws it, as fractions (0 to 1) of the map's width and height.
   * @param bool $hidden Whether menus keep it a secret ("???") while it is locked.
   */
  public function __construct(
    public string $id,
    public int $cost = 0,
    public int $opensAtLevel = 1,
    public array $requires = [],
    public ?array $position = null,
    public bool $hidden = false,
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery map entry needs a node id without surrounding spaces.');
    }
    if ($cost < 0) {
      throw new InvalidArgumentException(sprintf('Mastery node %s cannot cost less than 0 points.', $id));
    }
    if ($opensAtLevel < 1) {
      throw new InvalidArgumentException(sprintf('Mastery node %s must open at level 1 or later.', $id));
    }
  }

  /**
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the entry is malformed or holds a key entries do not have.
   */
  public static function fromArray(array $data): self
  {
    $id = strval($data['id'] ?? '');
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(match ($key) {
          'skill', 'region' => sprintf('Mastery map entry %s names its %s, which the mastery tree now owns; run `ichiloto upgrade`.', $id, $key),
          default => sprintf('Mastery map entry %s has unknown key "%s".', $id, $key),
        });
      }
    }
    foreach (['cost', 'opensAtLevel'] as $key) {
      if (array_key_exists($key, $data) && ! is_int($data[$key])) {
        throw new InvalidArgumentException(sprintf('Mastery node %s %s must be a whole number.', $id, $key));
      }
    }
    if (array_key_exists('hidden', $data) && ! is_bool($data['hidden'])) {
      throw new InvalidArgumentException(sprintf('Mastery node %s hidden must be true or false.', $id));
    }
    $position = $data['position'] ?? null;
    if ($position !== null && (! is_array($position) || ! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)
      || $position['x'] < 0 || $position['x'] > 1 || $position['y'] < 0 || $position['y'] > 1)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s position must give x and y between 0 and 1.', $id));
    }

    return new self(
      $id,
      $data['cost'] ?? 0,
      $data['opensAtLevel'] ?? 1,
      MasteryNodeDefinition::readNodeIds($data['requires'] ?? [], $id),
      $position === null ? null : ['x' => floatval($position['x']), 'y' => floatval($position['y'])],
      $data['hidden'] ?? false,
    );
  }
}
