<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * A region of the project's mastery tree (a game might name them after card
 * suits, schools or disciplines): the trunk its branches grow from. The
 * Engine gives regions no meaning of its own. A region's own nodes sit on
 * its trunk, outside any branch, as parallel leaves: an author places them
 * on a branch once its chain is known.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryRegion
{
  /** The keys a region's data may hold. */
  public const array KEYS = ['id', 'name', 'description', 'nodes'];

  /** @param list<MasteryNodeDefinition> $nodes The nodes on its trunk, in the order menus list them. */
  public function __construct(
    public string $id,
    public string $name,
    public string $description = '',
    public array $nodes = [],
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery region needs an id without surrounding spaces.');
    }
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    $id = strval($data['id'] ?? '');
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Mastery region %s has unknown key "%s".', $id, $key));
      }
    }
    $nodes = $data['nodes'] ?? [];
    if (! is_array($nodes) || ! array_is_list($nodes)) {
      throw new InvalidArgumentException(sprintf('Mastery region %s nodes must be a list.', $id));
    }

    return new self($id, strval($data['name'] ?? $data['id'] ?? ''), strval($data['description'] ?? ''),
      array_map(static fn(mixed $node): MasteryNodeDefinition => MasteryNodeDefinition::fromArray(is_array($node) ? $node : [], $id), $nodes));
  }
}
