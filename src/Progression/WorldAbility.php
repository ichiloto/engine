<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use InvalidArgumentException;

/** A project-owned exploration power; consuming features reference its stable identity. */
final readonly class WorldAbility
{
  /** @param list<array<string,mixed>> $conditions */
  public function __construct(public string $id, public string $name, public string $description = '',
    public array $conditions = [], public ?string $iconRole = null)
  {
    foreach ([$id, $name, ...($iconRole === null ? [] : [$iconRole])] as $value) {
      if (trim($value) === '' || $value !== trim($value)) {
        throw new InvalidArgumentException('World ability identities, names and icon roles require nonempty trimmed text.');
      }
    }
    if (!array_is_list($conditions)) { throw new InvalidArgumentException('World ability conditions must be a list.'); }
    WorldConditionEvaluator::validateAll($conditions, 'world ability ' . $id . ' conditions');
  }

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['id', 'name', 'description', 'conditions', 'iconRole']) !== []
      || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null)
      || (isset($data['description']) && !is_string($data['description']))
      || (isset($data['conditions']) && !is_array($data['conditions']))
      || (isset($data['iconRole']) && !is_string($data['iconRole']))) {
      throw new InvalidArgumentException('A world ability declares id, name, optional description, conditions and iconRole.');
    }
    return new self($data['id'], $data['name'], $data['description'] ?? '', $data['conditions'] ?? [], $data['iconRole'] ?? null);
  }
}
