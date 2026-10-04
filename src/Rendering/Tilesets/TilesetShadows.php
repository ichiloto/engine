<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/**
 * The shadow a tileset's raised tiles cast on the cell to their right, as
 * RPG Maker's auto-shadow does: a translucent black band over the left of
 * that cell. Purely graphical; collision, terminal cells and saves never see
 * it. Read from the tileset's `shadows` key:
 * `['casters' => ['A3', 'A4', 5], 'width' => 0.5, 'opacity' => 0.35]`.
 * A caster is a whole sheet, such as RPG Maker's raised A3 buildings and A4
 * walls, so every wall painted from it casts, or one tile identity.
 */
final readonly class TilesetShadows
{
  private const array FIELDS = ['casters', 'width', 'opacity'];

  /**
   * @param list<TilesetSheet> $sheets The sheets whose every tile casts.
   * @param list<int> $tiles Flag identities of other tiles that cast, one per autotile kind.
   * @param float $width The band's width as a fraction of a cell, above 0 and at most 1.
   * @param float $opacity The band's opacity, above 0 and at most 1.
   */
  public function __construct(public array $sheets, public array $tiles, public float $width, public float $opacity)
  {
    if ($sheets === [] && $tiles === []) {
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
    $casters = $data['casters'];
    if (!is_array($casters) || !array_is_list($casters)) {
      throw new InvalidArgumentException("{$context} shadow casters must list sheets and tile identities.");
    }
    $sheets = [];
    foreach (array_filter($casters, is_string(...)) as $name) {
      $sheets[$name] = TilesetSheet::tryFrom($name)
        ?? throw new InvalidArgumentException("{$context} shadow casters: '{$name}' is not an RPG Maker sheet (A1 to A5, B to E).");
    }
    $tiles = $readIdentities(array_values(array_filter($casters, static fn(mixed $caster): bool => !is_string($caster))),
      "{$context} shadow casters");
    return new self(array_values($sheets), $tiles, (float)$data['width'], (float)$data['opacity']);
  }

  public function isCaster(int $id): bool
  {
    return in_array(TileId::getSheet($id), $this->sheets, true) || in_array(TileId::getFlagId($id), $this->tiles, true);
  }
}
