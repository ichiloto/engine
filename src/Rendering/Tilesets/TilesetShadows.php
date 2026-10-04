<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/**
 * The shadow a tileset's raised tiles cast on the cell to their right, as
 * RPG Maker's auto-shadow does: a translucent black band over the left of
 * that cell. Purely graphical; collision, terminal cells and saves never see
 * it. Read from the tileset's `shadows` key:
 * `['casters' => [tile identities], 'width' => 0.5, 'opacity' => 0.35]`.
 */
final readonly class TilesetShadows
{
  private const array FIELDS = ['casters', 'width', 'opacity'];

  /**
   * @param list<int> $casters Flag identities of the tiles that cast, one per autotile kind.
   * @param float $width The band's width as a fraction of a cell, above 0 and at most 1.
   * @param float $opacity The band's opacity, above 0 and at most 1.
   */
  public function __construct(public array $casters, public float $width, public float $opacity)
  {
    if ($casters === []) {
      throw new InvalidArgumentException('Tileset shadows need at least one caster.');
    }
    foreach (['width' => $width, 'opacity' => $opacity] as $name => $value) {
      if (!is_finite($value) || $value <= 0.0 || $value > 1.0) {
        throw new InvalidArgumentException("Tileset shadow {$name} must be above 0 and at most 1.");
      }
    }
  }

  /** @param callable(mixed, string): list<int> $readIdentities Reads a list of flag identities. */
  public static function fromArray(mixed $data, string $context, callable $readIdentities): self
  {
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== [] || count($data) !== count(self::FIELDS)) {
      throw new InvalidArgumentException("{$context} shadows need exactly " . implode(', ', self::FIELDS) . '.');
    }
    foreach (['width', 'opacity'] as $name) {
      if (!is_int($data[$name]) && !is_float($data[$name])) {
        throw new InvalidArgumentException("{$context} shadow {$name} must be a number.");
      }
    }
    return new self($readIdentities($data['casters'], "{$context} shadow casters"), (float)$data['width'], (float)$data['opacity']);
  }

  public function isCaster(int $id): bool
  {
    return in_array(TileId::getFlagId($id), $this->casters, true);
  }
}
