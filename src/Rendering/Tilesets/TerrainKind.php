<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapAtlasTerrain;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * One kind of terrain a classic world map is painted in, such as grass,
 * forest or mountains: a stable id and name, its terminal glyph and colour,
 * its graphical autotile, its passage on foot, its encounter terrain, its
 * atlas role and, for a feature such as a forest, the ground kind it stands
 * on. The kind is the cell's identity; its glyph and its autotile are two
 * independent presentations of it, and neither the glyph nor its colour is a
 * graphical tile identity.
 *
 * Read from a tileset's `kinds`, keyed by kind id:
 *
 * ```php
 * 'grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>',
 *   'art' => ['layer' => 'ground', 'tile' => 2816], 'passage' => 'walk', 'encounters' => 'grass', 'atlas' => 'ground'],
 * 'forest' => ['name' => 'Forest', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>', 'ground' => 'grass',
 *   'art' => ['layer' => 'features', 'tile' => 3200], 'passage' => 'walk', 'encounters' => 'forest', 'atlas' => 'vegetation'],
 * 'hills' => ['name' => 'Hills', 'layer' => 'terrain', 'glyph' => '<fg=green>n</>', 'art' => ['awaiting' => true],
 *   'passage' => 'walk', 'encounters' => 'hills', 'atlas' => 'highland'],
 * 'road' => ['name' => 'Road', 'layer' => 'terrain', 'glyph' => '<fg=#d2b48c>·</>',
 *   'art' => ['layer' => 'ground', 'tile' => 3008], 'passage' => 'walk', 'atlas' => 'road'],
 * ```
 *
 * A kind is placed through its {@see TilesetPiece}: one cell whose glyph goes
 * on the kind's gameplay layer and whose tiles are its autotile and, for a
 * feature, its ground kind's autotile. Painting a kind therefore writes both
 * presentations through the existing piece mechanism. A kind awaiting its
 * art (`'art' => ['awaiting' => true]`) draws no tiles at all, its ground's
 * and kept layers included, so its glyph shows in the graphical field until
 * its autotile is bound; its ground and keeps apply from then on. Passage and encounters
 * come from the kind ({@see \Ichiloto\Engine\Field\MapTerrainKinds}), so a
 * cell's collision and its art come from one authored decision.
 */
