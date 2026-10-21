<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * A region of every character's Mastery Map, declared by the project: the
 * neighbourhoods its nodes sit in (a game might name them after card suits,
 * schools or disciplines). The Engine gives regions no meaning of its own.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryRegion
{
  public function __construct(
    public string $id,
    public string $name,
    public string $description = '',
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery region needs an id without surrounding spaces.');
    }
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    return new self(strval($data['id'] ?? ''), strval($data['name'] ?? $data['id'] ?? ''), strval($data['description'] ?? ''));
  }
}
