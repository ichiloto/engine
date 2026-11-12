<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Rendering\FieldViewport;
use InvalidArgumentException;

/**
 * A kind of foliage a character walks through hidden to the waist or the
 * ankles, such as woodland or marsh reeds, as RPG Maker's bush flag does but
 * authored per foliage type with its depth: the lower `depth` field pixels of
 * a character standing in it are drawn behind the foliage, masked
 * (`opacity` 0) or see-through (`opacity` above 0), and while the character
 * moves the foliage around it rustles. Purely graphical: collision, movement,
 * encounters, the terminal and saves never see it.
 *
 * Read from a tileset's `thickets`, keyed by thicket id. A terrain kind names
 * the thicket its own autotile is ({@see TerrainKind}); a thicket's `tiles`
 * list any other tile identities that are one, as RPG Maker flags a tile:
 *
 * ```php
 * 'thickets' => [
 *   'woodland' => ['name' => 'Woodland', 'depth' => 24, 'opacity' => 0.0],
 *   'reeds' => ['name' => 'Marsh reeds', 'depth' => 12, 'opacity' => 0.5, 'tiles' => [1544]],
 * ],
 * ```
 *
 * `rustle` (true unless authored false) says whether the foliage rustles
 * around a character walking through it; reduced motion never rustles it.
 */
final readonly class TilesetThicket
{
  public const string DATA_KEY = 'thickets';
  public const array FIELDS = ['name', 'depth', 'opacity', 'rustle', 'tiles'];
  /** The deepest a thicket hides a character, in field pixels: one whole field cell. */
  public const int MAX_DEPTH = FieldViewport::TILE_SIZE;

  /**
   * @param int $depth Field pixels of a character hidden, measured up from its feet, 1 to {@see MAX_DEPTH}.
   * @param float $opacity How opaque the hidden part is drawn, from 0 (masked) to below 1.
   * @param bool $rustle Whether the foliage rustles around a character walking through it.
   * @param list<int> $tiles Flag identities of the tiles, other than kinds' own autotiles, that are this thicket.
   */
  public function __construct(
    public string $id,
    public string $name,
    public int $depth,
    public float $opacity,
    public bool $rustle = true,
    public array $tiles = [],
  ) {
    if ($depth < 1 || $depth > self::MAX_DEPTH) {
      throw new InvalidArgumentException("Thicket {$id} depth must be 1 to " . self::MAX_DEPTH . ' field pixels, measured up from a character\'s feet.');
    }
    if (!is_finite($opacity) || $opacity < 0.0 || $opacity >= 1.0) {
      throw new InvalidArgumentException("Thicket {$id} opacity must be from 0 (the hidden part masked) to below 1.");
    }
  }

  /**
   * Reads a tileset's `thickets`, keyed by thicket id, refusing a tile two
   * thickets claim.
   *
   * @param callable(mixed, string): list<int> $readIdentities Reads a list of flag identities.
   * @return array<string, self> By thicket id, in authored order.
   */
  public static function readAll(mixed $data, string $context, callable $readIdentities): array
  {
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new InvalidArgumentException("{$context} thickets must be keyed by thicket id.");
    }
    $thickets = $claimed = [];
    foreach ($data as $id => $definition) {
      $id = (string)$id;
      $where = "{$context} thicket {$id}";
      if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
        throw new InvalidArgumentException("{$where}: a thicket id is lowercase letters, digits, hyphens or underscores.");
      }
      if (!is_array($definition) || array_diff(array_keys($definition), self::FIELDS) !== []) {
        throw new InvalidArgumentException("{$where} must be an array of " . implode(', ', self::FIELDS) . '.');
      }
      if (!is_string($definition['name'] ?? null) || trim($definition['name']) === '') {
        throw new InvalidArgumentException("{$where} needs a name.");
      }
      if (!is_int($definition['depth'] ?? null)) {
        throw new InvalidArgumentException("{$where} needs its depth: the field pixels of a character it hides, up from its feet.");
      }
      $opacity = $definition['opacity'] ?? null;
      if (!is_int($opacity) && !is_float($opacity)) {
        throw new InvalidArgumentException("{$where} needs the opacity its hidden part is drawn at: 0 masks it.");
      }
      if (!is_bool($definition['rustle'] ?? true)) {
        throw new InvalidArgumentException("{$where} rustle is true or false.");
      }
      $tiles = $readIdentities($definition['tiles'] ?? [], "{$where} tiles");
      foreach ($tiles as $tile) {
        if (isset($claimed[$tile])) {
          throw new InvalidArgumentException("{$context} thickets {$claimed[$tile]} and {$id} both list tile {$tile}; a tile is one thicket.");
        }
        $claimed[$tile] = $id;
      }
      try {
        $thickets[$id] = new self($id, $definition['name'], $definition['depth'], (float)$opacity, $definition['rustle'] ?? true, $tiles);
      } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException("{$context}: " . $error->getMessage(), previous: $error);
      }
    }
    return $thickets;
  }
}