final readonly class TerrainKind
{
  public const array FIELDS = ['name', 'layer', 'glyph', 'art', 'passage', 'encounters', 'atlas', 'ground', 'keeps'];
  /** The art key that marks a kind whose autotile is still to be drawn: `'art' => ['awaiting' => true]`. */
  public const string AWAITING_ART = 'awaiting';
  /** Encounter terrain ids, shared by the kinds whose troops they share. */
  public const string ENCOUNTER_PATTERN = '/\A[a-z][a-z0-9_-]{0,63}\z/';
  private const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]*\z/';

  /** The plain glyph that names the kind in the terminal. */
  public string $symbol;
  /** The physical cell the kind resolves to on foot: solid, an encounter tile, or open ground. */
  public CollisionType $collision;
  /** Whether the kind's autotile is still to be drawn: it is placed with its glyph alone, and validation lists it. */
  public bool $awaitingArt;

  /**
   * @param string $glyph The authored glyph cell, in its style markup.
   * @param array{layer: string, tile: int}|null $art The autotile and the tile layer it is painted on; null while awaiting art.
   * @param string|null $encounters The encounter terrain whose troops it meets; null (absent) meets none.
   * @param string $atlas The atlas role it shows as on a map screen ({@see MapAtlasTerrain::ROLES}).
   * @param string|null $ground The kind a feature stands on, painted beneath it; null for a ground kind.
   * @param list<string> $keeps Tile layers whose existing tiles stay beneath it, such as a substrate.
   * @param TilesetPiece $piece The one-cell piece that places it.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $layer,
    public string $glyph,
    public ?array $art,
    public TerrainPassage $passage,
    public ?string $encounters,
    public string $atlas,
    public ?string $ground,
    public array $keeps,
    public TilesetPiece $piece,
  ) {
    $this->symbol = TerminalText::stripAnsi(TerminalText::visibleSymbols($glyph)[0] ?? '');
    $this->awaitingArt = $art === null;
    $this->collision = match (true) {
      $passage === TerrainPassage::SOLID => CollisionType::SOLID,
      $encounters !== null => CollisionType::ENCOUNTER,
      default => CollisionType::NONE,
    };
  }

  /**
   * Reads a tileset's `kinds`, keyed by kind id, refusing anything a map
   * could not be painted or played with.
   *
   * @param array<string, string> $sheets The tileset's sheets, by sheet name.
   * @param list<string> $pieceIds The tileset's authored piece ids, which kind ids must not repeat.
   * @return array<string, self> By kind id, in authored order.
   */
  public static function readAll(mixed $data, array $sheets, array $pieceIds, string $context): array
  {
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new InvalidArgumentException("{$context} kinds must be keyed by kind id.");
    }
    $definitions = [];
    foreach ($data as $id => $definition) {
      $id = (string)$id;
      $kindContext = "{$context} kind {$id}";
      if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
        throw new InvalidArgumentException("{$kindContext}: a kind id is lowercase letters, digits, hyphens or underscores.");
      }
      if (in_array($id, $pieceIds, true)) {
        throw new InvalidArgumentException("{$kindContext}: a piece already has this id; kinds and pieces are placed by id, so each id names one.");
      }
      $definitions[$id] = self::readDefinition($definition, $sheets, $kindContext);
    }
    $kinds = [];
    foreach ($definitions as $id => $definition) {
      $kinds[$id] = self::build($id, $definition, $definitions, "{$context} kind {$id}");
    }
    self::assertDistinguishable($kinds, $context);
    return $kinds;
  }

  /** @return array<string, mixed> The kind's own fields, read and checked. */
  private static function readDefinition(mixed $data, array $sheets, string $context): array
  {
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException("{$context} must be an array of " . implode(', ', self::FIELDS) . '.');
    }
    if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
      throw new InvalidArgumentException("{$context} needs a name.");
    }
    if (!is_string($data['layer'] ?? null) || preg_match(self::NAME_PATTERN, $data['layer']) !== 1) {
      throw new InvalidArgumentException("{$context} needs the name of the gameplay layer its glyph goes on.");
    }
    $glyph = $data['glyph'] ?? null;
    $symbols = is_string($glyph) ? TerminalText::visibleSymbols($glyph) : [];
    $plain = count($symbols) === 1 ? TerminalText::stripAnsi($symbols[0]) : '';
    if (count($symbols) !== 1 || TerminalText::displayWidth($symbols[0]) !== 1 || trim($plain) === ''
      || preg_match('/\p{Cc}/u', $plain) === 1) {
      throw new InvalidArgumentException("{$context} glyph must be one visible character, one terminal cell wide, in its colour.");
    }
    $cell = TerminalText::parseSourceCells($glyph)[0] ?? null;
    if ($cell === null || $cell['prefix'] === '') {
      throw new InvalidArgumentException("{$context} glyph needs its terminal colour, such as '<fg=green>{$plain}</>': painting the kind writes the glyph in it.");
    }
    $passage = TerrainPassage::tryFrom(is_string($data['passage'] ?? null) ? $data['passage'] : '')
      ?? throw new InvalidArgumentException("{$context} passage must be 'walk' or 'solid'. Passage by vehicle is not authored yet.");
    $encounters = $data['encounters'] ?? null;
    if ($encounters !== null && (!is_string($encounters) || preg_match(self::ENCOUNTER_PATTERN, $encounters) !== 1)) {
      throw new InvalidArgumentException("{$context} encounters must be an encounter terrain id, such as 'woodland', or absent for none.");
    }
    if ($passage === TerrainPassage::SOLID && $encounters !== null) {
      throw new InvalidArgumentException("{$context} is solid, so nobody meets encounters on it; remove its encounters.");
    }
    if (!in_array($data['atlas'] ?? null, MapAtlasTerrain::ROLES, true)) {
      throw new InvalidArgumentException("{$context} atlas must be one of " . implode(', ', MapAtlasTerrain::ROLES) . '.');
    }
    $art = $data['art'] ?? null;
    if ($art === [self::AWAITING_ART => true]) {
      $art = null;
    } elseif (is_array($art) && count($art) === 2 && is_string($art['layer'] ?? null)
      && preg_match(self::NAME_PATTERN, $art['layer']) === 1 && is_int($art['tile'] ?? null)) {
      if (!TileId::isValid($art['tile']) || !TileId::isAutotile($art['tile'])) {
        throw new InvalidArgumentException("{$context} art tile must be an autotile (A1 to A4), so its edges come from its neighbours; place a whole-cell object as a piece.");
      }
      if (!isset($sheets[TileId::getSheet($art['tile'])->value])) {
        throw new InvalidArgumentException(sprintf('%s art tile %d is on sheet %s, which this tileset does not have.', $context, $art['tile'],
          TileId::getSheet($art['tile'])->value));
      }
      $art = ['layer' => $art['layer'], 'tile' => TileId::getFlagId($art['tile'])];
    } else {
      throw new InvalidArgumentException("{$context} art must be ['layer' => tile layer, 'tile' => autotile], or ['" . self::AWAITING_ART
        . "' => true] while its autotile is still to be drawn.");
    }
    $ground = $data['ground'] ?? null;
    if (array_key_exists('ground', $data) && !is_string($ground)) {
      throw new InvalidArgumentException("{$context} ground must name the kind it stands on.");
    }
    $keeps = $data['keeps'] ?? [];
    if (!is_array($keeps) || !array_is_list($keeps)
      || array_filter($keeps, static fn(mixed $name): bool => !is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) !== []
      || count(array_unique($keeps)) !== count($keeps)) {
      throw new InvalidArgumentException("{$context} keeps must be a list of distinct tile layer names.");
    }
    if ($art !== null && in_array($art['layer'], $keeps, true)) {
      throw new InvalidArgumentException("{$context} keeps its own art layer {$art['layer']}; it paints that layer, so it cannot keep it.");
    }
    return ['name' => $data['name'], 'layer' => $data['layer'], 'glyph' => $glyph, 'art' => $art, 'passage' => $passage,
      'encounters' => $encounters, 'atlas' => $data['atlas'], 'ground' => $ground, 'keeps' => $keeps];
  }

  /** @param array<string, array<string, mixed>> $definitions */
  private static function build(string $id, array $definition, array $definitions, string $context): self
  {
    $tiles = [];
    $keeps = $definition['keeps'];
    if ($definition['art'] !== null) {
      $tiles[$definition['art']['layer']] = [(string)$definition['art']['tile']];
    }
    $groundId = $definition['ground'];
    if ($groundId !== null) {
      $ground = $definitions[$groundId] ?? throw new InvalidArgumentException("{$context} stands on {$groundId}, which is not one of this tileset's kinds.");
      if ($groundId === $id || $ground['ground'] !== null) {
        throw new InvalidArgumentException("{$context} must stand on a ground kind, one that stands on nothing itself.");
      }
      if ($ground['layer'] !== $definition['layer']) {
        throw new InvalidArgumentException("{$context} goes on the {$definition['layer']} layer but its ground {$groundId} goes on {$ground['layer']}; a feature and its ground share one gameplay layer.");
      }
      if ($ground['art'] !== null) {
        if ($definition['art'] !== null && $definition['art']['layer'] === $ground['art']['layer']) {
          throw new InvalidArgumentException("{$context} paints the {$ground['art']['layer']} layer that its ground {$groundId} paints; a feature is painted on a layer above its ground.");
        }
        $tiles[$ground['art']['layer']] = [(string)$ground['art']['tile']];
      }
      $keeps = array_values(array_unique([...$keeps, ...$ground['keeps']]));
    }
    if ($definition['art'] === null) {
      // Until its own art is drawn the kind draws nothing graphically, its ground included, and keeps nothing
      // beneath it: its glyph is its whole presentation, in the graphical field too.
      $tiles = $keeps = [];
    }
    $keeps = array_values(array_diff($keeps, array_keys($tiles)));
    $piece = TilesetPiece::fromArray($id, ['name' => $definition['name'], 'layer' => $definition['layer'], 'glyphs' => [$definition['glyph']],
      'tiles' => $tiles, 'keeps' => $keeps], $context);
    return new self($id, $definition['name'], $definition['layer'], $definition['glyph'], $definition['art'], $definition['passage'],
      $definition['encounters'], $definition['atlas'], $groundId, $keeps, $piece);
  }

  /**
   * Kinds that share a glyph on one layer differ only in look, so the
   * terminal, which names a kind by its glyph's shape, plays them alike; and
   * no two kinds draw the same autotile, so a tile names one kind.
   *
   * @param array<string, self> $kinds
   */
  private static function assertDistinguishable(array $kinds, string $context): void
  {
    $bySymbol = $byTile = [];
    foreach ($kinds as $kind) {
      foreach ($bySymbol[$kind->layer][$kind->symbol] ?? [] as $same) {
        if ($same->collision !== $kind->collision || $same->encounters !== $kind->encounters || $same->atlas !== $kind->atlas) {
          throw new InvalidArgumentException(sprintf("%s kinds %s and %s share the glyph %s on the %s layer but play differently. The terminal names a kind by its glyph's shape, never its colour, so give one a glyph of its own, or the same passage, encounters and atlas role.",
            $context, $same->id, $kind->id, $kind->symbol, $kind->layer));
        }
        if ($same->glyph === $kind->glyph && $same->art === $kind->art) {
          throw new InvalidArgumentException("{$context} kinds {$same->id} and {$kind->id} look the same in both presentations; give one its own glyph colour or autotile.");
        }
      }
      $bySymbol[$kind->layer][$kind->symbol][] = $kind;
      if ($kind->art !== null) {
        $key = TileId::getFlagId($kind->art['tile']);
        if (isset($byTile[$key])) {
          throw new InvalidArgumentException("{$context} kinds {$byTile[$key]} and {$kind->id} draw the same autotile {$key}; each kind draws its own.");
        }
        $byTile[$key] = $kind->id;
      }
    }
  }

  /** Whether a tile entry is this kind's own autotile, in any of its shapes. */
  public function isOwnTile(int $tile): bool
  {
    return $this->art !== null && $tile !== TileId::EMPTY && TileId::isAutotile($tile) && TileId::getFlagId($tile) === $this->art['tile'];
  }
}
