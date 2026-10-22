<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * One node of a character's Mastery Map: a skill the character can learn
 * there. It names the skill by its catalogue identity and owns no copy of it.
 * A node has a price in Mastery points (SP), opens at a level once the nodes
 * it requires are learned, and may stay a secret ("???") until then. Its
 * position places it on a graphical map and means nothing to its
 * progression. Nodes are listed in the order their map file gives them.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryNode
{
  /** The keys a node's data may hold. */
  public const array KEYS = ['id', 'skill', 'region', 'cost', 'opensAtLevel', 'requires', 'position', 'hidden'];

  /**
   * @param string $id The node's identity, stable across renames and moves.
   * @param string $skill The skill it teaches, by catalogue name.
   * @param string $region The region it sits in.
   * @param int $cost The Mastery points (SP) it takes to learn; 0 is learned as soon as it opens.
   * @param int $opensAtLevel The character level it opens at.
   * @param list<string> $requires The nodes that must be learned first.
   * @param array{x: float, y: float}|null $position Where a graphical map draws it, as fractions (0 to 1) of the map's width and height.
   * @param bool $hidden Whether menus keep it a secret ("???") while it is locked.
   */
  public function __construct(
    public string $id,
    public string $skill,
    public string $region,
    public int $cost = 0,
    public int $opensAtLevel = 1,
    public array $requires = [],
    public ?array $position = null,
    public bool $hidden = false,
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery node needs an id without surrounding spaces.');
    }
    if (trim($skill) === '') {
      throw new InvalidArgumentException(sprintf('Mastery node %s names no skill.', $id));
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
   * @throws InvalidArgumentException When the entry is malformed or holds a key nodes do not have.
   */
  public static function fromArray(array $data): self
  {
    $id = strval($data['id'] ?? '');
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Mastery node %s has unknown key "%s".', $id, $key));
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
    $requires = $data['requires'] ?? [];
    if (! is_array($requires) || ! array_is_list($requires) || array_filter($requires, static fn(mixed $node): bool => ! is_string($node)) !== []) {
      throw new InvalidArgumentException(sprintf('Mastery node %s requires must list node ids.', $id));
    }
    $position = $data['position'] ?? null;
    if ($position !== null && (! is_array($position) || ! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)
      || $position['x'] < 0 || $position['x'] > 1 || $position['y'] < 0 || $position['y'] > 1)) {
      throw new InvalidArgumentException(sprintf('Mastery node %s position must give x and y between 0 and 1.', $id));
    }

    return new self(
      $id,
      strval($data['skill'] ?? ''),
      strval($data['region'] ?? ''),
      $data['cost'] ?? 0,
      $data['opensAtLevel'] ?? 1,
      array_values($requires),
      $position === null ? null : ['x' => floatval($position['x']), 'y' => floatval($position['y'])],
      $data['hidden'] ?? false,
    );
  }
}
